<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Notification\NotificationRepository;
use DockerCli\Panel\AssetController;
use DockerCli\Panel\AuthController;
use DockerCli\Panel\Http\ControllerInvoker;
use DockerCli\Panel\Http\Middleware\AuthMiddleware;
use DockerCli\Panel\Http\ResponseEmitter;
use DockerCli\Panel\JwtTokenService;
use DockerCli\Panel\NotificationController;
use DockerCli\Panel\ProjectController;
use DockerCli\Panel\ProjectsSettingsRepository;
use DockerCli\Panel\QueueController;
use DockerCli\Panel\Router;
use DockerCli\Panel\StateController;
use DockerCli\Panel\SystemController;
use DockerCli\Panel\SystemdService;
use DockerCli\Panel\UserRepository;
use DockerCli\Panel\WebSocket\PanelStateChannel;
use DockerCli\Project\ProjectRegistry;
use DockerCli\Queue\QueueRepository;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelUpCommand extends Command
{
    public function __construct(private readonly SystemdService $systemdService = new SystemdService())
    {
        parent::__construct('panel:up');
        $this->setDescription('Запустить HTTP-сервер административной панели.');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Порт HTTP-сервера (переопределяет PANEL_PORT из .env).');
        $this->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Создать и запустить systemd-сервис панели.');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Пользователь, от имени которого будет работать systemd-сервис.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Явный путь к бинарнику для systemd-сервиса.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('daemon')) {
            $rawPort = $input->getOption('port');
            if ($rawPort !== null && !$this->isValidPort($rawPort)) {
                $output->writeln('<error>Порт должен быть целым числом от 1 до 65535.</error>');
                return Command::INVALID;
            }

            return $this->installSystemdService($input, $output, $rawPort === null ? null : (int) $rawPort);
        }
        if ($input->getOption('user') !== null || $input->getOption('path') !== null) {
            $output->writeln('<error>Опции --user и --path можно использовать только вместе с -d.</error>');
            return Command::INVALID;
        }

        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $rawPort = $input->getOption('port') ?? $compose->envValue('PANEL_PORT', '8181');
        if (!$this->isValidPort($rawPort)) {
            $output->writeln('<error>Порт должен быть целым числом от 1 до 65535.</error>');
            return Command::INVALID;
        }
        $port = (int) $rawPort;

        $salt = $compose->envValue('PANEL_PASSWORD_SALT');
        $jwtSecret = $compose->envValue('PANEL_JWT_SECRET');
        if ($salt === '' || $jwtSecret === '') {
            $output->writeln('<error>Секреты панели не настроены. Выполните `docker-cli config:init`.</error>');
            return Command::FAILURE;
        }

        $lockPath = dirname($compose->directory()) . DIRECTORY_SEPARATOR . 'panel.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>Административная панель уже запущена другим бинарником.</error>');
            return Command::FAILURE;
        }

        $this->configureGateway($compose, $port);
        try {
            $socket = new SocketServer('0.0.0.0:' . $port);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>Не удалось запустить HTTP-сервер: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $assets = dirname(__DIR__, 2) . '/resources/panel/dist';
        $users = new UserRepository($salt);
        $tokenRepository = new \DockerCli\Panel\TokenRepository();
        $securitySettings = new \DockerCli\Panel\SecuritySettingsRepository();
        $projectsSettings = new \DockerCli\Panel\ProjectsSettingsRepository();
        $tokens = new JwtTokenService($jwtSecret, $tokenRepository, $securitySettings);
        $queues = new QueueRepository();
        $projects = new ProjectController(new ProjectRegistry(), $compose, $queues, new ProjectsSettingsRepository());
        $system = new SystemController($compose);
        $queue = new QueueController($queues);
        $notifications = new NotificationController(new NotificationRepository());
        $responses = new ResponseEmitter($assets);
        $state = new StateController($projects, $system, $queue, $notifications);
        $router = new Router(
            [new AuthController($users, $tokens, $tokenRepository), new \DockerCli\Panel\SecuritySettingsController($securitySettings), new \DockerCli\Panel\ProjectsSettingsController($projectsSettings), new \DockerCli\Panel\UsersSettingsController($users, $tokenRepository, new \DockerCli\Panel\PanelPasswordGenerator()), $state, $projects, $system, $queue, $notifications, new AssetController()],
            new ControllerInvoker(),
            new AuthMiddleware($tokens, $responses),
            $responses,
        );
        $channel = new PanelStateChannel($state, $tokens, $responses, 'https://panel.' . $compose->envValue('BASE_HOST', ''));
        $server = new HttpServer(static fn ($request) => $channel->handles($request) ? $channel->upgrade($request) : $router($request));
        $server->listen($socket);
        $output->writeln(sprintf('<info>Панель запущена на https://panel.%s</info>', $compose->envValue('BASE_HOST', '')));
        Loop::run();

        return Command::SUCCESS;
    }

    private function installSystemdService(InputInterface $input, OutputInterface $output, ?int $port): int
    {
        $rawUser = $input->getOption('user');
        if ($rawUser !== null && (!is_string($rawUser) || preg_match('/^[a-zA-Z0-9_.@-]+$/D', $rawUser) !== 1)) {
            $output->writeln('<error>Некорректное имя пользователя для systemd-сервиса.</error>');
            return Command::INVALID;
        }

        $rawPath = $input->getOption('path');
        $binary = $this->resolveBinary(is_string($rawPath) ? $rawPath : (string) ($_SERVER['argv'][0] ?? 'docker-cli'));
        if ($rawPath !== null && (!str_starts_with($binary, DIRECTORY_SEPARATOR) || !is_file($binary) || !is_executable($binary))) {
            $output->writeln('<error>Опция --path должна указывать на существующий исполняемый файл.</error>');
            return Command::INVALID;
        }
        try {
            $this->systemdService->install($binary, $port, is_string($rawUser) ? $rawUser : null);
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Создан systemd-сервис %s.</info>', SystemdService::NAME));
        $output->writeln(sprintf('<info>Конфигурация записана в %s.</info>', SystemdService::UNIT_PATH));
        $output->writeln(sprintf('<info>Сервис запускает: %s panel:up</info>', $binary));
        if (is_string($rawUser)) {
            $output->writeln(sprintf('<info>Сервис работает от пользователя: %s</info>', $rawUser));
        }
        $output->writeln(sprintf('<info>Сервис включён и запущен. Управление: systemctl {status|restart|stop} %s</info>', SystemdService::NAME));

        return Command::SUCCESS;
    }

    private function isValidPort(mixed $port): bool
    {
        return is_string($port) && ctype_digit($port) && (int) $port >= 1 && (int) $port <= 65535;
    }

    private function resolveBinary(string $binary): string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return realpath($binary) ?: $binary;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return $binary;
    }

    private function configureGateway(SystemCompose $compose, int $port): void
    {
        $file = $compose->directory() . '/config/panel/upstream.conf';
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException(sprintf('Unable to create panel config directory "%s".', dirname($file)));
        }
        // A hostname in a variable proxy_pass is resolved by nginx's configured
        // DNS resolver, which does not consult Docker's extra_hosts entries.
        // Keep the hostname in an upstream instead: nginx then resolves it via
        // libc on start/reload and honours host.docker.internal from /etc/hosts.
        $contents = sprintf(
            "upstream panel_backend {\n    server host.docker.internal:%d;\n}\n",
            $port
        );
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write panel gateway config "%s".', $file));
        }

        // The gateway might not be running yet; it will read the file on its next start.
        $process = proc_open(['docker', 'exec', 'docker-cli-panel-gateway', 'nginx', '-s', 'reload'], [STDIN, ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }
}
