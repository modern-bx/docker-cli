<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\SystemCompose;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function DockerCli\Util\join_path;

abstract class ImageCommand extends AbstractCommand
{
    /** @var list<array{name: string, context: string, service: string}> */
    private const IMAGES = [
        [
            'name' => 'php-fpm-8.2',
            'context' => 'resources/compose/system/config/php-fpm-8.2',
            'service' => 'php-fpm-8.2',
        ],
        [
            'name' => 'playwright',
            'context' => 'resources/compose/system/config/playwright',
            'service' => 'playwright',
        ],
    ];

    protected function configureImageOptions(): void
    {
        $this->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Тег образа. По умолчанию берется SOURCE_IMAGE_TAG, последний git-тег текущей ветки или default. Для имени vendor/namespace задайте SOURCE_IMAGE_NAMESPACE.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать docker-команды без выполнения.');
    }

    /** @return list<array{name: string, context: string, service: string}> */
    protected function images(): array
    {
        $root = $this->repositoryRoot();

        return array_map(
            static fn (array $image): array => [
                'name' => $image['name'],
                'context' => join_path($root, $image['context']),
                'service' => $image['service'],
            ],
            self::IMAGES
        );
    }

    protected function imageTag(InputInterface $input): string
    {
        $optionTag = $input->getOption('tag');
        if (is_string($optionTag) && $optionTag !== '') {
            return $this->normalizeTag($optionTag);
        }

        $envTag = $this->imageEnv()['SOURCE_IMAGE_TAG'] ?? null;
        if (is_string($envTag) && $envTag !== '') {
            return $this->normalizeTag($envTag);
        }

        return $this->latestGitTag() ?? 'default';
    }

    protected function localImageReference(string $name, string $tag): string
    {
        return sprintf('%s/%s:%s', $this->imageNamespace(), $this->imageName($name), $tag);
    }

    protected function remoteImageReference(string $name, string $tag): string
    {
        return sprintf('%s/%s/%s:%s', $this->imageRegistry(), $this->imageNamespace(), $this->imageName($name), $tag);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $extraEnv
     */
    protected function runDockerCommand(array $command, OutputInterface $output, bool $dryRun, array $extraEnv = []): int
    {
        $this->writeMessage($output, '<comment>' . implode(' ', array_map('escapeshellarg', $command)) . '</comment>');
        if ($dryRun) {
            return Command::SUCCESS;
        }

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $imageEnv = $this->imageEnv();
        $buildKit = $imageEnv['SOURCE_IMAGE_DOCKER_BUILDKIT'] ?? null;
        if (is_string($buildKit) && $buildKit !== '') {
            $env['DOCKER_BUILDKIT'] = $buildKit;
            $env['COMPOSE_DOCKER_CLI_BUILD'] = $buildKit;
        }
        $env = array_replace($env, $extraEnv);

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start docker process.');
        }

        return proc_close($process);
    }

    /** @return list<string> */
    protected function composeBuildCommand(string $service, bool $noCache = false): array
    {
        $command = [
            'docker',
            'compose',
            '--env-file',
            $this->composeEnvFile(),
            '--file',
            $this->composeFile(),
            'build',
        ];
        if ($noCache) {
            $command[] = '--no-cache';
        }
        $command[] = $service;

        return $command;
    }

    /** @return array<string, string> */
    protected function imageCommandEnvironment(string $tag): array
    {
        return [
            'SOURCE_IMAGE_REGISTRY' => $this->imageRegistry(),
            'SOURCE_IMAGE_NAMESPACE' => $this->imageNamespace(),
            'SOURCE_IMAGE_TAG' => $tag,
        ];
    }

    private function imageRegistry(): string
    {
        $registry = $this->imageEnv()['SOURCE_IMAGE_REGISTRY'] ?? 'ghcr.io';

        return trim((string) $registry, '/');
    }

    private function imageNamespace(): string
    {
        $namespace = trim((string) ($this->imageEnv()['SOURCE_IMAGE_NAMESPACE'] ?? ''), '/');
        if ($namespace === '') {
            throw new \RuntimeException('SOURCE_IMAGE_NAMESPACE is not set. Set the image vendor/namespace before building or publishing custom images.');
        }

        return $namespace;
    }

    private function imageName(string $serviceName): string
    {
        return 'docker-cli/' . $serviceName;
    }

    /** @return array<string, string> */
    private function imageEnv(): array
    {
        $env = $this->readEnvFile(join_path($this->repositoryRoot(), 'resources', 'compose', 'system', SystemCompose::ENV_FILE));
        $composeEnv = (new SystemCompose())->envFile();
        if (is_file($composeEnv)) {
            $env = array_replace($env, $this->readEnvFile($composeEnv));
        }

        return $env;
    }

    /** @return array<string, string> */
    private function readEnvFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $values;
    }

    private function latestGitTag(): ?string
    {
        $command = ['git', 'tag', '--merged', 'HEAD', '--sort=-v:refname'];
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->repositoryRoot());
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || !is_string($stdout)) {
            return null;
        }

        foreach (explode(PHP_EOL, $stdout) as $tag) {
            $tag = trim($tag);
            if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function normalizeTag(string $tag): string
    {
        $tag = trim($tag);
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $matches) === 1) {
            return $matches[1];
        }

        if ($tag === 'default') {
            return $tag;
        }

        throw new \InvalidArgumentException(sprintf('Invalid source image tag "%s". Use a version like 1.0.0 or default.', $tag));
    }

    private function composeFile(): string
    {
        return join_path($this->repositoryRoot(), 'resources', 'compose', 'system', SystemCompose::COMPOSE_FILE);
    }

    private function composeEnvFile(): string
    {
        $composeEnv = (new SystemCompose())->envFile();
        if (is_file($composeEnv)) {
            return $composeEnv;
        }

        return join_path($this->repositoryRoot(), 'resources', 'compose', 'system', SystemCompose::ENV_FILE);
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
