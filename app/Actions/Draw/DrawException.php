<?php

namespace App\Actions\Draw;

use RuntimeException;

/**
 * A draw operation that is not allowed; the message is meant to be shown to the user.
 */
class DrawException extends RuntimeException {}
