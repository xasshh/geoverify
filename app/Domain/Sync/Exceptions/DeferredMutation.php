<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

use RuntimeException;

/**
 * A mutation whose parent has not arrived yet.
 *
 * Held rather than failed. On a bad connection the batch carrying the parent may
 * simply be later in the queue, and telling an officer their shop is invalid
 * because the server has not seen the building yet would be wrong and would lose
 * real work.
 */
final class DeferredMutation extends RuntimeException {}
