<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function DockerCli\Util\join_path;

final class PlayRunCommand extends Command
{
    public function __construct()
    {
        parent::__construct('play:run');
        $this->setDescription('Запустить Playwright-сценарий в контексте текущего проекта.');
        $this->addArgument('script', InputArgument::REQUIRED, 'Путь к js-сценарию относительно ~/.config/docker-cli/playwright/scripts; расширение .js можно опустить.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new ProjectRegistry();
        $projectName = $registry->projectNameFromContext();
        if ($projectName === null) {
            $output->writeln('<error>Команда play:run выполняется только из директории зарегистрированного проекта.</error>');

            return Command::FAILURE;
        }

        $script = $this->normalizeScriptName((string) $input->getArgument('script'));
        if ($script === null) {
            $output->writeln('<error>Некорректное имя сценария: используйте относительный путь внутри playwright/scripts.</error>');

            return Command::FAILURE;
        }

        $compose = new SystemCompose();
        try {
            $compose->assertInitialized();
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));

            return Command::FAILURE;
        }

        $scriptPath = join_path($compose->playwrightScriptsDirectory(), $script);
        if (!is_file($scriptPath)) {
            $output->writeln(sprintf('<error>Сценарий "%s" не найден в "%s".</error>', $script, $compose->playwrightScriptsDirectory()));

            return Command::FAILURE;
        }

        $projectConfig = $registry->readProjectConfig($projectName);
        $projectRoot = $projectConfig['data']['project']['root'] ?? getcwd();
        $documentRoot = $projectConfig['data']['project']['document_root'] ?? '';
        $baseHost = $this->readEnvValue($compose->envFile(), 'BASE_HOST');
        $projectUrl = $baseHost === '' ? '' : sprintf('https://web-%s.%s', $projectName, $baseHost);

        $command = array_merge($compose->dockerComposeCommand('run'), [
            '--rm',
            '--no-deps',
            '--workdir',
            $this->containerPath(is_string($projectRoot) ? $projectRoot : (string) getcwd()),
            '-e',
            'PROJECT_NAME=' . $projectName,
            '-e',
            'PROJECT_ROOT=' . (is_string($projectRoot) ? $projectRoot : ''),
            '-e',
            'PROJECT_DOCUMENT_ROOT=' . (is_string($documentRoot) ? $documentRoot : ''),
            '-e',
            'PROJECT_URL=' . $projectUrl,
            'playwright',
            'sh',
            '-lc',
            'mkdir -p /docker-cli/playwright/runtime && cd /docker-cli/playwright/runtime && if [ ! -d node_modules/playwright ]; then npm --silent --no-update-notifier --no-fund install playwright@${PLAYWRIGHT_VERSION:-1.61.0}; fi && NODE_PATH=/docker-cli/playwright/runtime/node_modules node "$1"',
            'docker-cli-playwright',
            join_path('/docker-cli/playwright/scripts', $script),
        ]);

        $output->writeln('<comment>Выполняется: ' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            $output->writeln('<error>Не удалось запустить Docker Compose.</error>');

            return Command::FAILURE;
        }

        $exitCode = proc_close($process);

        return is_int($exitCode) ? $exitCode : Command::FAILURE;
    }

    private function normalizeScriptName(string $script): ?string
    {
        $script = trim(str_replace('\\', '/', $script), '/');
        if ($script === '' || str_contains($script, '..')) {
            return null;
        }

        if (!str_ends_with($script, '.js')) {
            $script .= '.js';
        }

        return $script;
    }

    private function containerPath(string $hostPath): string
    {
        $realPath = realpath($hostPath) ?: $hostPath;
        if (str_starts_with($realPath, '/home/')) {
            return $realPath;
        }

        return '/host' . $realPath;
    }

    private function readEnvValue(string $envFile, string $key): string
    {
        $contents = is_file($envFile) ? file_get_contents($envFile) : false;
        if ($contents === false) {
            return '';
        }

        foreach (explode(PHP_EOL, $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$envKey, $value] = explode('=', $line, 2);
            if (trim($envKey) === $key) {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return '';
    }
}
