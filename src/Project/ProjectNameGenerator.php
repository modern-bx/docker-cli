<?php

declare(strict_types=1);

namespace DockerCli\Project;

use Nubs\RandomNameGenerator\Alliteration;

final class ProjectNameGenerator
{
    /** @param list<string> $existingNames */
    public function generate(array $existingNames): string
    {
        $existing = array_fill_keys($existingNames, true);
        $candidates = $this->candidates();
        shuffle($candidates);

        foreach ($candidates as $candidate) {
            if (!isset($existing[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique project name: all adjective-animal combinations are already used.');
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $directory = dirname((new \ReflectionClass(Alliteration::class))->getFileName());
        $adjectives = $this->readWords($directory . '/adjectives.txt');
        $nouns = $this->readWords($directory . '/nouns.txt');

        $nounsByLetter = [];
        foreach ($nouns as $noun) {
            $nounsByLetter[$noun[0]][] = $noun;
        }

        $candidates = [];
        foreach ($adjectives as $adjective) {
            foreach ($nounsByLetter[$adjective[0]] ?? [] as $noun) {
                $candidate = $adjective . '-' . $noun;
                if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $candidate) === 1) {
                    $candidates[] = $candidate;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /** @return list<string> */
    private function readWords(string $file): array
    {
        $words = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($words === false) {
            throw new \RuntimeException(sprintf('Unable to read name generator word list "%s".', $file));
        }

        return array_values(array_filter(array_map(
            static fn (string $word): string => strtolower(trim($word)),
            $words,
        ), static fn (string $word): bool => $word !== ''));
    }
}
