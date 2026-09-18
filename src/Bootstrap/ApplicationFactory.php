<?php

declare(strict_types=1);

namespace DockerCli\Bootstrap;

use DockerCli\Command\BitrixGetInstallerCommand;
use DockerCli\Command\ConfigInitCommand;
use DockerCli\Command\ConfigSeedCommand;
use DockerCli\Command\DataApplyCommand;
use DockerCli\Command\DataDatabaseCreateCommand;
use DockerCli\Command\DataDatabaseDeleteCommand;
use DockerCli\Command\DataDbuserCreateCommand;
use DockerCli\Command\DataDbuserDeleteCommand;
use DockerCli\Command\DataDropCommand;
use DockerCli\Command\DataDumpCommand;
use DockerCli\Command\DataInitCommand;
use DockerCli\Command\DataWipeCommand;
use DockerCli\Command\ImageBuildCommand;
use DockerCli\Command\ImagePublishCommand;
use DockerCli\Command\MysqlBackupDeleteCommand;
use DockerCli\Command\MysqlDumpCommand;
use DockerCli\Command\MysqlLoadCommand;
use DockerCli\Command\MysqlRecoverCommand;
use DockerCli\Command\PanelDownCommand;
use DockerCli\Command\PanelPasswordRotateCommand;
use DockerCli\Command\PanelTokenRevokeCommand;
use DockerCli\Command\PanelUpCommand;
use DockerCli\Command\PanelUserCreateCommand;
use DockerCli\Command\PanelUserDeleteCommand;
use DockerCli\Command\PlayRunCommand;
use DockerCli\Command\PostgresBackupDeleteCommand;
use DockerCli\Command\PostgresDumpCommand;
use DockerCli\Command\PostgresLoadCommand;
use DockerCli\Command\PostgresRecoverCommand;
use DockerCli\Command\ProjectCloneCommand;
use DockerCli\Command\ProjectConfigGetCommand;
use DockerCli\Command\ProjectConfigSetCommand;
use DockerCli\Command\ProjectDisableCommand;
use DockerCli\Command\ProjectDownCommand;
use DockerCli\Command\ProjectEnableCommand;
use DockerCli\Command\ProjectListCommand;
use DockerCli\Command\ProjectShowCommand;
use DockerCli\Command\ProjectUpCommand;
use DockerCli\Command\ProjectUpdateCommand;
use DockerCli\Command\ProjectWipeCommand;
use DockerCli\Command\QueueItemCreateCommand;
use DockerCli\Command\QueueItemDeleteCommand;
use DockerCli\Command\QueueListCommand;
use DockerCli\Command\QueuePauseCommand;
use DockerCli\Command\QueueResumeCommand;
use DockerCli\Command\QueueStartCommand;
use DockerCli\Command\QueueStepCommand;
use DockerCli\Command\QueueStopCommand;
use DockerCli\Command\ShellBashCommand;
use DockerCli\Command\ShellRunCommand;
use DockerCli\Command\SystemReloadCommand;
use DockerCli\Command\SystemRestartCommand;
use DockerCli\Command\SystemRollbackCommand;
use DockerCli\Command\SystemSelfUpdateCommand;
use DockerCli\Command\SystemSetupCommand;
use DockerCli\Command\SystemStartCommand;
use DockerCli\Command\SystemStopCommand;
use DockerCli\Command\TaskListCommand;
use DockerCli\Command\TaskRunCommand;
use DockerCli\Command\TreeBackupDeleteCommand;
use DockerCli\Command\TreeDumpCommand;
use DockerCli\Command\TreeLoadCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

final class ApplicationFactory {
    public const APPLICATION_NAME = "docker-cli";
    public const APPLICATION_VERSION = "0.1.0";

    /** @param list<Command> $commands */
    public function __construct(private readonly array $commands) {}

    public static function createDefault(): self {
        return new self([
            new BitrixGetInstallerCommand(),
            new ConfigInitCommand(),
            new ConfigSeedCommand(),
            new DataApplyCommand(),
            new DataDatabaseCreateCommand(),
            new DataDatabaseDeleteCommand(),
            new DataDbuserCreateCommand(),
            new DataDbuserDeleteCommand(),
            new DataDropCommand(),
            new DataDumpCommand(),
            new DataInitCommand(),
            new DataWipeCommand(),
            new ImageBuildCommand(),
            new ImagePublishCommand(),
            new MysqlBackupDeleteCommand(),
            new MysqlDumpCommand(),
            new MysqlLoadCommand(),
            new MysqlRecoverCommand(),
            new PanelDownCommand(),
            new PanelPasswordRotateCommand(),
            new PanelTokenRevokeCommand(),
            new PanelUpCommand(),
            new PanelUserCreateCommand(),
            new PanelUserDeleteCommand(),
            new PlayRunCommand(),
            new PostgresBackupDeleteCommand(),
            new PostgresDumpCommand(),
            new PostgresLoadCommand(),
            new PostgresRecoverCommand(),
            new ProjectCloneCommand(),
            new ProjectConfigGetCommand(),
            new ProjectConfigSetCommand(),
            new ProjectDisableCommand(),
            new ProjectDownCommand(),
            new ProjectEnableCommand(),
            new ProjectListCommand(),
            new ProjectShowCommand(),
            new ProjectUpdateCommand(),
            new ProjectUpCommand(),
            new ProjectWipeCommand(),
            new QueueItemCreateCommand(),
            new QueueItemDeleteCommand(),
            new QueueListCommand(),
            new QueuePauseCommand(),
            new QueueResumeCommand(),
            new QueueStartCommand(),
            new QueueStepCommand(),
            new QueueStopCommand(),
            new ShellBashCommand(),
            new ShellRunCommand(),
            new SystemReloadCommand(),
            new SystemRestartCommand(),
            new SystemRollbackCommand(),
            new SystemSelfUpdateCommand(),
            new SystemSetupCommand(),
            new SystemStartCommand(),
            new SystemStopCommand(),
            new TaskListCommand(),
            new TaskRunCommand(),
            new TreeBackupDeleteCommand(),
            new TreeDumpCommand(),
            new TreeLoadCommand(),
        ]);
    }

    public function create(): Application {
        $application = new Application(self::APPLICATION_NAME, self::APPLICATION_VERSION);
        $application->addCommands($this->commands);
        $application->setDefaultCommand("shell:run");

        return $application;
    }
}
