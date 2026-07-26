<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Panel\HttpResponse;
use DockerCli\Panel\JwtTokenService;
use DockerCli\Panel\UserRepository;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PanelUpCommand extends Command
{
    public function __construct()
    {
        parent::__construct('panel:up');
        $this->setDescription('Запустить HTTP-сервер административной панели.');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Порт HTTP-сервера (переопределяет PANEL_PORT из .env).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $rawPort = $input->getOption('port') ?? $compose->envValue('PANEL_PORT', '8181');
        if (!is_string($rawPort) || !ctype_digit($rawPort) || (int) $rawPort < 1 || (int) $rawPort > 65535) {
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
        $server = new HttpServer(new HttpResponse(new UserRepository($salt), new JwtTokenService($jwtSecret), $assets));
        $server->listen($socket);
        $output->writeln(sprintf('<info>Панель запущена на https://panel.%s</info>', $compose->envValue('BASE_HOST', '')));
        Loop::run();

        return Command::SUCCESS;
    }

    private function configureGateway(SystemCompose $compose, int $port): void
    {
        $file = $compose->directory() . '/config/panel/upstream.conf';
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException(sprintf('Unable to create panel config directory "%s".', dirname($file)));
        }
        $contents = sprintf("set \$panel_upstream http://host.docker.internal:%d;\n", $port);
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
