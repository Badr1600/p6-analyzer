<?php

namespace TeckFuture\P6Analyzer\DTOs;

/**
 * Represents a single P6 project.
 */
final class ProjectData
{
    public function __construct(
        public readonly string $id,
        public readonly string $proj_short_name,
        public readonly string $proj_name,
        public readonly array $raw_data = [],
    ) {}

    /**
     * Create from a Laravel Model, stdClass, or associative array.
     */
    public static function fromModelOrArray(mixed $project): self
    {
        $isArray = is_array($project);
        $rawData = $isArray
            ? ($project['raw_data'] ?? $project)
            : (is_array($project->raw_data ?? null) ? $project->raw_data : (array) ($project->raw_data ?? []));

        return new self(
            id: (string) ($isArray ? ($project['id'] ?? $rawData['proj_id'] ?? '') : $project->id),
            proj_short_name: (string) ($rawData['proj_short_name'] ?? ''),
            proj_name: (string) ($rawData['proj_name'] ?? ''),
            raw_data: $rawData,
        );
    }
}
