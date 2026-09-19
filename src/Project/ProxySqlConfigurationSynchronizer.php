<?php

declare(strict_types=1);

namespace DockerCli\Project;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class ProxySqlConfigurationSynchronizer
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?SystemCompose $compose = null,
    ) {
    }

    public function synchronize(OutputInterface $output): int
    {
        $compose = $this->compose ?? new SystemCompose();
        $input = tmpfile();
        if ($input === false) {
            throw new \RuntimeException("Не удалось подготовить конфигурацию ProxySQL.");
        }
        $this->write($input, $this->sql());
        rewind($input);

        $command = array_merge(
            $compose->dockerComposeCommand("exec"),
            [
                "--no-TTY",
                "proxysql",
                "/bin/sh",
                "-ec",
                'MYSQL_PWD="$PROXYSQL_ADMIN_PASSWORD" exec mysql --protocol=tcp --host=127.0.0.1 ' .
                    '--port=6032 --user="$PROXYSQL_ADMIN_USER"',
            ],
        );

        $process = proc_open(
            $command,
            [$input, STDOUT, STDERR],
            $pipes,
            null,
            $compose->dockerProcessEnvironment(),
        );
        if (!is_resource($process)) {
            fclose($input);
            throw new \RuntimeException("Не удалось запустить синхронизацию ProxySQL.");
        }
        $exitCode = proc_close($process);
        fclose($input);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    public function sql(): string
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $servers = ["mysql" => ["docker-cli-mysql" => 0], "postgres" => ["docker-cli-postgres" => 0]];
        $routes = ["mysql" => [], "postgres" => []];
        foreach ($registry->registeredProjectNames() as $projectName) {
            $databases = $registry->readProjectConfig($projectName)["data"]["databases"] ?? [];
            foreach (["mysql", "postgres"] as $driver) {
                $database = $databases[$driver] ?? null;
                if (!is_array($database)) {
                    continue;
                }
                $hostname = $database["hostname"] ?? null;
                $username = $database["username"] ?? null;
                $name = $database["database"] ?? null;
                $password = $database["password"] ?? null;
                if (!is_string($hostname) || !is_string($username) || !is_string($name) || !is_string($password)) {
                    continue;
                }
                $servers[$driver][$hostname] ??= $this->hostgroup($driver, $hostname);
                $routes[$driver][$username . "\0" . $name] = [
                    $username,
                    $name,
                    $password,
                    $servers[$driver][$hostname],
                ];
            }
        }

        return "BEGIN;\n"
            . $this->driverSql("mysql", $servers["mysql"], $routes["mysql"])
            . $this->driverSql("pgsql", $servers["postgres"], $routes["postgres"])
            . "COMMIT;\n"
            . "SET mysql-monitor_enabled='false';\n"
            . "LOAD MYSQL VARIABLES TO RUNTIME; SAVE MYSQL VARIABLES TO DISK;\n"
            . "SET pgsql-monitor_enabled='false';\n"
            . "LOAD PGSQL VARIABLES TO RUNTIME; SAVE PGSQL VARIABLES TO DISK;\n"
            . "LOAD MYSQL SERVERS TO RUNTIME; SAVE MYSQL SERVERS TO DISK;\n"
            . "LOAD MYSQL USERS TO RUNTIME; SAVE MYSQL USERS TO DISK;\n"
            . "LOAD MYSQL QUERY RULES TO RUNTIME; SAVE MYSQL QUERY RULES TO DISK;\n"
            . "LOAD PGSQL SERVERS TO RUNTIME; SAVE PGSQL SERVERS TO DISK;\n"
            . "LOAD PGSQL USERS TO RUNTIME; SAVE PGSQL USERS TO DISK;\n"
            . "LOAD PGSQL QUERY RULES TO RUNTIME; SAVE PGSQL QUERY RULES TO DISK;\n";
    }

    /** @param array<string, int> $servers @param array<string, array{string,string,string,int}> $routes */
    private function driverSql(string $prefix, array $servers, array $routes): string
    {
        $port = $prefix === "mysql" ? 3306 : 5432;
        $databaseColumn = $prefix === "mysql" ? "schemaname" : "database";
        $sql = "DELETE FROM {$prefix}_servers;\nDELETE FROM {$prefix}_users;\nDELETE FROM {$prefix}_query_rules;\n";
        foreach ($servers as $hostname => $hostgroup) {
            $sql .= sprintf(
                "INSERT INTO %s_servers (hostgroup_id, hostname, port, comment) VALUES (%d, '%s', %d, 'docker-cli');\n",
                $prefix,
                $hostgroup,
                $this->escape($hostname),
                $port,
            );
        }
        $ruleId = 1;
        $insertedUsers = [];
        foreach ($routes as [$username, $database, $password, $hostgroup]) {
            if (!isset($insertedUsers[$username])) {
                $sql .= sprintf(
                    "INSERT INTO %s_users (username, password, default_hostgroup, comment) " .
                        "VALUES ('%s', '%s', %d, 'docker-cli');\n",
                    $prefix,
                    $this->escape($username),
                    $this->escape($password),
                    $hostgroup,
                );
                $insertedUsers[$username] = true;
            }
            $sql .= sprintf(
                "INSERT INTO %s_query_rules " .
                    "(rule_id, active, username, %s, destination_hostgroup, apply, comment) " .
                    "VALUES (%d, 1, '%s', '%s', %d, 1, 'docker-cli');\n",
                $prefix,
                $databaseColumn,
                $ruleId++,
                $this->escape($username),
                $this->escape($database),
                $hostgroup,
            );
        }
        return $sql;
    }

    private function hostgroup(string $driver, string $hostname): int
    {
        return 1000 + (int) (sprintf("%u", crc32($driver . "\0" . $hostname)) % 100000000);
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /** @param resource $stream */
    private function write($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                fclose($stream);
                throw new \RuntimeException("Не удалось подготовить конфигурацию ProxySQL.");
            }
            $offset += $written;
        }
    }
}
