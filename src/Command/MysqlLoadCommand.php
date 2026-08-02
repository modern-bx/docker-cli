<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Project\MysqlDumpLoader;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MysqlLoadCommand extends AbstractCommand
{
    public function __construct(private readonly ?ProjectRegistry $registry = null, private readonly ?MysqlDumpLoader $dumpLoader = null)
    {
        parent::__construct('mysql:load');
        $this->setDescription('Параллельно восстановить MySQL-базу проекта через myloader.');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Путь к директории, созданной mysql:dump.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Короткое имя директории бэкапа.');
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Код зарегистрированного проекта.');
        $this->addOption('threads', 'j', InputOption::VALUE_REQUIRED, 'Число параллельных потоков.', '4');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Подтвердить полную замену выбранной базы.');
        $this->addOption('skip-checks', null, InputOption::VALUE_NONE, 'Не проверять соответствие проекта и базы метаданным дампа.');
        $this->addOption('disable-redo-log', null, InputOption::VALUE_NONE, 'Ускорить загрузку, временно отключив InnoDB redo log для всего MySQL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry ?? new ProjectRegistry();
        $project = $input->getOption('project') ?: $registry->projectNameFromContext();
        if (!is_string($project) || !$registry->hasProject($project)) {
            $this->writeMessage($output, '<error>Укажите зарегистрированный проект через --project или запустите команду из проекта.</error>');
            return Command::FAILURE;
        }
        if ($registry->isProjectProtected($project)) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" защищен. Изменение его данных запрещено.</error>', $project));
            return Command::FAILURE;
        }
        if (!$input->getOption('force')) {
            $this->writeMessage($output, sprintf('<error>Загрузка полностью заменит MySQL-базу проекта "%s". Повторите с --force.</error>', $project));
            return Command::FAILURE;
        }
        $threads = filter_var($input->getOption('threads'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($threads === false) {
            $this->writeMessage($output, '<error>Опция --threads должна быть положительным целым числом.</error>');
            return Command::INVALID;
        }
        $database = $registry->readProjectConfig($project)['data']['databases']['mysql']['database'] ?? $project;
        if (!is_string($database) || $database === '') {
            $this->writeMessage($output, sprintf('<error>В конфигурации проекта "%s" не задана база MySQL.</error>', $project));
            return Command::FAILURE;
        }
        $name = $input->getOption('name');
        $path = $input->getOption('path');
        if (($name === null) === ($path === null)) {
            $this->writeMessage($output, '<error>Укажите ровно одну из опций --name или --path.</error>');
            return Command::INVALID;
        }
        if ($name !== null && (!is_string($name) || $name === '' || basename($name) !== $name)) {
            $this->writeMessage($output, '<error>Опция --name должна содержать короткое имя директории бэкапа.</error>');
            return Command::INVALID;
        }
        if ($path !== null && (!is_string($path) || $path === '')) {
            $this->writeMessage($output, '<error>Опция --path должна содержать путь к директории бэкапа.</error>');
            return Command::INVALID;
        }
        $path = realpath($path ?? sprintf('.docker-cli/backups/mysql/%s', $name));
        if ($path === false || !is_file($path . '/metadata')) {
            $this->writeMessage($output, '<error>Указанная директория не является дампом mydumper.</error>');
            return Command::FAILURE;
        }
        $metadata = json_decode((string) @file_get_contents($path . '/docker-cli.json'), true);
        if (!$input->getOption('skip-checks') && is_array($metadata) && (($metadata['project'] ?? null) !== $project || ($metadata['database'] ?? null) !== $database)) {
            $this->writeMessage($output, '<error>Дамп создан для другого проекта или базы данных.</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('skip-checks')) {
            $this->writeMessage($output, sprintf('<comment>Проверка проекта и базы дампа пропущена; данные будут загружены в "%s".</comment>', $database));
        }
        try {
            $code = ($this->dumpLoader ?? new MysqlDumpLoader())->load($database, $path, $threads, (bool) $input->getOption('disable-redo-log'), $output);
        } catch (MissingConfigException) {
            $this->writeMessage($output, '<error>Системная конфигурация не инициализирована.</error>');
            return Command::FAILURE;
        }
        if ($code === Command::SUCCESS) {
            CommandContext::fromEnvironment($this, $output)->addMessage(new Message(
                sprintf('База "%s" проекта "%s" восстановлена из бэкапа "%s".', $database, $project, basename($path)),
                MessageLevel::Info,
                notify: true,
            ));
        }
        return $code;
    }
}
