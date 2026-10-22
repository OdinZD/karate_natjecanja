<?php

namespace App\Actions\Results;

use App\Models\Category;
use App\Models\Entry;

final readonly class CategoryResultRow
{
    /**
     * @param  array<int, Entry>  $first
     * @param  array<int, Entry>  $second
     * @param  array<int, Entry>  $third  Up to two entries
     */
    public function __construct(
        public Category $category,
        public array $first,
        public array $second,
        public array $third,
        public bool $complete,
    ) {}
}
