<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Panel\ProjectsSettingsRepository;
use DockerCli\Project\ProjectNameGenerator;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class ProjectCloneCommand extends Command
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?ProjectsSettingsRepository $settings = null)
    {
        parent::__construct('project:clone');
        $this->setDescription('Полностью клонировать зарегистрированный проект.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Очистить существующий целевой проект перед клонированием.');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Кодовое имя исходного проекта.');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Кодовое имя или путь целевого проекта.');
        $this->addOption('location', null, InputOption::VALUE_REQUIRED, 'Код расположения проектов.');
        $this->addOption('here', null, InputOption::VALUE_NONE, 'Создать проект рядом с исходным.');
        $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Список glob-шаблонов через запятую.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('here') && $input->getOption('location') !== null) {
            $output->writeln('<error>Опции --here и --location нельзя использовать одновременно.</error>');
            return Command::FAILURE;
        }
        $registry = $this->registry ?? new ProjectRegistry();
        $from = $input->getOption('from');
        $from = is_string($from) && $from !== '' ? $from : $registry->projectNameFromContext();
        if ($from === null || !$registry->hasProject($from)) {
            $output->writeln('<error>Исходный проект не найден. Укажите его код через --from.</error>');
            return Command::FAILURE;
        }
        $sourceConfig = $registry->readProjectConfig($from);
        $sourceRoot = $sourceConfig['data']['project']['root'] ?? null;
        if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
            $output->writeln('<error>Директория исходного проекта не найдена.</error>');
            return Command::FAILURE;
        }

        $to = $input->getOption('to');
        $isPath = is_string($to) && (str_contains($to, '/') || str_contains($to, '\\'));
        $names = $registry->registeredProjectNames();
        $name = is_string($to) && $to !== '' && !$isPath ? $to : null;
        if ($name === null && $isPath) $name = $this->normalizeName(basename((string) $to));
        if ($name === null || $name === '' || in_array($name, $names, true) && $isPath) {
            $name = (new ProjectNameGenerator())->generate($names);
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
            $output->writeln('<error>Некорректное кодовое имя целевого проекта.</error>');
            return Command::FAILURE;
        }

        try {
            $destination = $isPath ? $this->absolutePath((string) $to) : $this->destinationForName($name, $sourceRoot, $input);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $existingName = $this->projectAtPath($destination, $registry);
        if (($existingName !== null || $registry->hasProject($name) || $this->directoryHasFiles($destination)) && !$input->getOption('force')) {
            $output->writeln('<error>Целевой проект или директория уже существует. Используйте --force для очистки.</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('force') && is_dir($destination)) $this->wipe($destination);
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            $output->writeln('<error>Не удалось создать целевую директорию.</error>');
            return Command::FAILURE;
        }

        $startedAt = new \DateTimeImmutable();
        $started = microtime(true);
        $output->writeln(sprintf(
            '<info>Клонирование проекта "%s" в "%s" началось (%s).</info>',
            $from,
            $name,
            $startedAt->format('H:i:s'),
        ));

        $config = $sourceConfig;
        $documentRoot = $config['data']['project']['document_root'] ?? $sourceRoot;
        $relativeDocumentRoot = is_string($documentRoot) && str_starts_with($documentRoot, rtrim($sourceRoot, '/') . '/')
            ? substr($documentRoot, strlen(rtrim($sourceRoot, '/')) + 1) : '';
        $config['data']['project']['name'] = $name;
        $config['data']['project']['root'] = $destination;
        $config['data']['project']['document_root'] = $relativeDocumentRoot === '' ? $destination : join_path($destination, $relativeDocumentRoot);
        if (!is_dir($registry->projectDirectory($name))) mkdir($registry->projectDirectory($name), 0775, true);
        $registry->writeProjectConfig($name, $config);
        $metadata = join_path($destination, '.docker-cli');
        if (!is_dir($metadata)) mkdir($metadata, 0775, true);
        file_put_contents(join_path($metadata, 'project.yaml'), Yaml::dump(['meta' => ['schema' => 'project-meta', 'version' => 0.1], 'data' => ['project' => ['name' => $name]]], 4, 2));

        $excludes = ['.docker-cli'];
        $rawExclude = $input->getOption('exclude');
        if (is_string($rawExclude)) foreach (explode(',', $rawExclude) as $pattern) if (trim($pattern) !== '') $excludes[] = ltrim(trim($pattern), './');
        $excludeArgs = implode(' ', array_map(static fn (string $pattern): string => '--exclude=' . escapeshellarg($pattern), $excludes));
        $command = sprintf('tar %s -cf - . | (cd %s && tar -xf -)', $excludeArgs, escapeshellarg($destination));
        $filesStarted = microtime(true);
        passthru('cd ' . escapeshellarg($sourceRoot) . ' && ' . $command, $status);
        $filesDuration = microtime(true) - $filesStarted;
        if ($status !== 0) {
            $output->writeln('<error>Копирование проекта завершилось с ошибкой.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            '<info>Проект "%s" клонирован в "%s" (%s; всего: %s; копирование файлов: %s).</info>',
            $name,
            $destination,
            (new \DateTimeImmutable())->format('H:i:s'),
            $this->formatDuration(microtime(true) - $started),
            $this->formatDuration($filesDuration),
        ));
        return Command::SUCCESS;
    }

    private function destinationForName(string $name, string $sourceRoot, InputInterface $input): string
    {
        if ($input->getOption('here')) return join_path(dirname($sourceRoot), $name);
        $locations = ($this->settings ?? new ProjectsSettingsRepository())->locations();
        $code = $input->getOption('location');
        foreach ($locations as $location) {
            if (($code !== null && $location['code'] === $code) || ($code === null && $location['default'])) return join_path($location['path'], $name);
        }
        throw new \InvalidArgumentException($code === null ? 'Не настроено расположение проектов по умолчанию.' : sprintf('Расположение "%s" не найдено.', $code));
    }

    private function absolutePath(string $path): string { return str_starts_with($path, '/') ? rtrim($path, '/') : join_path((string) getcwd(), $path); }
    private function normalizeName(string $name): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-'); }
    private function formatDuration(float $seconds): string { return number_format($seconds, 3, '.', '') . ' с'; }
    private function directoryHasFiles(string $path): bool { return is_dir($path) && count(scandir($path) ?: []) > 2; }
    private function projectAtPath(string $path, ProjectRegistry $registry): ?string
    {
        foreach ($registry->registeredProjectNames() as $name) if (($registry->readProjectConfig($name)['data']['project']['root'] ?? null) === $path) return $name;
        return null;
    }
    private function wipe(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.docker-cli') continue;
            $this->remove(join_path($path, $entry));
        }
    }
    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove(join_path($path, $entry));
        rmdir($path);
    }
}
