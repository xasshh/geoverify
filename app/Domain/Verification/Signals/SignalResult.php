<?php

declare(strict_types=1);

namespace App\Domain\Verification\Signals;

use App\Domain\Verification\Enums\Verdict;

/**
 * What one signal concluded, and the numbers it concluded it from.
 *
 * The message is written for a supervisor, not for a log. It says the finding
 * rather than the rule that produced it, because a supervisor deciding whether
 * to return a day's work needs "accuracy over 5 m on 2 of 7 fixes" and not
 * "AccuracySignal threshold exceeded".
 */
final readonly class SignalResult
{
    /** @param array<string, mixed> $evidence */
    private function __construct(
        public Verdict $verdict,
        public string $message,
        public array $evidence = [],
    ) {}

    /** @param array<string, mixed> $evidence */
    public static function ok(string $message, array $evidence = []): self
    {
        return new self(Verdict::Ok, $message, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public static function warn(string $message, array $evidence = []): self
    {
        return new self(Verdict::Warn, $message, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public static function fail(string $message, array $evidence = []): self
    {
        return new self(Verdict::Fail, $message, $evidence);
    }

    /** The signal had nothing to read. Not a finding, and never a penalty. */
    public static function unknown(string $message): self
    {
        return new self(Verdict::Unknown, $message);
    }
}
