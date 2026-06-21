<?php
declare(strict_types=1);


namespace Flames\FileDb;

use Error;
use Flames\Env\Env;

/**
 * @internal
 */
final class Connection
{
    public readonly string $name;
    public readonly string $driver;
    public readonly string $path;

    private function __construct(string $name, string $driver, string $path)
    {
        $this->name   = $name;
        $this->driver = $driver;
        $this->path   = $path;
    }

    public static function resolve(string $connection = 'default'): self
    {
        $name   = self::normalizeName($connection);
        $prefix = 'DATABASE_MEMORY_' . strtoupper($name) . '_';

        $driver = Env::get($prefix . 'DRIVER');
        if ($driver === null || $driver === '') {
            $driver = $name;
        }

        $driver = strtolower((string) $driver);

        if ($driver !== 'filedb') {
            throw new Error(
                "Connection '{$name}' uses driver '{$driver}' which is not FileDB. Expected driver 'filedb'."
            );
        }

        $path = Env::get($prefix . 'PATH');
        if ($path === null || trim((string) $path) === '') {
            throw new Error("Missing env key {$prefix}PATH for FileDB connection '{$name}'.");
        }

        return new self($name, $driver, Path::resolve((string) $path));
    }

    public static function resolveName(string $connection = 'default'): string
    {
        return self::normalizeName($connection);
    }

    private static function normalizeName(string $connection): string
    {
        $connection = strtolower(trim($connection));

        if ($connection === '' || $connection === 'default') {
            $default = Env::get('DATABASE_MEMORY_DEFAULT');
            if ($default === null || $default === '') {
                throw new Error('DATABASE_MEMORY_DEFAULT is not set.');
            }

            return strtolower((string) $default);
        }

        return $connection;
    }
}
