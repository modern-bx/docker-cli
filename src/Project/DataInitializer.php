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
        $env = $this->readEnvValues($compose->envFile());

        $mysqlRootPassword = $env['MYSQL_ROOT_PASSWORD'] ?? '';
        if ($mysqlRootPassword === '') {
            $output->writeln('<error>MYSQL_ROOT_PASSWORD не задан в системном .env.</error>');
            return Command::FAILURE;
        }

        $postgresRootUser = $env['POSTGRES_USER'] ?? 'system';
        $postgresRootPassword = $env['POSTGRES_PASSWORD'] ?? '';
        if ($postgresRootPassword === '') {
            $output->writeln('<error>POSTGRES_PASSWORD не задан в системном .env.</error>');
            return Command::FAILURE;
        }

        $mysqlSql = $this->mysqlSql($projectName, $mysqlPassword, $rebuild);
        $code = $this->run(array_merge($compose->dockerComposeCommand('exec'), ['-T', 'mysql', 'mysql', '-uroot', '-p' . $mysqlRootPassword, '-e', $mysqlSql]), $compose, $output);
        if ($code !== Command::SUCCESS) {
            return $code;
        }

        [$postgresRoleSql, $postgresDatabaseSql, $postgresGrantSql] = $this->postgresSql($projectName, $postgresPassword, $rebuild);
        return $this->run(array_merge($compose->dockerComposeCommand('exec'), ['-T', 'postgres', 'psql', '-v', 'ON_ERROR_STOP=1', '-U', $postgresRootUser, '-d', 'postgres', '-c', $postgresRoleSql, '-c', $postgresDatabaseSql, '-c', $postgresGrantSql]), $compose, $output, ['PGPASSWORD' => $postgresRootPassword]);
    }

    private function mysqlSql(string $name, string $password, bool $rebuild): string
    {
        $identifier = str_replace('`', '``', $name);
        $user = str_replace("'", "''", $name);
        $password = str_replace("'", "''", $password);
        $drop = $rebuild ? "DROP USER IF EXISTS '{$user}'@'%'; DROP DATABASE IF EXISTS `{$identifier}`;" : '';

        return $drop . " CREATE DATABASE IF NOT EXISTS `{$identifier}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$password}'; GRANT ALL PRIVILEGES ON `{$identifier}`.* TO '{$user}'@'%'; FLUSH PRIVILEGES;";
    }

    /** @return array{string, string, string} */
    private function postgresSql(string $name, string $password, bool $rebuild): array
    {
        $literalName = str_replace("'", "''", $name);
        $literalPassword = str_replace("'", "''", $password);
        $rebuildSql = $rebuild ? "PERFORM pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$literalName}' AND pid <> pg_backend_pid(); EXECUTE format('DROP DATABASE IF EXISTS %I', '{$literalName}'); EXECUTE format('DROP ROLE IF EXISTS %I', '{$literalName}');" : '';

        $quotedIdentifier = '"' . str_replace('"', '""', $name) . '"';

        return [
            "DO $$ BEGIN {$rebuildSql} IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$literalName}') THEN EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', '{$literalName}', '{$literalPassword}'); END IF; END $$;",
            "SELECT format('CREATE DATABASE %I OWNER %I', '{$literalName}', '{$literalName}') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '{$literalName}') \\gexec",
            "GRANT ALL PRIVILEGES ON DATABASE {$quotedIdentifier} TO {$quotedIdentifier};",
        ];
    }

    /** @param array<string, string> $extraEnv */
    private function run(array $command, SystemCompose $compose, OutputInterface $output, array $extraEnv = []): int
    {
        $output->writeln('<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $extraEnv + $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker compose process.');
        }

        return proc_close($process);
    }

    /** @return array<string, string> */
    private function readEnvValues(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        $values = [];
        foreach (explode(PHP_EOL, $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $values;
    }
}
