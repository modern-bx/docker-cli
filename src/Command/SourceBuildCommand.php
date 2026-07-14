<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SourceBuildCommand extends SourceImageCommand
{
    public function __construct()
    {
        parent::__construct('src:build');
        $this->setDescription('Собрать кастомные docker-cli образы из исходников.');
        $this->configureSourceImageOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $this->imageTag($input);
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($this->sourceImages() as $image) {
            $code = $this->runDockerCommand([
                'docker',
                'build',
                '--tag',
                $this->localImageReference($image['name'], $tag),
                '--tag',
                $this->remoteImageReference($image['name'], $tag),
                $image['context'],
            ], $output, $dryRun);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }

        return Command::SUCCESS;
    }
}
