<?php

declare(strict_types=1);

namespace DockerCli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ImagePublishCommand extends ImageCommand
{
    public function __construct()
    {
        parent::__construct('image:publish');
        $this->setDescription('Опубликовать кастомные docker-cli образы в registry.');
        $this->configureImageOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $this->imageTag($input);
        $dryRun = (bool) $input->getOption('dry-run');

        foreach ($this->images() as $image) {
            $code = $this->runDockerCommand([
                'docker',
                'tag',
                $this->localImageReference($image['name'], $tag),
                $this->remoteImageReference($image['name'], $tag),
            ], $output, $dryRun);
            if ($code !== Command::SUCCESS) {
                return $code;
            }

            $code = $this->runDockerCommand([
                'docker',
                'push',
                $this->remoteImageReference($image['name'], $tag),
            ], $output, $dryRun);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }

        return Command::SUCCESS;
    }
}
