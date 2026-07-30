<?php

declare(strict_types=1);

namespace DockerCli\Command;

use DockerCli\Config\MissingConfigException;
use DockerCli\Config\SystemCompose;
use DockerCli\Service\TranslatorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConfigSeedCommand extends AbstractCommand
{
    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('config:seed');
        $this->setDescription($this->translator->trans('command.seed.description'));
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, $this->translator->trans('command.seed.yes_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $compose = new SystemCompose();

        try {
            $compose->assertInitialized();
        } catch (MissingConfigException) {
            $output->writeln('<error>' . $this->translator->trans('config.missing', [
                '%files%' => implode(', ', $compose->missingFiles()),
                '%directory%' => $compose->directory(),
            ]) . '</error>');

            return Command::FAILURE;
        }

        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion($this->translator->trans('command.seed.confirm') . ' ', false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('<comment>' . $this->translator->trans('command.seed.cancelled') . '</comment>');

                return Command::SUCCESS;
            }
        }

        $values = $this->readEnvFile($compose->envFile());
        $this->setDefaultIfEmpty($values, 'DOCKGE_ADMIN_USERNAME', 'admin');
        $this->setDefaultIfEmpty($values, 'DOCKGE_ADMIN_PASSWORD', $this->randomSecret());
        $this->setDefaultIfEmpty($values, 'MYSQL_ROOT_PASSWORD', $this->randomSecret());
        $this->setDefaultIfEmpty($values, 'MYSQL_PASSWORD', $this->randomSecret());
        $this->setDefaultIfEmpty($values, 'POSTGRES_PASSWORD', $this->randomSecret());
        $this->writeEnvFile($compose->envFile(), $values);

        $output->writeln('<info>' . $this->translator->trans('command.seed.completed', [
            '%file%' => $compose->envFile(),
            '%username%' => $values['DOCKGE_ADMIN_USERNAME'],
        ]) . '</info>');

        return Command::SUCCESS;
    }

    /** @return array<string, string> */
    private function readEnvFile(string $file): array
    {
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

    /** @param array<string, string> $values */
    private function writeEnvFile(string $file, array $values): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException(sprintf('Unable to read env file "%s".', $file));
        }

        $writtenKeys = [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key] = explode('=', $trimmed, 2);
            $key = trim($key);
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $lines[$index] = $key . '=' . $values[$key];
            $writtenKeys[$key] = true;
        }

        foreach ($this->envKeyOrder($values) as $key) {
            if (!isset($writtenKeys[$key])) {
                $lines[] = $key . '=' . $values[$key];
            }
        }

        file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @param array<string, string> $values
     * @return list<string>
     */
    private function envKeyOrder(array $values): array
    {
        $orderedKeys = [
            'APP_LOCALE',
            'BASE_HOST',
            'PROJECT_WEB_DNSDOCK_ALIAS',
            'HOST_UID',
            'HOST_GID',
            'SOURCE_IMAGE_REGISTRY',
            'SOURCE_IMAGE_NAMESPACE',
            'SOURCE_IMAGE_TAG',
            'SOURCE_IMAGE_DOCKER_BUILDKIT',
            'CLOUDFLARE_DNS_API_TOKEN',
            'ACME_EMAIL',
            'DOCKGE_ADMIN_USERNAME',
            'DOCKGE_ADMIN_PASSWORD',
            'MYSQL_ROOT_PASSWORD',
            'MYSQL_DATABASE',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
            'POSTGRES_DB',
            'POSTGRES_USER',
            'POSTGRES_PASSWORD',
        ];

        return array_values(array_unique(array_merge(
            array_values(array_filter($orderedKeys, static fn (string $key): bool => array_key_exists($key, $values))),
            array_keys($values)
        )));
    }

    /** @param array<string, string> $values */
    private function setDefaultIfEmpty(array &$values, string $key, string $default): void
    {
        if (($values[$key] ?? '') === '') {
            $values[$key] = $default;
        }
    }

    private function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
