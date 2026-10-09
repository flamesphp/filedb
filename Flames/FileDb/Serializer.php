<?php
declare(strict_types=1);


namespace Flames\FileDb;

/**
 * @internal
 */
final class Serializer
{
    private const string IGBINARY_PREFIX = "\x00\x00\x00\x01";
    private const string PHP_PREFIX      = "\x00\x00\x00\x02";

    public static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value) && !is_object($value)) {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return (string) $value;
        }

        if (function_exists('igbinary_serialize')) {
            return self::IGBINARY_PREFIX . igbinary_serialize($value);
        }

        return self::PHP_PREFIX . serialize($value);
    }

    public static function decode(string $payload): mixed
    {
        if (str_starts_with($payload, self::IGBINARY_PREFIX)) {
            $raw = substr($payload, strlen(self::IGBINARY_PREFIX));
            if (function_exists('igbinary_unserialize')) {
                return igbinary_unserialize($raw);
            }
        }

        if (str_starts_with($payload, self::PHP_PREFIX)) {
            return unserialize(substr($payload, strlen(self::PHP_PREFIX)));
        }

        return $payload;
    }
}
