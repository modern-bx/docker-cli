<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class TokenRepository
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        if ($directory === null) {
            $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
            $directory = join_path($home, '.config', 'docker-cli', 'panel', 'sessions', 'tokens');
        }
        $this->directory = $directory;
    }

    public function store(string $token, string $jti, string $login, int $issuedAt, int $expiresAt): void
    {
        $this->ensureDirectory();
        $lock = fopen(join_path($this->directory, '.lock'), 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock token directory.');
        }
        try {
            $counter = 1;
            do {
                $file = join_path($this->directory, sprintf('%d.%d.%s.yaml', $issuedAt * 1_000, $counter++, $login));
            } while (file_exists($file));
            $data = [
                'meta' => ['schema' => 'token.jwt', 'version' => 0.1],
                'token.jwt' => [
                    'id' => $jti,
                    'login' => $login,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'sha256' => hash('sha256', $token),
                ],
            ];
            if (file_put_contents($file, Yaml::dump($data, 3, 2), LOCK_EX) === false) {
                throw new \RuntimeException(sprintf('Unable to write token file "%s".', $file));
            }
            chmod($file, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function contains(string $token, string $jti, string $login, int $expiresAt, ?int $now = null): bool
    {
        $now ??= time();
        foreach ($this->records() as [$file, $record]) {
            if (($record['expires_at'] ?? 0) <= $now) {
                @unlink($file);
                continue;
            }
            if (($record['id'] ?? null) === $jti
                && ($record['login'] ?? null) === $login
                && ($record['expires_at'] ?? null) === $expiresAt
                && is_string($record['sha256'] ?? null)
                && hash_equals($record['sha256'], hash('sha256', $token))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $logins */
    public function revoke(array $logins): int
    {
        $lookup = array_fill_keys(array_map(UserRepository::normalizeLogin(...), $logins), true);
        $removed = 0;
        foreach ($this->records() as [$file, $record]) {
            if (is_string($record['login'] ?? null) && isset($lookup[$record['login']]) && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /** @return list<array{string, array<string, mixed>}> */
    private function records(): array
    {
        if (!is_dir($this->directory)) return [];
        $records = [];
        foreach (scandir($this->directory) ?: [] as $name) {
            if (!str_ends_with($name, '.yaml')) continue;
            $file = join_path($this->directory, $name);
            try {
                $data = Yaml::parseFile($file);
            } catch (\Throwable) {
                continue;
            }
            $record = is_array($data)
                && ($data['meta']['schema'] ?? null) === 'token.jwt'
                && ($data['meta']['version'] ?? null) === 0.1
                && is_array($data['token.jwt'] ?? null)
                ? $data['token.jwt'] : null;
            if (is_array($record)) $records[] = [$file, $record];
        }
        return $records;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Unable to create token directory "%s".', $this->directory));
        }
        chmod($this->directory, 0700);
    }
}
