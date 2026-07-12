<?php

namespace TeckFuture\P6Analyzer\DTOs;

/**
 * Represents a single P6 relationship (predecessor link) between two activities.
 */
final class RelationshipData
{
    public function __construct(
        public readonly string $id,
        public readonly string $pred_type,
        public readonly ?float $lag_hr_cnt,
        public readonly array $raw_data = [],
    ) {}

    /**
     * Create from a Laravel Model, stdClass, or associative array.
     */
    public static function fromModelOrArray(mixed $relationship): self
    {
        $isArray = is_array($relationship);
        $rawData = $isArray
            ? ($relationship['raw_data'] ?? $relationship)
            : (is_array($relationship->raw_data ?? null) ? $relationship->raw_data : (array) ($relationship->raw_data ?? []));

        return new self(
            id: (string) ($isArray ? ($relationship['id'] ?? $relationship['task_pred_id'] ?? $rawData['task_pred_id'] ?? '') : $relationship->id),
            pred_type: (string) ($rawData['pred_type'] ?? ''),
            lag_hr_cnt: isset($rawData['lag_hr_cnt']) && $rawData['lag_hr_cnt'] !== '' ? (float) $rawData['lag_hr_cnt'] : null,
            raw_data: $rawData,
        );
    }
}
