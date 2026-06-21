<?php
declare(strict_types=1);


namespace Flames\FileDb;

/**
 * @internal
 */
final class Pool
{
    /** @var array<int, FileDb> */
    private static array $open = [];

    public static function track(FileDb $filedb): void
    {
        self::$open[spl_object_id($filedb)] = $filedb;
    }

    public static function forget(FileDb $filedb): void
    {
        unset(self::$open[spl_object_id($filedb)]);
    }

    public static function closeAll(): void
    {
        foreach (self::$open as $filedb) {
            try {
                $filedb->close();
            } catch (\Throwable) {}
        }

        self::$open = [];
    }
}
