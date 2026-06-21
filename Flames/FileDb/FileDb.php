<?php
declare(strict_types=1);


namespace Flames\FileDb;

use Flames\FileDb\Client\Storage;

/**
 * On-disk memory engine backed by sharded SHA1 files.
 */
final class FileDb
{
    private Storage $storage;

    private Connection $connection;

    public function __construct(string $connection = 'default')
    {
        $this->connection = Connection::resolve($connection);
        $this->storage    = new Storage($this->connection);
        Pool::track($this);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function get(string $key): mixed
    {
        return $this->storage->get($key);
    }

    public function set(string $key, mixed $value): bool
    {
        return $this->storage->set($key, $value);
    }

    public function setex(string $key, int $seconds, mixed $value): bool
    {
        return $this->storage->setex($key, $seconds, $value);
    }

    /** @param list<string> $keys */
    public function mGet(array $keys): array
    {
        return $this->storage->mGet($keys);
    }

    public function del(string $key): int
    {
        return $this->storage->del($key);
    }

    public function deleteByPattern(string $pattern): int
    {
        return $this->storage->deleteByPattern($pattern);
    }

    public function pipeline(): self
    {
        $this->storage->pipeline();

        return $this;
    }

    /** @return list<mixed> */
    public function executePipeline(): array
    {
        return $this->storage->executePipeline();
    }

    public function close(): void
    {
        Pool::forget($this);
        $this->storage->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
