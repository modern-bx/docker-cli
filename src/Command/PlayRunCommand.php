<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class PlayRunCommand extends Command
{
    public function __construct()
    {
        parent::__construct('play:run');
        $this->setDescription('Запустить Playwright-сценарий в контексте текущего проекта.');
        $this->addArgument('script', InputArgument::REQUIRED, 'Путь к js-сценарию относительно ~/.config/docker-cli/playwright/scripts; расширение .js можно опустить.');
        $this->addOption('show', null, InputOption::VALUE_NONE, 'Показывать управляемый браузер в окне через локальный noVNC viewer.');
        $this->addOption('browser', null, InputOption::VALUE_REQUIRED, 'Браузер для выполнения сценария: chromium, firefox или webkit.');
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
        $projectRoot = is_string($projectRoot) ? $projectRoot : (string) getcwd();
        $documentRoot = $projectConfig['data']['project']['document_root'] ?? '';
        $baseHost = $this->readEnvValue($compose->envFile(), 'BASE_HOST');
        $projectUrl = $baseHost === '' ? '' : sprintf('https://web-%s.%s', $projectName, $baseHost);
        $hostLogDirectory = join_path($registry->projectDirectory($projectName), 'logs', 'playwright');
        $this->ensureDirectory($hostLogDirectory);
        $containerLogDirectory = $this->containerPath($hostLogDirectory);
        $mixinRequireArguments = $this->mixinRequireArguments($compose);

        $show = (bool) $input->getOption('show');
        $browser = $input->getOption('browser');
        if ($browser !== null && !in_array($browser, ['chromium', 'firefox', 'webkit'], true)) {
            $output->writeln('<error>Некорректный браузер. Допустимые значения: chromium, firefox, webkit.</error>');

            return Command::INVALID;
        }

        try {
            $contextFile = $this->writeContextFile(
                $hostLogDirectory,
                $projectRoot,
                $projectConfig,
                join_path($compose->playwrightDataDirectory(), substr($script, 0, -3)),
            );
        } catch (\JsonException|\RuntimeException $exception) {
            $output->writeln(sprintf('<error>Не удалось подготовить данные Playwright: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $viewerPort = 7900;

        $runArguments = [
            '--rm',
            '--no-deps',
        ];
        if ($browser !== null) {
            $runArguments = array_merge($runArguments, ['-e', 'PLAYWRIGHT_BROWSER=' . $browser]);
        }
        if ($show) {
            $runArguments = array_merge($runArguments, [
                '--publish',
                sprintf('127.0.0.1:%d:7900', $viewerPort),
                '-e',
                'PLAYWRIGHT_SHOW=1',
            ]);
        }

        $command = array_merge($compose->dockerComposeCommand('run'), $runArguments, [
            '--workdir',
            $this->containerPath($projectRoot),
            '-e',
            'PROJECT_NAME=' . $projectName,
            '-e',
            'PROJECT_ROOT=' . (is_string($projectRoot) ? $projectRoot : ''),
            '-e',
            'PROJECT_DOCUMENT_ROOT=' . (is_string($documentRoot) ? $documentRoot : ''),
            '-e',
            'PROJECT_URL=' . $projectUrl,
            '-e',
            'PLAYWRIGHT_LOG_DIR=' . $containerLogDirectory,
            '-e',
            'PLAYWRIGHT_SCRIPT_ID=' . $script,
            '-e',
            'PLAYWRIGHT_CONTEXT_FILE=' . $this->containerPath($contextFile),
            'playwright',
            'sh',
            '-lc',
            'mkdir -p /docker-cli/playwright/runtime && cd /docker-cli/playwright/runtime && if [ ! -d node_modules/playwright ]; then npm --silent --no-update-notifier --no-fund install playwright@${PLAYWRIGHT_VERSION:-1.61.0}; fi && NODE_PATH=/docker-cli/playwright/runtime/node_modules node "$@"',
            'docker-cli-playwright',
            ...$mixinRequireArguments,
            join_path('/docker-cli/playwright/scripts', $script),
        ]);

        $output->writeln('<comment>Выполняется: ' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $compose->dockerProcessEnvironment());
        if (!is_resource($process)) {
            unlink($contextFile);
            $output->writeln('<error>Не удалось запустить Docker Compose.</error>');

            return Command::FAILURE;
        }

        if ($show) {
            $viewerUrl = sprintf('http://127.0.0.1:%d/vnc.html?autoconnect=true&resize=scale', $viewerPort);
            $output->writeln(sprintf('<info>Окно браузера доступно по адресу: %s</info>', $viewerUrl));
            $this->openViewerWhenReady($viewerUrl, $viewerPort);
        }

        $exitCode = proc_close($process);
        unlink($contextFile);
        $output->writeln(sprintf('<info>Playwright logs directory: %s</info>', $hostLogDirectory));

        return is_int($exitCode) ? $exitCode : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function writeContextFile(string $directory, string $projectRoot, array $projectConfig, string $defaultDataDirectory): string
    {
        $context = ['project' => $projectConfig];

        foreach ($this->readDataDirectory($defaultDataDirectory) as $name => $value) {
            if ($name === 'project') {
                throw new \RuntimeException('Имя объекта "project" зарезервировано.');
            }
            $context[$name] = $value;
        }

        foreach ($this->readDataDirectory(join_path($projectRoot, '.docker-cli', 'data')) as $name => $value) {
            if ($name === 'project') {
                throw new \RuntimeException('Имя объекта "project" зарезервировано.');
            }
            $context[$name] = $value;
        }

        $contextFile = tempnam($directory, '.context-');
        if ($contextFile === false) {
            throw new \RuntimeException(sprintf('Не удалось создать временный файл в "%s".', $directory));
        }

        try {
            $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($contextFile, $json) === false) {
                throw new \RuntimeException(sprintf('Не удалось записать временный файл "%s".', $contextFile));
            }
        } catch (\Throwable $exception) {
            unlink($contextFile);
            throw $exception;
        }

        return $contextFile;
    }

    /** @return array<string, mixed> */
    private function readDataDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $data = [];
        $files = glob(join_path($directory, '*.{json,yaml,yml}'), GLOB_BRACE) ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) !== 1) {
                throw new \RuntimeException(sprintf('Имя файла "%s" не является допустимым JavaScript-идентификатором.', basename($file)));
            }
            if (array_key_exists($name, $data)) {
                throw new \RuntimeException(sprintf('Имя объекта "%s" используется более одного раза.', $name));
            }

            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new \RuntimeException(sprintf('Не удалось прочитать файл "%s".', $file));
                }
                $data[$name] = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $data[$name] = Yaml::parseFile($file);
            }
        }

        return $data;
    }

    private function openViewerWhenReady(string $url, int $port): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $opener = 'open';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            return;
        } else {
            $opener = 'xdg-open';
        }

        $script = sprintf(
            'command -v %1$s >/dev/null 2>&1 || exit 0; while ! (echo >/dev/tcp/127.0.0.1/%2$d) >/dev/null 2>&1; do sleep 1; done; %1$s %3$s >/dev/null 2>&1',
            escapeshellarg($opener),
            $port,
            escapeshellarg($url),
        );
        $viewerProcess = proc_open(['bash', '-c', $script . ' &'], [], $pipes);
        if (is_resource($viewerProcess)) {
            proc_close($viewerProcess);
        }
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

    /** @return list<string> */
    private function mixinRequireArguments(SystemCompose $compose): array
    {
        $mixinsDirectory = join_path($compose->playwrightScriptsDirectory(), 'mixins');
        if (!is_dir($mixinsDirectory)) {
            return [];
        }

        $files = glob(join_path($mixinsDirectory, '*.js')) ?: [];
        sort($files, SORT_STRING);

        $arguments = [];
        foreach ($files as $file) {
            $arguments[] = '--require';
            $arguments[] = join_path('/docker-cli/playwright/scripts/mixins', basename($file));
        }

        return $arguments;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
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
