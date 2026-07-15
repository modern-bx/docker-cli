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
            'mysql',
            'sh',
            '-ec',
            'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysql -uroot -e "$1"',
            'sh',
            $mysqlSql,
        ]), $compose, $output);
        if ($code !== Command::SUCCESS) {
            return $code;
        }

        [$postgresRoleSql, $postgresDropRoleSql, $postgresTerminateSql, $postgresDatabaseExistsSql, $postgresGrantSql] = $this->postgresSql($projectName, $postgresPassword);
        return $this->run(array_merge($compose->dockerComposeCommand('exec'), [
            '-T',
            'postgres',
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

    private function mysqlSql(string $name, string $password, bool $rebuild): string
    {
        $identifier = str_replace('`', '``', $name);
        $user = str_replace("'", "''", $name);
        $password = str_replace("'", "''", $password);
        $drop = $rebuild ? "DROP USER IF EXISTS '{$user}'@'%'; DROP DATABASE IF EXISTS `{$identifier}`;" : '';

        return $drop . " CREATE DATABASE IF NOT EXISTS `{$identifier}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$password}'; GRANT ALL PRIVILEGES ON `{$identifier}`.* TO '{$user}'@'%'; FLUSH PRIVILEGES;";
    }

    /** @return array{string, string, string, string, string} */
    private function postgresSql(string $name, string $password): array
    {
        $literalName = str_replace("'", "''", $name);
        $literalPassword = str_replace("'", "''", $password);
        $quotedIdentifier = '"' . str_replace('"', '""', $name) . '"';

        return [
            "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$literalName}') THEN EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', '{$literalName}', '{$literalPassword}'); END IF; END $$;",
            "DROP ROLE IF EXISTS {$quotedIdentifier};",
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$literalName}' AND pid <> pg_backend_pid();",
            "SELECT 1 FROM pg_database WHERE datname = '{$literalName}'",
            "GRANT ALL PRIVILEGES ON DATABASE {$quotedIdentifier} TO {$quotedIdentifier};",
        ];
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
}
