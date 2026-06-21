<?php
declare(strict_types=1);


namespace Flames\FileDb;

/**
 * @internal
 */
final class Path
{
    public static function resolve(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new \InvalidArgumentException('FileDB path cannot be empty.');
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if (self::isAbsolute($path)) {
            return $path;
        }

        if (!defined('ROOT_PATH')) {
            return $path;
        }

        return rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        if (strlen($path) >= 2 && ctype_alpha($path[0]) && $path[1] === ':') {
            return true;
        }

        return str_starts_with($path, '~' . DIRECTORY_SEPARATOR) || $path === '~';
    }
}
