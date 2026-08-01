<?php

namespace TeckFuture\P6Analyzer\DTOs;

use Illuminate\Support\Collection;

/**
 * Represents the fully parsed contents of an XER file.
 *
 * Contains the ERMHDR header line and a map of table names to collections
 * of rows (associative arrays). Provides convenience methods to map
 * common tables to typed DTOs.
 *
 * @example
 * ```php
 * $parser = new XerParser();
 * $data = $parser->parseFile('/path/to/schedule.xer');
 *
 * $activities = $data->getActivities();    // Collection<ActivityData>
 * $projects   = $data->getProjects();      // Collection<ProjectData>
 * $rawWbs     = $data->getTable('PROJWBS'); // Collection<array>
 * ```
 */
final class ParsedXerData
{
    /**
     * @param  string  $header  The ERMHDR line from the XER file.
     * @param  Collection<string, Collection<int, array<string, string>>>  $tables  Map of table names to row collections.
     */
    public function __construct(
        public readonly string $header,
        public readonly Collection $tables,
    ) {}

    /**
     * Get a specific table as a collection of associative arrays.
     *
     * @return Collection<int, array<string, string>>
     */
    public function getTable(string $tableName): Collection
    {
        return $this->tables->get($tableName, collect());
    }

    /**
     * Map the PROJECT table to ProjectData DTOs.
     *
     * @return Collection<int, ProjectData>
     */
    public function getProjects(): Collection
    {
        return $this->getTable('PROJECT')->map(fn (array $row) => ProjectData::fromModelOrArray($row));
    }

    /**
     * Map the TASK table to ActivityData DTOs.
     *
     * @return Collection<int, ActivityData>
     */
    public function getActivities(): Collection
    {
        $taskrsrc = $this->getTable('TASKRSRC');
        $costsByActivity = [];
        foreach ($taskrsrc as $r) {
            $actId = $r['task_id'] ?? null;
            if ($actId) {
                if (! isset($costsByActivity[$actId])) {
                    $costsByActivity[$actId] = [
                        'target_cost' => 0.0,
                        'act_reg_cost' => 0.0,
                        'act_ot_cost' => 0.0,
                        'remain_cost' => 0.0,
                    ];
                }
                $costsByActivity[$actId]['target_cost'] += (float) ($r['target_cost'] ?? 0);
                $costsByActivity[$actId]['act_reg_cost'] += (float) ($r['act_reg_cost'] ?? 0);
                $costsByActivity[$actId]['act_ot_cost'] += (float) ($r['act_ot_cost'] ?? 0);
                $costsByActivity[$actId]['remain_cost'] += (float) ($r['remain_cost'] ?? 0);
            }
        }

        return $this->getTable('TASK')->map(function (array $row) use ($costsByActivity) {
            $actId = $row['task_id'] ?? null;
            if ($actId && isset($costsByActivity[$actId])) {
                $row['target_cost'] = (string) ($costsByActivity[$actId]['target_cost']);
                $row['act_cost'] = (string) ($costsByActivity[$actId]['act_reg_cost'] + $costsByActivity[$actId]['act_ot_cost']);
                $row['remain_cost'] = (string) ($costsByActivity[$actId]['remain_cost']);
            }

            return ActivityData::fromModelOrArray($row);
        });
    }

    /**
     * Map the TASKPRED table to RelationshipData DTOs.
     *
     * @return Collection<int, RelationshipData>
     */
    public function getRelationships(): Collection
    {
        return $this->getTable('TASKPRED')->map(fn (array $row) => RelationshipData::fromModelOrArray($row));
    }
}
