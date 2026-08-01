<?php

namespace TeckFuture\P6Analyzer\DTOs;

/**
 * The per-activity values feeding an EVM calculation, extracted for one
 * specific measurement method (cost, labor units, or duration).
 *
 * Extraction is the ONLY method-specific step of the calculation; every
 * formula downstream operates on this shape. `remaining` is deliberately
 * nullable: null means "the schedule does not carry this field" (no
 * inference possible), while 0.0 means "the schedule says nothing remains"
 * (a completed activity). Conflating the two is how actual-cost inference
 * goes wrong.
 */
final class ActivityEvmInput
{
    public function __construct(
        /** Budget At Completion in the method's unit. */
        public readonly float $bac,
        /** Explicitly tracked actuals (0.0 when the schedule records none). */
        public readonly float $trackedActual,
        /** Remaining amount when the schedule carries it; null when absent. */
        public readonly ?float $remaining,
        /** Percent complete (0–100) after type selection and status overrides. */
        public readonly float $pctComplete,
        /** Caller-supplied planned value; null triggers time-phased estimation. */
        public readonly ?float $explicitPv,
    ) {}
}
