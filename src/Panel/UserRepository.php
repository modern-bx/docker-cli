<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Symfony\Component\Yaml\Yaml;

use function DockerCli\Util\join_path;

final class UserRepository
{
    private readonly string $file;
    private readonly string $dummyPasswordHash;

    public function __construct(private readonly string $salt, ?string $file = null)
    {
        if ($salt === '') {
            throw new \InvalidArgumentException('Password salt must not be empty.');
        }
        if ($file === null) {
            $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');
            $file = join_path($home, '.config', 'docker-cli', 'state', 'panel', 'settings', 'users.yaml');
            $legacyFile = join_path($home, '.config', 'docker-cli', 'state', 'panel', 'users.yaml');
            if (!is_file($file) && is_file($legacyFile)) {
                $directory = dirname($file);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('Unable to create settings directory "%s".', $directory));
                }
                if (!rename($legacyFile, $file)) {
                    throw new \RuntimeException(sprintf('Unable to move users file to "%s".', $file));
                }
            }
        }
        $this->file = $file;
        $this->dummyPasswordHash = $this->hashPassword(bin2hex(random_bytes(16)));
    }

    public function add(string $login, string $password, string $comments = ''): bool
    {
        $login = self::normalizeLogin($login);
        $this->validatePassword($password);

        return $this->update(function (array &$users) use ($login, $password, $comments): bool {
            if (isset($users[$login])) {
                return false;
            }
            $users[$login] = ['password' => $this->hashPassword($password), 'comments' => $comments];
            return true;
        });
    }

    public function rotatePassword(string $login, string $password): bool
    {
        $login = self::normalizeLogin($login);
        $this->validatePassword($password);
        return $this->update(function (array &$users) use ($login, $password): bool {
            if (!isset($users[$login])) return false;
            $users[$login]['password'] = $this->hashPassword($password);
            return true;
        });
    }

    public function updateComments(string $login, string $comments): bool
    {
        $login = self::normalizeLogin($login);
        return $this->update(static function (array &$users) use ($login, $comments): bool {
            if (!isset($users[$login])) return false;
            $users[$login]['comments'] = $comments;
            return true;
        });
    }

    /** @return list<array{login: string, comments: string}> */
    public function users(): array
    {
        $result = [];
        foreach ($this->read() as $login => $user) {
            $result[] = ['login' => $login, 'comments' => is_string($user['comments'] ?? null) ? $user['comments'] : ''];
        }
        return $result;
    }

    public function delete(string $login): bool
    {
        $login = self::normalizeLogin($login);

        return $this->update(static function (array &$users) use ($login): bool {
            if (!isset($users[$login])) {
                return false;
            }
            unset($users[$login]);
            return true;
        });
    }

    public function verifyPassword(string $login, string $password): bool
    {
        $login = self::normalizeLogin($login);
        $users = $this->read();
        $storedHash = $users[$login]['password'] ?? null;
        $exists = is_string($storedHash);
        $hash = $exists ? $storedHash : $this->dummyPasswordHash;
        $valid = password_verify(hash_hmac('sha256', $password, $this->salt), $hash);

        return $exists && $valid;
    }

    public function contains(string $login): bool
    {
        $login = self::normalizeLogin($login);
        return isset($this->read()[$login]);
    }

    public static function normalizeLogin(string $login): string
    {
        $login = strtolower(trim($login));
        if (filter_var($login, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Логин должен быть корректным email-адресом.');
        }
        return $login;
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 16 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Пароль должен содержать от 16 до 1024 байт.');
        }
    }

    private function hashPassword(string $password): string
    {
        $hash = password_hash(hash_hmac('sha256', $password, $this->salt), PASSWORD_DEFAULT);
        if (!is_string($hash)) throw new \RuntimeException('Unable to hash password.');
        return $hash;
    }

    /** @return array<string, array{password: string, comments?: string}> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = Yaml::parseFile($this->file);
        $users = is_array($data)
            && ($data['meta']['schema'] ?? null) === 'settings.users'
            && ($data['meta']['version'] ?? null) === 0.1
            && is_array($data['settings.users'] ?? null)
            ? ($data['settings.users']['users'] ?? []) : [];
        return is_array($users) ? $users : [];
    }

    /** @param callable(array<string, array{password: string, comments?: string}> &): bool $callback */
    private function update(callable $callback): bool
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create panel directory "%s".', $directory));
        }
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Unable to lock users file "%s".', $this->file));
        }
        try {
            $contents = stream_get_contents($handle);
            $data = is_string($contents) && trim($contents) !== '' ? Yaml::parse($contents) : [];
            $users = is_array($data)
                && ($data['meta']['schema'] ?? null) === 'settings.users'
                && ($data['meta']['version'] ?? null) === 0.1
                && is_array($data['settings.users'] ?? null)
                && is_array($data['settings.users']['users'] ?? null)
                ? $data['settings.users']['users'] : [];
            $changed = $callback($users);
            if ($changed) {
                ksort($users);
                rewind($handle);
                ftruncate($handle, 0);
                $document = [
                    'meta' => ['schema' => 'settings.users', 'version' => 0.1],
                    'settings.users' => ['users' => $users],
                ];
                if (fwrite($handle, Yaml::dump($document, 4, 2)) === false) {
                    throw new \RuntimeException(sprintf('Unable to write users file "%s".', $this->file));
                }
                fflush($handle);
                chmod($this->file, 0600);
            }
            return $changed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
