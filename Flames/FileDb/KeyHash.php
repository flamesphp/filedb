<?php
declare(strict_types=1);


namespace Flames\FileDb;

/**
 * SHA1 key hashing and sharded path layout.
 *
 * Values:  v/{h0h1}/{h2h3}/{hash}
 * Groups:  g/{g0g1}/{g2g3}/{groupHash}/{h0h1}/{hash}
 *
 * @internal
 */
final class KeyHash
{
    public static function hash(string $storageKey): string
    {
        return sha1($storageKey);
    }

    public static function valuePath(string $root, string $hash): string
    {
        return $root . DIRECTORY_SEPARATOR . 'v'
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2, 2)
            . DIRECTORY_SEPARATOR . $hash;
    }

    public static function isHashFilename(string $name): bool
    {
        return strlen($name) === 40 && ctype_xdigit($name);
    }

    public static function refPath(string $root, string $groupHash, string $keyHash): string
    {
        return $root . DIRECTORY_SEPARATOR . 'g'
            . DIRECTORY_SEPARATOR . substr($groupHash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($groupHash, 2, 2)
            . DIRECTORY_SEPARATOR . $groupHash
            . DIRECTORY_SEPARATOR . substr($keyHash, 0, 2)
            . DIRECTORY_SEPARATOR . $keyHash;
    }

    /**
     * Prefix groups for a storage key (users:1 -> [users:]).
     *
     * @return list<string>
     */
    public static function prefixGroups(string $storageKey): array
    {
        if (!str_contains($storageKey, ':')) {
            return [];
        }

        $parts  = explode(':', $storageKey);
        $groups = [];
        $prefix = '';

        for ($i = 0, $last = count($parts) - 1; $i < $last; $i++) {
            $prefix .= $parts[$i] . ':';
            $groups[] = $prefix;
        }

        return $groups;
    }

    /** Prefix for a glob pattern like users:* */
    public static function patternPrefix(string $pattern): ?string
    {
        $star = strpos($pattern, '*');
        if ($star === false) {
            return null;
        }

        return substr($pattern, 0, $star);
    }
}
