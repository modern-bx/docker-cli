<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class DataInitializer
{
    public function __construct(private readonly ?SystemCompose $compose = null) {}

    public function initialize(string $projectName, string $mysqlPassword, string $postgresPassword, bool $rebuild, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();
        $mysqlSql = $this->mysqlSql($projectName, $mysqlPassword, $rebuild);
        $code = $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            $this->service($projectName, 'mysql'),
            'sh',
            '-ec',
            <<<'SH'
set -eu
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}"
attempt=0
until mysqladmin --protocol=socket -uroot ping --silent >/dev/null 2>&1; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "MySQL did not become ready within 60 seconds." >&2
    exit 1
  fi
  sleep 1
done
mysql -uroot -e "$1"
SH,
            'sh',
            $mysqlSql,
        ]), $compose, $output);
        if ($code !== Command::SUCCESS) {
            return $code;
        }

        [$postgresRoleSql, $postgresDropRoleSql, $postgresTerminateSql, $postgresDatabaseExistsSql, $postgresGrantSql] = $this->postgresSql($projectName, $postgresPassword);
        return $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            $this->service($projectName, 'postgres'),
            'sh',
            '-ec',
            <<<'SH'
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
root_user="${POSTGRES_USER:-system}"
database="$1"
rebuild="$2"
role_sql="$3"
drop_role_sql="$4"
terminate_sql="$5"
database_exists_sql="$6"
grant_sql="$7"

attempt=0
until pg_isready -q -U "$root_user" -d postgres; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 60 ]; then
    echo "PostgreSQL did not become ready within 60 seconds." >&2
    exit 1
  fi
  sleep 1
done

if [ "$rebuild" = "1" ]; then
  psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$terminate_sql"
  dropdb -U "$root_user" --if-exists "$database"
  psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$drop_role_sql"
fi

psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$role_sql"
if [ "$(psql -v ON_ERROR_STOP=1 -At -U "$root_user" -d postgres -c "$database_exists_sql")" != "1" ]; then
  createdb -U "$root_user" -O "$database" "$database"
fi
psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$grant_sql"
SH,
            'sh',
            $projectName,
            $rebuild ? '1' : '0',
            $postgresRoleSql,
            $postgresDropRoleSql,
            $postgresTerminateSql,
            $postgresDatabaseExistsSql,
            $postgresGrantSql,
        ]), $compose, $output);
    }

    public function drop(string $projectName, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        $code = $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            $this->service($projectName, 'mysql'),
            'sh',
            '-ec',
            'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysql -uroot -e "$1"',
            'sh',
            $this->mysqlDropSql($projectName),
        ]), $compose, $output);
        if ($code !== Command::SUCCESS) {
            return $code;
        }

        [$postgresTerminateSql, $postgresRoleCleanupSql] = $this->postgresDropSql($projectName);
        return $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            $this->service($projectName, 'postgres'),
            'sh',
            '-ec',
            <<<'SH'
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
root_user="${POSTGRES_USER:-system}"
database="$1"
terminate_sql="$2"
role_cleanup_sql="$3"

psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$terminate_sql"
dropdb -U "$root_user" --if-exists "$database"
psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$role_cleanup_sql"
SH,
            'sh',
            $projectName,
            $postgresTerminateSql,
            $postgresRoleCleanupSql,
        ]), $compose, $output);
    }

    private function service(string $projectName, string $driver): string
    {
        $registry = new ProjectRegistry();
        if (!$registry->hasProject($projectName)) {
            return $driver;
        }
        $hostname = $registry->readProjectConfig($projectName)['data']['databases'][$driver]['hostname'] ?? null;

        return $hostname === sprintf('docker-cli-%s-%s', $driver, $projectName)
            ? ($this->compose ?? new SystemCompose())->databaseService($projectName, $driver)
            : $driver;
    }

    public function clonePostgres(string $sourceDatabase, string $targetDatabase, string $sourceUser, string $targetUser, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();
        $sourceIdentifier = '"' . str_replace('"', '""', $sourceDatabase) . '"';
        $targetIdentifier = '"' . str_replace('"', '""', $targetDatabase) . '"';
        $sourceLiteral = str_replace("'", "''", $sourceDatabase);
        $targetLiteral = str_replace("'", "''", $targetDatabase);
        $sourceUserIdentifier = '"' . str_replace('"', '""', $sourceUser) . '"';
        $targetUserIdentifier = '"' . str_replace('"', '""', $targetUser) . '"';

        return $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T', 'postgres', 'sh', '-ec', <<<'SH'
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
root_user="${POSTGRES_USER:-system}"
enable_source="$1"
psql_cmd() { psql -v ON_ERROR_STOP=1 -U "$root_user" -d postgres -c "$1"; }
trap 'psql_cmd "$enable_source" >/dev/null 2>&1 || true' EXIT
psql_cmd "$2"
psql_cmd 'SELECT pg_reload_conf();'
psql_cmd "$3"
psql_cmd "$4"
psql_cmd "$5"
psql_cmd "$6"
psql_cmd "$7"
psql_cmd "$enable_source"
trap - EXIT
psql_cmd "$8"
psql -v ON_ERROR_STOP=1 -U "$root_user" -d "$9" -c "${10}"
SH,
            'sh',
            "ALTER DATABASE {$sourceIdentifier} WITH ALLOW_CONNECTIONS true;",
            "ALTER SYSTEM SET file_copy_method = 'clone';",
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$targetLiteral}' AND pid <> pg_backend_pid();",
            "DROP DATABASE IF EXISTS {$targetIdentifier};",
            "ALTER DATABASE {$sourceIdentifier} WITH ALLOW_CONNECTIONS false;",
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$sourceLiteral}' AND pid <> pg_backend_pid();",
            "CREATE DATABASE {$targetIdentifier} WITH TEMPLATE {$sourceIdentifier} STRATEGY = FILE_COPY;",
            "ALTER DATABASE {$targetIdentifier} OWNER TO {$targetUserIdentifier}; GRANT ALL PRIVILEGES ON DATABASE {$targetIdentifier} TO {$targetUserIdentifier};",
            $targetDatabase,
            "REASSIGN OWNED BY {$sourceUserIdentifier} TO {$targetUserIdentifier}; ALTER DATABASE {$sourceIdentifier} OWNER TO {$sourceUserIdentifier};",
        ]), $compose, $output);
    }

    public function wipe(string $mysqlDatabase, string $postgresDatabase, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        $code = $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'mysql',
            'sh',
            '-ec',
            <<<'SH'
set -eu
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}"
database="$1"
drop_tables_query=$(cat <<'SQL'
SELECT CONCAT('DROP TABLE IF EXISTS `', REPLACE(TABLE_NAME, '`', '``'), '`;')
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';
SQL
)

{
  echo 'SET FOREIGN_KEY_CHECKS=0;'
  mysql -uroot -N -B "$database" -e "$drop_tables_query"
  echo 'SET FOREIGN_KEY_CHECKS=1;'
} | mysql -uroot "$database"
SH,
            'sh',
            $mysqlDatabase,
        ]), $compose, $output);
        if ($code !== Command::SUCCESS) {
            return $code;
        }

        return $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'postgres',
            'sh',
            '-ec',
            <<<'SH'
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
psql -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-system}" -d "$1" <<'SQL'
DO $$
DECLARE
  current_table record;
BEGIN
  FOR current_table IN
    SELECT schemaname, tablename
    FROM pg_tables
    WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
  LOOP
    EXECUTE format('DROP TABLE IF EXISTS %I.%I CASCADE', current_table.schemaname, current_table.tablename);
  END LOOP;
END
$$;
SQL
SH,
            'sh',
            $postgresDatabase,
        ]), $compose, $output);
    }

    public function dump(string $dbms, string $database, string $outputFile, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        $directory = dirname($outputFile);
        if ($directory !== '.' && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Не удалось создать директорию "%s".</error>', $directory));
            return Command::FAILURE;
        }

        return match ($dbms) {
            'mysql' => $this->dumpMysql($compose, $database, $outputFile, $output),
            'postgres' => $this->dumpPostgres($compose, $database, $outputFile, $output),
            default => Command::FAILURE,
        };
    }

    /** @param list<string> $files */
    public function apply(string $dbms, string $database, array $files, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();

        foreach ($files as $file) {
            $output->writeln(sprintf('<info>Применяется SQL-файл "%s".</info>', $file));
            $code = match ($dbms) {
                'mysql' => $this->applyMysqlFile($compose, $database, $file, $output),
                'postgres' => $this->applyPostgresFile($compose, $database, $file, $output),
                default => Command::FAILURE,
            };

            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }

        return Command::SUCCESS;
    }

    private function mysqlSql(string $name, string $password, bool $rebuild): string
    {
        $identifier = str_replace('`', '``', $name);
        $user = str_replace("'", "''", $name);
        $password = str_replace("'", "''", $password);
        $drop = $rebuild ? "DROP USER IF EXISTS '{$user}'@'%'; DROP DATABASE IF EXISTS `{$identifier}`;" : '';

        return $drop . " CREATE DATABASE IF NOT EXISTS `{$identifier}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$password}'; ALTER USER '{$user}'@'%' IDENTIFIED BY '{$password}'; GRANT ALL PRIVILEGES ON `{$identifier}`.* TO '{$user}'@'%'; FLUSH PRIVILEGES;";
    }

    private function mysqlDropSql(string $name): string
    {
        $identifier = str_replace('`', '``', $name);
        $user = str_replace("'", "''", $name);

        return "DROP USER IF EXISTS '{$user}'@'%'; DROP DATABASE IF EXISTS `{$identifier}`;";
    }

    /** @return array{string, string, string, string, string} */
    private function postgresSql(string $name, string $password): array
    {
        $literalName = str_replace("'", "''", $name);
        $literalPassword = str_replace("'", "''", $password);
        $quotedIdentifier = '"' . str_replace('"', '""', $name) . '"';

        return [
            "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$literalName}') THEN EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', '{$literalName}', '{$literalPassword}'); ELSE EXECUTE format('ALTER ROLE %I LOGIN PASSWORD %L', '{$literalName}', '{$literalPassword}'); END IF; END $$;",
            "DROP ROLE IF EXISTS {$quotedIdentifier};",
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$literalName}' AND pid <> pg_backend_pid();",
            "SELECT 1 FROM pg_database WHERE datname = '{$literalName}'",
            "GRANT ALL PRIVILEGES ON DATABASE {$quotedIdentifier} TO {$quotedIdentifier};",
        ];
    }

    /** @return array{string, string} */
    private function postgresDropSql(string $name): array
    {
        $literalName = str_replace("'", "''", $name);
        $roleCleanupSql = str_replace('<role>', $literalName, <<<'SQL'
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '<role>') THEN
    EXECUTE format('REASSIGN OWNED BY %I TO %I', '<role>', current_user);
    EXECUTE format('DROP OWNED BY %I', '<role>');
    EXECUTE format('DROP ROLE %I', '<role>');
  END IF;
END
$$;
SQL);

        return [
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$literalName}' AND pid <> pg_backend_pid();",
            $roleCleanupSql,
        ];
    }

    private function dumpMysql(SystemCompose $compose, string $database, string $outputFile, OutputInterface $output): int
    {
        return $this->runWithOutput(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'mysql',
            'sh',
            '-ec',
            'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysqldump --single-transaction --routines --triggers "$1"',
            'sh',
            $database,
        ]), $compose, $outputFile, $output);
    }

    private function dumpPostgres(SystemCompose $compose, string $database, string $outputFile, OutputInterface $output): int
    {
        return $this->runWithOutput(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'postgres',
            'sh',
            '-ec',
            'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; pg_dump -U "${POSTGRES_USER:-system}" -d "$1"',
            'sh',
            $database,
        ]), $compose, $outputFile, $output);
    }

    private function applyMysqlFile(SystemCompose $compose, string $database, string $file, OutputInterface $output): int
    {
        return $this->runWithInput(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'mysql',
            'sh',
            '-ec',
            'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysql "$1"',
            'sh',
            $database,
        ]), $compose, $file, $output);
    }

    private function applyPostgresFile(SystemCompose $compose, string $database, string $file, OutputInterface $output): int
    {
        return $this->runWithInput(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'postgres',
            'sh',
            '-ec',
            'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; psql -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-system}" -d "$1"',
            'sh',
            $database,
        ]), $compose, $file, $output);
    }

    private function run(array $command, SystemCompose $compose, OutputInterface $output): int
    {
        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        return proc_close($process);
    }

    private function runWithInput(array $command, SystemCompose $compose, string $inputFile, OutputInterface $output): int
    {
        $input = fopen($inputFile, 'rb');
        if ($input === false) {
            $output->writeln(sprintf('<error>Не удалось открыть файл "%s".</error>', $inputFile));
            return Command::FAILURE;
        }

        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . ' < ' . escapeshellarg($inputFile) . '</comment>');
        $process = proc_open($command, [$input, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            fclose($input);
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        $code = proc_close($process);
        fclose($input);

        return $code;
    }

    private function runWithOutput(array $command, SystemCompose $compose, string $outputFile, OutputInterface $output): int
    {
        $file = fopen($outputFile, 'wb');
        if ($file === false) {
            $output->writeln(sprintf('<error>Не удалось открыть файл "%s" для записи.</error>', $outputFile));
            return Command::FAILURE;
        }

        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . ' > ' . escapeshellarg($outputFile) . '</comment>');
        $process = proc_open($command, [STDIN, $file, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            fclose($file);
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        $code = proc_close($process);
        fclose($file);

        return $code;
    }

}
