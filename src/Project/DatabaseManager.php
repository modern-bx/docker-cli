<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class DatabaseManager
{
    public const DBMS = ['mysql', 'postgres'];

    public function __construct(private readonly ?SystemCompose $compose = null) {}

    /** @param list<string> $dbms @param list<string> $users */
    public function createDatabases(array $dbms, string $database, array $users, OutputInterface $output): int
    {
        foreach ($dbms as $engine) {
            $sql = $engine === 'mysql'
                ? $this->mysqlCreateDatabaseSql($database, $users)
                : $this->postgresCreateDatabaseScript($database, $users);
            $code = $this->execute($engine, $sql, $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
            $output->writeln(sprintf('<info>База "%s" создана в %s.</info>', $database, $engine));
        }
        return Command::SUCCESS;
    }

    /** @param list<string> $dbms @param list<string> $databases */
    public function deleteDatabases(array $dbms, array $databases, OutputInterface $output): int
    {
        foreach ($dbms as $engine) {
            $code = $this->execute($engine, $engine === 'mysql'
                ? $this->mysqlDeleteDatabasesSql($databases)
                : $this->postgresDeleteDatabasesScript($databases), $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }
        return Command::SUCCESS;
    }

    /** @param list<string> $dbms @param list<string> $databases */
    public function createUser(array $dbms, string $user, array $databases, OutputInterface $output): int
    {
        // Validate every requested database before changing any DBMS.
        foreach ($dbms as $engine) {
            if ($databases === []) {
                continue;
            }
            $code = $this->execute($engine, $engine === 'mysql'
                ? $this->mysqlAssertDatabasesSql($databases)
                : $this->postgresAssertDatabasesScript($databases), $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }
        foreach ($dbms as $engine) {
            $code = $this->execute($engine, $engine === 'mysql'
                ? $this->mysqlCreateUserSql($user, $databases)
                : $this->postgresCreateUserScript($user, $databases), $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
            $output->writeln(sprintf('<info>Пользователь "%s" создан в %s.</info>', $user, $engine));
        }
        return Command::SUCCESS;
    }

    /** @param list<string> $dbms @param list<string> $users */
    public function deleteUsers(array $dbms, array $users, OutputInterface $output): int
    {
        foreach ($dbms as $engine) {
            $code = $this->execute($engine, $engine === 'mysql'
                ? $this->mysqlDeleteUsersSql($users)
                : $this->postgresDeleteUsersSql($users), $output);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }
        return Command::SUCCESS;
    }

    private function execute(string $dbms, string $payload, OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $compose->assertInitialized();
        $command = array_merge($compose->dockerComposeCommand('exec'), ['-T', $dbms, 'sh', '-ec', $dbms === 'mysql'
            ? 'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" mysql -uroot --show-warnings -e "$1"'
            : 'export PGPASSWORD="${POSTGRES_PASSWORD:?}"; sh -ec "$1"', 'sh', $payload]);
        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }
        return proc_close($process);
    }

    /** @param list<string> $users */
    private function mysqlCreateDatabaseSql(string $database, array $users): string
    {
        $sql = 'CREATE DATABASE IF NOT EXISTS ' . $this->mysqlIdentifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';
        foreach ($users as $user) {
            $sql .= ' CREATE USER IF NOT EXISTS ' . $this->mysqlUser($user) . '; GRANT ALL PRIVILEGES ON ' . $this->mysqlIdentifier($database) . '.* TO ' . $this->mysqlUser($user) . ';';
        }
        return $sql;
    }

    /** @param list<string> $databases */
    private function mysqlDeleteDatabasesSql(array $databases): string
    {
        $sql = '';
        foreach ($databases as $database) {
            $literal = $this->mysqlLiteral($database);
            $sql .= "SELECT IF(EXISTS(SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME={$literal}), '', 'Предупреждение: база {$this->plain($database)} не найдена в mysql.') AS message; DROP DATABASE IF EXISTS {$this->mysqlIdentifier($database)};";
        }
        return $sql;
    }

    /** @param list<string> $databases */
    private function mysqlAssertDatabasesSql(array $databases): string
    {
        return "SET @missing=(SELECT GROUP_CONCAT(requested.name) FROM (" . implode(' UNION ALL ', array_map(fn (string $database): string => 'SELECT ' . $this->mysqlLiteral($database) . ' AS name', $databases)) . ") requested LEFT JOIN information_schema.SCHEMATA s ON s.SCHEMA_NAME=requested.name WHERE s.SCHEMA_NAME IS NULL); SET @sql=IF(@missing IS NULL, 'SELECT 1', CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Не найдены базы: ', REPLACE(@missing, '''', ''''''), '''')); PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;";
    }

    /** @param list<string> $databases */
    private function mysqlCreateUserSql(string $user, array $databases): string
    {
        $sql = 'CREATE USER IF NOT EXISTS ' . $this->mysqlUser($user) . ';';
        foreach ($databases as $database) {
            $sql .= ' GRANT ALL PRIVILEGES ON ' . $this->mysqlIdentifier($database) . '.* TO ' . $this->mysqlUser($user) . ';';
        }
        return $sql;
    }

    /** @param list<string> $users */
    private function mysqlDeleteUsersSql(array $users): string
    {
        $sql = '';
        foreach ($users as $user) {
            $literal = $this->mysqlLiteral($user);
            $sql .= "SELECT IF(EXISTS(SELECT 1 FROM mysql.user WHERE User={$literal} AND Host='%'), '', 'Предупреждение: пользователь {$this->plain($user)} не найден в mysql.') AS message; DROP USER IF EXISTS {$this->mysqlUser($user)};";
        }
        return $sql;
    }

    /** @param list<string> $users */
    private function postgresCreateDatabaseScript(string $database, array $users): string
    {
        $root = '"${POSTGRES_USER:-system}"';
        $script = $this->postgresCreateUsersSql($users);
        $script = "root={$root}; psql -v ON_ERROR_STOP=1 -U \"\$root\" -d postgres -c " . escapeshellarg($script) . '; ';
        $script .= 'if ! psql -U "$root" -d postgres -Atqc ' . escapeshellarg('SELECT 1 FROM pg_database WHERE datname=' . $this->postgresLiteral($database)) . ' | grep -qx 1; then createdb -U "$root" ' . escapeshellarg($database) . '; fi; ';
        foreach ($users as $user) {
            $script .= 'psql -v ON_ERROR_STOP=1 -U "$root" -d postgres -c ' . escapeshellarg('GRANT ALL PRIVILEGES ON DATABASE ' . $this->postgresIdentifier($database) . ' TO ' . $this->postgresIdentifier($user)) . '; ';
        }
        return $script;
    }

    /** @param list<string> $databases */
    private function postgresDeleteDatabasesScript(array $databases): string
    {
        $script = 'root="${POSTGRES_USER:-system}"; ';
        foreach ($databases as $database) {
            $query = 'SELECT 1 FROM pg_database WHERE datname=' . $this->postgresLiteral($database);
            $script .= 'if psql -U "$root" -d postgres -Atqc ' . escapeshellarg($query) . ' | grep -qx 1; then psql -v ON_ERROR_STOP=1 -U "$root" -d postgres -c ' . escapeshellarg('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=' . $this->postgresLiteral($database) . ' AND pid <> pg_backend_pid()') . '; dropdb -U "$root" ' . escapeshellarg($database) . '; else echo ' . escapeshellarg('Предупреждение: база ' . $database . ' не найдена в postgres.') . '; fi; ';
        }
        return $script;
    }

    /** @param list<string> $databases */
    private function postgresAssertDatabasesScript(array $databases): string
    {
        $script = 'root="${POSTGRES_USER:-system}"; missing=""; ';
        foreach ($databases as $database) {
            $query = 'SELECT 1 FROM pg_database WHERE datname=' . $this->postgresLiteral($database);
            $script .= 'psql -U "$root" -d postgres -Atqc ' . escapeshellarg($query) . ' | grep -qx 1 || missing="$missing ' . str_replace(['"', '$', '`', '\\'], ['\\"', '\\$', '\\`', '\\\\'], $database) . '"; ';
        }
        return $script . '[ -z "$missing" ] || { echo "Не найдены базы:$missing" >&2; exit 1; }';
    }

    /** @param list<string> $databases */
    private function postgresCreateUserScript(string $user, array $databases): string
    {
        $script = 'root="${POSTGRES_USER:-system}"; psql -v ON_ERROR_STOP=1 -U "$root" -d postgres -c ' . escapeshellarg($this->postgresCreateUsersSql([$user])) . '; ';
        foreach ($databases as $database) {
            $script .= 'psql -v ON_ERROR_STOP=1 -U "$root" -d postgres -c ' . escapeshellarg('GRANT ALL PRIVILEGES ON DATABASE ' . $this->postgresIdentifier($database) . ' TO ' . $this->postgresIdentifier($user)) . '; ';
        }
        return $script;
    }

    /** @param list<string> $users */
    private function postgresDeleteUsersSql(array $users): string
    {
        $sql = '';
        foreach ($users as $user) {
            $sql .= "DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname={$this->postgresLiteral($user)}) THEN EXECUTE 'DROP ROLE ' || quote_ident({$this->postgresLiteral($user)}); ELSE RAISE WARNING 'Пользователь % не найден в postgres.', {$this->postgresLiteral($user)}; END IF; END $$;";
        }
        return 'root="${POSTGRES_USER:-system}"; psql -v ON_ERROR_STOP=1 -U "$root" -d postgres -c ' . escapeshellarg($sql);
    }

    /** @param list<string> $users */
    private function postgresCreateUsersSql(array $users): string
    {
        $sql = '';
        foreach ($users as $user) {
            $literal = $this->postgresLiteral($user);
            $sql .= "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname={$literal}) THEN EXECUTE 'CREATE ROLE ' || quote_ident({$literal}) || ' LOGIN'; END IF; END $$;";
        }
        return $sql === '' ? 'SELECT 1;' : $sql;
    }

    private function mysqlIdentifier(string $value): string { return '`' . str_replace('`', '``', $value) . '`'; }
    private function mysqlLiteral(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    private function mysqlUser(string $value): string { return $this->mysqlLiteral($value) . "@'%'"; }
    private function postgresIdentifier(string $value): string { return '"' . str_replace('"', '""', $value) . '"'; }
    private function postgresLiteral(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    private function plain(string $value): string { return str_replace(["'", "\n", "\r"], ['', ' ', ' '], $value); }
}
