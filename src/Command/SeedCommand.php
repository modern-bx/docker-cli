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

final class SeedCommand extends Command
{
    private TranslatorInterface $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator ?? TranslatorFactory::create();
        parent::__construct('seed');
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
        $values['DOCKGE_ADMIN_USERNAME'] = 'admin';
        $values['DOCKGE_ADMIN_PASSWORD'] = $this->randomSecret();
        $values['MYSQL_ROOT_PASSWORD'] = $this->randomSecret();
        $values['MYSQL_PASSWORD'] = $this->randomSecret();
        $values['POSTGRES_PASSWORD'] = $this->randomSecret();
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
        $orderedKeys = [
            'APP_LOCALE',
            'BASE_HOST',
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

        $lines = [];
        foreach ($orderedKeys as $key) {
            if (array_key_exists($key, $values)) {
                $lines[] = $key . '=' . $values[$key];
                unset($values[$key]);
            }
        }

        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
