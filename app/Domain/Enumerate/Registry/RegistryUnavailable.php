<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

use RuntimeException;

/** The provider did not answer. Not a finding about the business. */
final class RegistryUnavailable extends RuntimeException {}
