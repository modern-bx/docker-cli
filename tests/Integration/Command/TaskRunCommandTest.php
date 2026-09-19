<?php

declare(strict_types=1);

namespace DockerCli\Tests\Integration\Command;

use DockerCli\Command\TaskRunCommand;
use DockerCli\Task\TaskRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskRunCommandTest extends TestCase
{
    public function testAcceptsProjectInitTagWithColon(): void
    {
        $repository = new TaskRepository(dirname(__DIR__, 3) . "/resources/tasks/core");
        $command = new TaskRunCommand($repository);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(["task-code" => "core.project.init.bitrix"]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Обязательный параметр "edition" не передан.', $tester->getDisplay());
        self::assertStringNotContainsString("Некорректный тег задачи", $tester->getDisplay());
        self::assertSame(
            ['Обязательный параметр "edition" не передан.'],
            array_column($command->journal(), "message"),
        );
    }

    public function testProjectInitializationRunsFromProjectRootBeforeDocumentRootExists(): void
    {
        $directory = sys_get_temp_dir() . "/docker-cli-project-init-" . bin2hex(random_bytes(8));
        $home = $directory . "/home";
        $projectRoot = $directory . "/project";
        $tasksDirectory = $directory . "/tasks";
        $projectState = $home . "/.config/docker-cli/state/projects/example";
        self::assertTrue(mkdir($projectRoot, 0775, true));
        self::assertTrue(mkdir($tasksDirectory, 0775, true));
        self::assertTrue(mkdir($projectState, 0775, true));
        file_put_contents($tasksDirectory . "/init.yaml", <<<YAML
            meta: { schema: task, version: 0.1 }
            task:
              name: Проверка директории инициализации
              code: test.project.init
              type: shell
              context: project
              tags: [project:init]
              action: pwd > initialization-directory.txt
            YAML);
        file_put_contents($tasksDirectory . "/maintenance.yaml", <<<YAML
            meta: { schema: task, version: 0.1 }
            task:
              name: Проверка резервной директории
              code: test.project.maintenance
              type: shell
              context: project
              action: pwd > maintenance-directory.txt
            YAML);
        file_put_contents($projectState . "/project.yaml", <<<YAML
            data:
              project:
                root: {$projectRoot}
                document_root: {$projectRoot}/public
            YAML);
        $previousHome = getenv("HOME");

        try {
            putenv("HOME=" . $home);
            $tester = new CommandTester(new TaskRunCommand(new TaskRepository($tasksDirectory)));

            $exitCode = $tester->execute(["task-code" => "test.project.init", "--project" => "example"]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $actualDirectory = file_get_contents($projectRoot . "/initialization-directory.txt");
            self::assertSame($projectRoot, trim((string) $actualDirectory));

            $exitCode = $tester->execute(["task-code" => "test.project.maintenance", "--project" => "example"]);

            self::assertSame(Command::SUCCESS, $exitCode);
            $actualDirectory = file_get_contents($projectRoot . "/maintenance-directory.txt");
            self::assertSame($projectRoot, trim((string) $actualDirectory));
        } finally {
            putenv($previousHome === false ? "HOME" : "HOME=" . $previousHome);
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
