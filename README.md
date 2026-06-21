# Flames: FileDB

Sharded on-disk key-value store for `DATABASE_MEMORY_FILEDB_*`.

Designed for large key counts without a central JSON index.

## Layout

```
{PATH}/
  v/{aa}/{bb}/{sha1(key)}              # values (binary header + payload)
  g/{aa}/{bb}/{sha1(prefix)}/{hh}/{sha1(key)}   # prefix groups for users.*
```

- Filenames are SHA1 hashes only — no extensions.
- Files under `g/` hold the storage key for prefix-scoped wildcard deletes.
- Relative paths resolve from **ROOT_PATH**.

## Usage

```php
use Flames\Memory\Memory;

Memory::driver('filedb')->set('users.1', 123456, 3600);
Memory::driver('filedb')->get('users.1');
Memory::driver('filedb')->destroy('users.*');
```

## Env

```env
DATABASE_MEMORY_FILEDB_DRIVER=filedb
DATABASE_MEMORY_FILEDB_PATH="${STORAGE_PATH}/flames.filedb/"
```
