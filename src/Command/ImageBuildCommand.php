<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImageBuildCommand extends ImageCommand
{
    public function __construct()
    {
        parent::__construct('image:build');
        $this->setDescription('Собрать кастомные docker-cli образы из исходников.');
        $this->configureImageOptions();
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'Собрать образ без использования кеша Docker.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $this->imageTag($input);
        $dryRun = (bool) $input->getOption('dry-run');
        $noCache = (bool) $input->getOption('no-cache');

        foreach ($this->images() as $image) {
            $code = $this->runDockerCommand(
                $this->composeBuildCommand($image['service'], $noCache),
                $output,
                $dryRun,
                $this->imageCommandEnvironment($tag),
            );
            if ($code !== Command::SUCCESS) {
                return $code;
            }

            $code = $this->runDockerCommand([
                'docker',
                'tag',
                $this->remoteImageReference($image['name'], $tag),
                $this->localImageReference($image['name'], $tag),
            ], $output, $dryRun);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }

        return Command::SUCCESS;
    }
}
