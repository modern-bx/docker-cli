<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\DataInitializer;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DataApplyCommand extends Command
{
    private const DBMS = ['mysql', 'postgres'];

    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?DataInitializer $initializer = null,
    ) {
        parent::__construct('data:apply');
        $this->setDescription('Выполнить SQL-файлы в БД проекта.');
        $this->addOption('dbms', null, InputOption::VALUE_REQUIRED, 'СУБД проекта: mysql или postgres.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addArgument('path', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'SQL-файл, директория или glob-выражение. Можно указать несколько путей.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbms = $input->getOption('dbms');
        if (!is_string($dbms) || !in_array($dbms, self::DBMS, true)) {
            $output->writeln('<error>Укажите поддерживаемую СУБД через --dbms: mysql или postgres.</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        $projectName = $this->resolveProjectName($input, $registry);
        if ($projectName === null) {
            $output->writeln('<error>Укажите код зарегистрированного проекта через --project или запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }

        if (!$registry->hasProject($projectName)) {
            $output->writeln(sprintf('<error>Проект "%s" не зарегистрирован.</error>', $projectName));
            return Command::FAILURE;
        }

        $paths = $input->getArgument('path');
        if (!is_array($paths)) {
            $paths = [];
        }

        $files = $this->resolveSqlFiles($paths, $output);
        if ($files === []) {
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($projectName);
        $database = $config['data']['databases'][$dbms]['database'] ?? $projectName;
        if (!is_string($database) || $database === '') {
            $output->writeln(sprintf('<error>В конфигурации проекта "%s" не задана БД для %s.</error>', $projectName, $dbms));
            return Command::FAILURE;
        }

        try {
            $code = ($this->initializer ?? new DataInitializer())->apply($dbms, $database, $files, $output);
        } catch (MissingConfigException $exception) {
            $output->writeln(sprintf('<error>Системная конфигурация не инициализирована. Отсутствуют файлы: %s.</error>', implode(', ', $exception->missingFiles())));
            return Command::FAILURE;
        }

        if ($code === Command::SUCCESS) {
            $output->writeln(sprintf('<info>SQL-файлы применены к БД "%s" проекта "%s".</info>', $database, $projectName));
        }

        return $code;
    }

    private function resolveProjectName(InputInterface $input, ProjectRegistry $registry): ?string
    {
        $projectName = $input->getOption('project');
        if (is_string($projectName) && $projectName !== '') {
            return $projectName;
        }

        return $registry->projectNameFromContext();
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolveSqlFiles(array $paths, OutputInterface $output): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            if (is_file($path)) {
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'sql') {
                    $output->writeln(sprintf('<error>Файл "%s" не является sql-файлом.</error>', $path));
                    return [];
                }
                $files[] = $this->normalizePath($path);
                continue;
            }

            if (is_dir($path)) {
                $matches = glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
                sort($matches, SORT_STRING);
                foreach ($matches as $file) {
                    if (is_file($file)) {
                        $files[] = $this->normalizePath($file);
                    }
                }
                continue;
            }

            $matches = array_values(array_filter(glob($path) ?: [], static fn (string $file): bool => is_file($file)));
            if ($matches === []) {
                $output->writeln(sprintf('<error>Путь "%s" не найден.</error>', $path));
                return [];
            }

            sort($matches, SORT_STRING);
            foreach ($matches as $file) {
                $files[] = $this->normalizePath($file);
            }
        }

        return array_values(array_unique($files));
    }

    private function normalizePath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
