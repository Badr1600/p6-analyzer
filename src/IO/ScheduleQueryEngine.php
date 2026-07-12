<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\DTOs\ProjectData;

/**
 * Query engine for searching and filtering parsed XER schedule data.
 */
class ScheduleQueryEngine
{
    public function __construct(protected ParsedXerData $data) {}

    /**
     * List all projects in the XER.
     *
     * @return Collection<int, ProjectData>
     */
    public function listProjects(): Collection
    {
        return $this->data->getProjects();
    }

    /**
     * List all activities, optionally filtered by project_id.
     *
     * @return Collection<int, ActivityData>
     */
    public function listActivities(?string $projectId = null): Collection
    {
        $activities = $this->data->getActivities();

        if ($projectId) {
            $activities = $activities->filter(
                fn (ActivityData $activity) => isset($activity->raw_data['proj_id']) && $activity->raw_data['proj_id'] == $projectId
            );
        }

        return $activities->values();
    }

    /**
     * Get a single activity by task_id or task_code.
     */
    public function getActivity(string $identifier): ?ActivityData
    {
        return $this->data->getActivities()->first(
            fn (ActivityData $activity) => $activity->id === $identifier || $activity->task_code === $identifier
        );
    }

    /**
     * Search activities by name (case-insensitive partial match).
     *
     * @return Collection<int, ActivityData>
     */
    public function searchActivities(string $query): Collection
    {
        $query = strtolower($query);

        return $this->data->getActivities()->filter(
            fn (ActivityData $activity) => str_contains(strtolower($activity->name ?? ''), $query)
        )->values();
    }

    /**
     * List all resources (raw RSRC table).
     *
     * @return Collection<int, array<string, string>>
     */
    public function listResources(): Collection
    {
        return $this->data->getTable('RSRC');
    }

    /**
     * List all calendars (raw CALENDAR table).
     *
     * @return Collection<int, array<string, string>>
     */
    public function listCalendars(): Collection
    {
        return $this->data->getTable('CALENDAR');
    }
}
