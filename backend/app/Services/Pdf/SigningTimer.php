<?php

namespace App\Services\Pdf;

use Closure;

/**
 * Phase stopwatch for the signing pipeline.
 *
 * Total duration alone cannot tell a slow Java signer apart from slow disk or a
 * slow photometric pass, which is the first question asked when an operator
 * reports that a report "took forever".
 */
class SigningTimer
{
    private readonly float $startedAt;

    /**
     * @var array<string, int>
     */
    private array $phases = [];

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Runs $work, recording how long it took under $phase.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function measure(string $phase, Closure $work): mixed
    {
        $startedAt = microtime(true);

        try {
            return $work();
        } finally {
            // Recorded in the finally block so a failed phase still reports the
            // time it burned before throwing.
            $this->phases[$phase] = (int) round((microtime(true) - $startedAt) * 1000);
        }
    }

    public function totalMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /**
     * @return array<string, int>
     */
    public function phases(): array
    {
        return $this->phases;
    }
}
