<?php

namespace App\Support;

use Collator;
use Illuminate\Support\Collection;
use PDO;
use WeakMap;

/**
 * Croatian alphabetical order (Č, Ć, Đ, Š, Ž and the digraphs sort as letters of their own), which neither SQLite's
 * default collation nor a plain string comparison gives.
 */
final class Croatian
{
    private static ?Collator $collator = null;

    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $registered = null;

    public static function compare(string $a, string $b): int
    {
        self::$collator ??= class_exists(Collator::class) ? new Collator('hr_HR') : null;

        return self::$collator !== null ? (int) self::$collator->compare($a, $b) : strcmp($a, $b);
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $items
     * @param  callable(TValue): string  $key
     * @return Collection<int, TValue>
     */
    public static function sortBy(Collection $items, callable $key): Collection
    {
        return $items->sort(fn ($a, $b) => self::compare($key($a), $key($b)))->values();
    }

    /**
     * Make `COLLATE hr` available on a SQLite connection.
     */
    public static function registerCollation(PDO $pdo): void
    {
        self::$registered ??= new WeakMap;

        if (isset(self::$registered[$pdo])) {
            return;
        }

        $compare = fn (string $a, string $b): int => self::compare($a, $b);

        if (method_exists($pdo, 'createCollation')) {
            $pdo->createCollation('hr', $compare);
        } else {
            $pdo->sqliteCreateCollation('hr', $compare);
        }

        self::$registered[$pdo] = true;
    }
}
