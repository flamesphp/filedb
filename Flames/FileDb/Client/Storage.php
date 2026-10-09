<?php
declare(strict_types=1);


namespace Flames\FileDb\Client;

use Flames\FileDb\Connection;
use Flames\FileDb\KeyHash;
use Flames\FileDb\Serializer;

/**
 * Sharded on-disk KV store. No central index — O(1) reads, prefix-scoped deletes.
 *
 * @internal
 */
final class Storage
{
    private const int BATCH = 500;

    /** @var 'pipeline'|null */
    private ?string $mode = null;

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queued = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function get(string $storageKey): mixed
    {
        if ($this->mode === 'pipeline') {
            $this->queued[] = ['GET', [$storageKey]];

            return null;
        }

        return $this->read($storageKey);
    }

    public function set(string $storageKey, mixed $value): bool
    {
        if ($this->mode === 'pipeline') {
            $this->queued[] = ['SET', [$storageKey, $value, null]];

            return true;
        }

        return $this->write($storageKey, $value, null);
    }

    public function setex(string $storageKey, int $seconds, mixed $value): bool
    {
        if ($this->mode === 'pipeline') {
            $this->queued[] = ['SET', [$storageKey, $value, $seconds > 0 ? time() + $seconds : null]];

            return true;
        }

        $expires = $seconds > 0 ? time() + $seconds : null;

        return $this->write($storageKey, $value, $expires);
    }

    /** @param list<string> $storageKeys */
    public function mGet(array $storageKeys): array
    {
        if ($this->mode === 'pipeline') {
            $this->queued[] = ['MGET', [$storageKeys]];

            return array_fill(0, count($storageKeys), null);
        }

        $out = [];
        foreach ($storageKeys as $key) {
            $out[] = $this->read((string) $key);
        }

        return $out;
    }

    public function del(string $storageKey): int
    {
        if ($this->mode === 'pipeline') {
            $this->queued[] = ['DEL', [$storageKey]];

            return 1;
        }

        return $this->erase($storageKey) ? 1 : 0;
    }

    /** @param list<string> $storageKeys */
    public function delMany(array $storageKeys): int
    {
        $deleted = 0;
        foreach ($storageKeys as $key) {
            if ($this->erase((string) $key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function deleteByPattern(string $pattern): int
    {
        if ($this->mode === 'pipeline') {
            throw new \RuntimeException('deleteByPattern() is not supported inside a pipeline.');
        }

        $prefix = KeyHash::patternPrefix($pattern);

        if ($prefix !== null && !str_contains(substr($pattern, strlen($prefix)), '?')) {
            return $this->deleteByPrefix($prefix, $pattern);
        }

        return $this->deleteByGlobScan($pattern);
    }

    public function pipeline(): self
    {
        $this->mode   = 'pipeline';
        $this->queued = [];

        return $this;
    }

    /** @return list<mixed> */
    public function executePipeline(): array
    {
        if ($this->mode !== 'pipeline') {
            throw new \RuntimeException('executePipeline() called without pipeline().');
        }

        $queued = $this->queued;
        $this->mode   = null;
        $this->queued = [];

        $out = [];
        foreach ($queued as [$command, $args]) {
            $out[] = match ($command) {
                'GET'   => $this->read((string) $args[0]),
                'SET'   => $this->write((string) $args[0], $args[1], $args[2]),
                'MGET'  => $this->mGet($args[0]),
                'DEL'   => $this->erase((string) $args[0]) ? 1 : 0,
                default => null,
            };
        }

        return $out;
    }

    public function close(): void
    {
        $this->mode   = null;
        $this->queued = [];
    }

    private function read(string $storageKey): mixed
    {
        $hash = KeyHash::hash($storageKey);
        $path = KeyHash::valuePath($this->connection->path, $hash);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < 10) {
            return null;
        }

        $expires = unpack('N', substr($raw, 0, 4))[1];
        $keyLen  = unpack('n', substr($raw, 4, 2))[1];
        $offset  = 6 + $keyLen;

        if ($offset + 4 > strlen($raw)) {
            return null;
        }

        $length = unpack('N', substr($raw, $offset, 4))[1];
        $offset += 4;

        if ($length < 0 || $offset + $length > strlen($raw)) {
            return null;
        }

        if ($expires !== 0 && $expires <= time()) {
            $this->erase($storageKey);

            return null;
        }

        return Serializer::decode(substr($raw, $offset, $length));
    }

    private function write(string $storageKey, mixed $value, ?int $expires): bool
    {
        $hash    = KeyHash::hash($storageKey);
        $path    = KeyHash::valuePath($this->connection->path, $hash);
        $payload = Serializer::encode($value);
        $expires ??= 0;
        $keyLen  = strlen($storageKey);

        $blob = pack('Nn', $expires, $keyLen)
            . $storageKey
            . pack('N', strlen($payload))
            . $payload;
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $blob, LOCK_EX) === false) {
            @unlink($temp);

            return false;
        }

        if (!rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        foreach (KeyHash::prefixGroups($storageKey) as $group) {
            $this->writeRef($group, $hash, $storageKey);
        }

        return true;
    }

    private function writeRef(string $groupPrefix, string $keyHash, string $storageKey): void
    {
        $groupHash = KeyHash::hash($groupPrefix);
        $path      = KeyHash::refPath($this->connection->path, $groupHash, $keyHash);
        $dir       = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($path, $storageKey, LOCK_EX);
    }

    private function erase(string $storageKey): bool
    {
        $hash = KeyHash::hash($storageKey);
        $path = KeyHash::valuePath($this->connection->path, $hash);
        $gone = false;

        if (is_file($path)) {
            @unlink($path);
            $gone = true;
        }

        foreach (KeyHash::prefixGroups($storageKey) as $group) {
            $ref = KeyHash::refPath($this->connection->path, KeyHash::hash($group), $hash);
            if (is_file($ref)) {
                @unlink($ref);
                $gone = true;
            }
        }

        return $gone;
    }

    private function deleteByPrefix(string $prefix, string $pattern): int
    {
        $groupHash = KeyHash::hash($prefix);
        $groupRoot = KeyHash::groupRoot($this->connection->path, $groupHash);

        if (!is_dir($groupRoot)) {
            return 0;
        }

        $deleted = 0;
        $batch   = [];

        for ($shard = 0; $shard < 256; $shard++) {
            $shardDir = $groupRoot . DIRECTORY_SEPARATOR . sprintf('%02x', $shard);

            if (!is_dir($shardDir)) {
                continue;
            }

            $handle = opendir($shardDir);
            if ($handle === false) {
                continue;
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..' || !KeyHash::isHashFilename($entry)) {
                    continue;
                }

                $refPath = $shardDir . DIRECTORY_SEPARATOR . $entry;
                $stored  = file_get_contents($refPath);
                if ($stored === false || $stored === '') {
                    @unlink($refPath);
                    continue;
                }

                if (!fnmatch($pattern, $stored)) {
                    continue;
                }

                $batch[] = $stored;

                if (count($batch) >= self::BATCH) {
                    $deleted += $this->delMany($batch);
                    $batch = [];
                }
            }

            closedir($handle);
        }

        if ($batch !== []) {
            $deleted += $this->delMany($batch);
        }

        return $deleted;
    }

    private function deleteByGlobScan(string $pattern): int
    {
        $valueRoot = KeyHash::valueRoot($this->connection->path);
        if (!is_dir($valueRoot)) {
            return 0;
        }

        $deleted = 0;
        $batch   = [];

        for ($a = 0; $a < 256; $a++) {
            $dirA = $valueRoot . DIRECTORY_SEPARATOR . sprintf('%02x', $a);
            if (!is_dir($dirA)) {
                continue;
            }

            for ($b = 0; $b < 256; $b++) {
                $dirB = $dirA . DIRECTORY_SEPARATOR . sprintf('%02x', $b);
                if (!is_dir($dirB)) {
                    continue;
                }

                $handle = opendir($dirB);
                if ($handle === false) {
                    continue;
                }

                while (($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..' || !KeyHash::isHashFilename($entry)) {
                        continue;
                    }

                    $path = $dirB . DIRECTORY_SEPARATOR . $entry;
                    $head = file_get_contents($path, false, null, 0, 512);
                    if ($head === false || strlen($head) < 10) {
                        continue;
                    }

                    $keyLen = unpack('n', substr($head, 4, 2))[1];
                    if (6 + $keyLen > strlen($head)) {
                        $head = file_get_contents($path, false, null, 0, 6 + $keyLen);
                        if ($head === false || strlen($head) < 6 + $keyLen) {
                            continue;
                        }
                    }

                    $stored = substr($head, 6, $keyLen);
                    if ($stored === '' || !fnmatch($pattern, $stored)) {
                        continue;
                    }

                    $batch[] = $stored;

                    if (count($batch) >= self::BATCH) {
                        $deleted += $this->delMany($batch);
                        $batch = [];
                    }
                }

                closedir($handle);
            }
        }

        if ($batch !== []) {
            $deleted += $this->delMany($batch);
        }

        return $deleted;
    }
}
