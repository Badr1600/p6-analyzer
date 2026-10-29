<?php

namespace TeckFuture\P6Analyzer\DTOs;

use Carbon\Carbon;

/**
 * Represents a single P6 schedule activity.
 *
 * This DTO normalizes activity data from various sources (raw XER arrays,
 * Laravel Eloquent models, or generic objects) into a consistent structure.
 *
 * @example
 * ```php
 * // From a raw XER row (array)
 * $activity = ActivityData::fromModelOrArray($xerRow);
 *
 * // From a Laravel model
 * $activity = ActivityData::fromModelOrArray($workbenchActivity);
 * ```
 */
final class ActivityData
{
    /**
     * Task type P6 writes for a WBS summary activity (`TASK.task_type`).
     */
    public const WBS_SUMMARY_TASK_TYPE = 'TT_WBS';

    /**
     * Task types P6 writes for milestones (`TASK.task_type`): `TT_Mile` is a
     * start milestone and `TT_FinMile` a finish milestone.
     *
     * @var array<int, string>
     */
    public const MILESTONE_TASK_TYPES = ['TT_Mile', 'TT_FinMile'];

    /**
     * P6 status code mapping from raw codes to normalized strings.
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        'TK_Complete' => 'Completed',
        'TK_Active' => 'In Progress',
        'TK_NotStart' => 'Not Started',
    ];

    public function __construct(
        public readonly string $id,
        public readonly ?string $activity_id,
        public readonly ?string $task_code,
        public readonly ?string $name,
        public readonly ?string $status_code,
        public readonly ?float $total_float_hr_cnt,
        public readonly ?float $target_drtn_hr_cnt,
        public readonly ?Carbon $act_start_date,
        public readonly ?Carbon $act_end_date,
        public readonly ?Carbon $target_start_date,
        public readonly ?Carbon $target_end_date,
        public readonly ?float $target_cost,
        public readonly ?float $act_cost,
        public readonly ?float $phys_comp_pct,
        public readonly ?float $complete_pct,
        public readonly ?float $planned_value,
        public readonly array $raw_data = [],
        public readonly ?float $remain_cost = null,
        public readonly ?float $target_work_qty = null,
        public readonly ?float $act_work_qty = null,
        public readonly ?float $remain_work_qty = null,
        public readonly ?float $remain_drtn_hr_cnt = null,
        public readonly ?string $complete_pct_type = null,
        public readonly ?string $task_type = null,
    ) {}

    /**
     * Create an ActivityData instance from a Laravel Model, stdClass, or associative array.
     *
     * When an array is passed without a `raw_data` key, the entire array
     * is treated as raw data (typical for rows parsed directly from an XER file).
     *
     * @param  mixed  $activity  A model, object, or associative array.
     */
    public static function fromModelOrArray(mixed $activity): self
    {
        $isArray = is_array($activity);
        $rawData = $isArray
            ? ($activity['raw_data'] ?? $activity)
            : (is_array($activity->raw_data ?? null) ? $activity->raw_data : (array) ($activity->raw_data ?? []));

        $rawStatus = $rawData['status_code'] ?? ($isArray ? ($activity['status'] ?? null) : ($activity->status ?? null));

        return new self(
            id: (string) ($isArray ? ($activity['id'] ?? $activity['task_id'] ?? $rawData['task_id'] ?? '') : $activity->id),
            activity_id: $isArray ? ($activity['activity_id'] ?? $rawData['activity_id'] ?? null) : ($activity->activity_id ?? null),
            task_code: (string) ($isArray ? ($activity['task_code'] ?? $rawData['task_code'] ?? '') : ($activity->task_code ?? '')),
            name: (string) ($isArray ? ($activity['task_name'] ?? $activity['name'] ?? $rawData['task_name'] ?? '') : ($activity->name ?? '')),
            status_code: self::normalizeStatus($rawStatus),
            total_float_hr_cnt: self::toNullableFloat($rawData['total_float_hr_cnt'] ?? null),
            target_drtn_hr_cnt: self::toNullableFloat($rawData['target_drtn_hr_cnt'] ?? null),
            act_start_date: self::parseDate($rawData['act_start_date'] ?? null),
            act_end_date: self::parseDate($rawData['act_end_date'] ?? null),
            target_start_date: self::parseDate($rawData['target_start_date'] ?? null),
            target_end_date: self::parseDate($rawData['target_end_date'] ?? null),
            target_cost: self::toNullableFloat($rawData['target_cost'] ?? null),
            act_cost: self::toNullableFloat($rawData['act_cost'] ?? null),
            phys_comp_pct: self::toNullableFloat($rawData['phys_complete_pct'] ?? $rawData['phys_comp_pct'] ?? null),
            complete_pct: self::toNullableFloat($rawData['complete_pct'] ?? null),
            planned_value: self::toNullableFloat($rawData['planned_value'] ?? null),
            raw_data: $rawData,
            remain_cost: self::toNullableFloat($rawData['remain_cost'] ?? null),
            target_work_qty: self::toNullableFloat($rawData['target_work_qty'] ?? null),
            act_work_qty: self::toNullableFloat($rawData['act_work_qty'] ?? null),
            remain_work_qty: self::toNullableFloat($rawData['remain_work_qty'] ?? null),
            remain_drtn_hr_cnt: self::toNullableFloat($rawData['remain_drtn_hr_cnt'] ?? null),
            complete_pct_type: ($rawData['complete_pct_type'] ?? null) ?: null,
            task_type: ($rawData['task_type'] ?? null) ?: null,
        );
    }

    /**
     * Normalize P6 status codes to human-friendly strings.
     *
     * Accepts both raw P6 codes (e.g. `TK_Complete`) and already-normalized
     * strings (e.g. `Completed`), returning a consistent value.
     */
    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return self::STATUS_MAP[$status] ?? $status;
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
