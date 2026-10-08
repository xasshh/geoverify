<?php

declare(strict_types=1);

namespace App\Domain\AreaCapture\Exceptions;

use RuntimeException;

/** Raised inside an import's transaction so a single refusal rolls back the lot. */
final class ImportRefused extends RuntimeException {}
