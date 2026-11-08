<?php

namespace App\Actions\Entries;

use App\Models\Category;

final readonly class WeighInResult
{
    /** The weight fits the category the competitor is entered in. */
    public const FITS = 'fits';

    /** The entry was moved to the category the weight belongs to. */
    public const MOVED = 'moved';

    /** The weight fits no category of the competitor, so the entry stays where it is. */
    public const NO_CATEGORY = 'no_category';

    /** The competitor is already entered in the category the weight belongs to. */
    public const CONFLICT = 'conflict';

    /** A draw exists, so nothing was changed. */
    public const LOCKED = 'locked';

    /** The entry cannot be weighed at all (not Kumite, absent, not weighed yet). */
    public const REFUSED = 'refused';

    public function __construct(
        public string $outcome,
        public ?Category $from = null,
        public ?Category $to = null,
        public ?string $message = null,
    ) {}

    public function failed(): bool
    {
        return in_array($this->outcome, [self::LOCKED, self::REFUSED], true);
    }
}
