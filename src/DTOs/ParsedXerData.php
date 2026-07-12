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
        return $this->getTable('TASK')->map(fn (array $row) => ActivityData::fromModelOrArray($row));
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
