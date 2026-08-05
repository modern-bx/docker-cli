<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Hook\CommandHookRunner;
use DockerCli\Project\ConfigurableServicesRestarter;
use DockerCli\Project\OpenRestyHostRenderer;
use DockerCli\Project\PhpLanguageVersion;
use DockerCli\Project\ProjectRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use function DockerCli\Util\join_path;

final class ProjectUpdateCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?ProjectRegistry $registry = null,
        private readonly ?CommandContext $context = null,
        private readonly ?CommandHookRunner $hookRunner = null,
    ) {
        parent::__construct('project:update');
        $this->setDescription('Изменить зарегистрированный проект.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Новое имя проекта.');
        $this->addOption('language', null, InputOption::VALUE_REQUIRED, 'Код языка проекта.');
        $this->addOption('language-version', null, InputOption::VALUE_REQUIRED, 'Версия языка проекта: 8.2, 8.3, 8.4 или 8.5.');
        $this->addOption('framework', null, InputOption::VALUE_REQUIRED, 'Код фреймворка проекта.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('name');
        $language = $input->getOption('language');
        $languageVersion = $input->getOption('language-version');
        $framework = $input->getOption('framework');
        if ($name === null && $language === null && $languageVersion === null && $framework === null) {
            $this->writeMessage($output, '<comment>Не указаны изменения: используйте --name, --language, --language-version или --framework.</comment>');
            return Command::SUCCESS;
        }
        if ($name !== null && (!is_string($name) || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1)) {
            $this->writeMessage($output, '<error>Имя проекта должно содержать строчные латинские буквы, цифры и дефисы.</error>');
            return Command::FAILURE;
        }
        if ($language !== null && $language !== 'php' || $framework !== null && !in_array($framework, ['', 'symfony', 'laravel', 'bitrix', 'bitrix24'], true)) {
            $this->writeMessage($output, '<error>Указан неподдерживаемый язык или фреймворк.</error>');
            return Command::FAILURE;
        }
        if ($languageVersion !== null && !PhpLanguageVersion::isSupported($languageVersion)) {
            $this->writeMessage($output, '<error>Версия PHP должна быть одной из: 8.2, 8.3, 8.4, 8.5.</error>');
            return Command::FAILURE;
        }

        $registry = $this->registry ?? new ProjectRegistry();
        $oldName = $registry->projectNameFromContext();
        if ($oldName === null || !$registry->hasProject($oldName)) {
            $this->writeMessage($output, '<error>Запустите команду в директории зарегистрированного проекта.</error>');
            return Command::FAILURE;
        }
        $newName = is_string($name) ? $name : $oldName;
        if ($newName !== $oldName && ($registry->hasProject($newName) || file_exists($registry->projectDirectory($newName)))) {
            $this->writeMessage($output, sprintf('<error>Проект "%s" уже зарегистрирован.</error>', $newName));
            return Command::FAILURE;
        }

        $config = $registry->readProjectConfig($oldName);
        $project = $config['data']['project'] ?? null;
        $root = is_array($project) ? ($project['root'] ?? null) : null;
        $localFile = is_string($root) ? join_path($root, '.docker-cli', 'project.yaml') : '';
        if (!is_array($project) || !is_string($root) || $root === '' || !is_file($localFile)) {
            $this->writeMessage($output, '<error>Конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }
        $localConfig = Yaml::parseFile($localFile);
        if (!is_array($localConfig) || !is_array($localConfig['data']['project'] ?? null)) {
            $this->writeMessage($output, '<error>Локальная конфигурация проекта повреждена.</error>');
            return Command::FAILURE;
        }

        $hookArguments = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];
        $beforeHookCode = ($this->hookRunner ?? new CommandHookRunner())->run('project:update', 'before', $hookArguments);
        if ($beforeHookCode !== Command::SUCCESS) {
            return $beforeHookCode;
        }

        $originalConfig = $config;
        $routingChanged = $newName !== $oldName || ($language !== null && $language !== ($project['language'] ?? null))
            || ($languageVersion !== null && $languageVersion !== ($project['language_version'] ?? PhpLanguageVersion::default()))
            || ($framework !== null && ($framework !== '' ? $framework : null) !== ($project['framework'] ?? null));
        $config['data']['project']['name'] = $newName;
        $localConfig['data']['project']['name'] = $newName;
        if (is_string($language)) $config['data']['project']['language'] = $language;
        if (is_string($languageVersion)) $config['data']['project']['language_version'] = $languageVersion;
        if (is_string($framework)) $config['data']['project']['framework'] = $framework !== '' ? $framework : null;
        $oldDirectory = $registry->projectDirectory($oldName);
        $newDirectory = $registry->projectDirectory($newName);
        $registry->writeProjectConfig($oldName, $config);
        file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        if ($newName !== $oldName && !rename($oldDirectory, $newDirectory)) {
            $localConfig['data']['project']['name'] = $oldName;
            $registry->writeProjectConfig($oldName, $originalConfig);
            file_put_contents($localFile, Yaml::dump($localConfig, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
            $this->writeMessage($output, '<error>Не удалось переименовать директорию проекта в реестре.</error>');
            return Command::FAILURE;
        }

        if ($routingChanged) {
            (new OpenRestyHostRenderer())->render();
            $restartCode = (new ConfigurableServicesRestarter())->restart($output);
            if ($restartCode !== Command::SUCCESS) return $restartCode;
        }
        ($this->context ?? CommandContext::fromEnvironment($this, $output))->addMessage(
            new Message(sprintf('Проект **%s** изменен.', $newName), notify: true),
        );
        return ($this->hookRunner ?? new CommandHookRunner())->run('project:update', 'after', $hookArguments);
    }
}
