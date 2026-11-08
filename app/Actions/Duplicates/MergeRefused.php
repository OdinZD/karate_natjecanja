<?php

namespace App\Actions\Duplicates;

use RuntimeException;

/**
 * A merge of a group that has to be rolled back as a whole because one of its parts was refused.
 */
class MergeRefused extends RuntimeException {}
