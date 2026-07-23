<?php

/**
 * TablesColumnMapper — pure projection logic between Nextcloud Tables columns/rows
 * and OpenRegister virtual-schema properties / object payloads.
 *
 * This helper holds NO reference to any `OCA\Tables\*` class: it operates only on
 * the plain-array column and row descriptors that {@see TablesTableReader}
 * extracts from Tables' internal entities. That keeps the Tables-column ↔
 * JSON-schema mapping (design D5), the `columnId → property name` resolution
 * (D4), the relation → UUID derivation (D9) and the on-read drift/collision
 * handling fully unit-testable without the Tables app installed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Psr\Log\LoggerInterface;

/**
 * Pure Tables-column ↔ virtual-schema projection helper.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The D5 column-type mapping table is inherently a wide dispatch.
 */
class TablesColumnMapper
{
    /**
     * Constructor.
     *
     * @param TablesUuidDeriver $uuidDeriver Deterministic uuid derivation (relations).
     * @param LoggerInterface   $logger      Logger for drift / collision diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly TablesUuidDeriver $uuidDeriver,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the `columnId → propertyName` map for a table's columns.
     *
     * Prefers each column's `technicalName`, falling back to a slug of its
     * `title`. When two columns resolve to the same property name the collision
     * is disambiguated by appending the numeric `columnId` and a warning is
     * logged, so the projection stays deterministic (design edge-handling table).
     *
     * @param array<int, array<string, mixed>> $columns The table's column descriptors.
     *
     * @return array<int, string> Map of columnId → property name.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function buildColumnMap(array $columns): array
    {
        $map  = [];
        $seen = [];
        foreach ($columns as $column) {
            $columnId = (int) ($column['id'] ?? 0);
            if ($columnId === 0) {
                continue;
            }

            $name = $this->propertyName(column: $column);

            if (isset($seen[$name]) === true) {
                $disambiguated = $name.'_'.$columnId;
                $this->logger->warning(
                    sprintf(
                        '[ObjectSource:tables] column %d slug "%s" collides with column %d; using "%s"',
                        $columnId,
                        $name,
                        $seen[$name],
                        $disambiguated
                    )
                );
                $name = $disambiguated;
            }

            $seen[$name]    = $columnId;
            $map[$columnId] = $name;
        }//end foreach

        return $map;
    }//end buildColumnMap()

    /**
     * Project a single row's cells onto a property-keyed object payload.
     *
     * Unknown `columnId`s (column drift after binding) are skipped and logged.
     * Relation cells are mapped to the referenced virtual object's derived uuid
     * when `$targetSchemaExists` confirms the target table has a seeded schema,
     * otherwise they fall back to the raw integer rowId with a logged warning
     * (design D9).
     *
     * @param array<string, mixed>             $row                The row descriptor ({id, cells:[{columnId,value}]}).
     * @param array<int, array<string, mixed>> $columns            The table's column descriptors (keyed by list order).
     * @param array<int, string>               $columnMap          Map of columnId →
     *                                                             property name.
     * @param callable(int): bool              $targetSchemaExists Predicate: does the target table have a seeded schema?
     *
     * @return array<string, mixed> The projected object payload (property → value).
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function projectRow(array $row, array $columns, array $columnMap, callable $targetSchemaExists): array
    {
        $byId = [];
        foreach ($columns as $column) {
            $byId[(int) ($column['id'] ?? 0)] = $column;
        }

        $data = [];
        foreach (($row['cells'] ?? []) as $cell) {
            $columnId = (int) ($cell['columnId'] ?? 0);
            if (isset($columnMap[$columnId]) === false) {
                $this->logger->warning(
                    sprintf('[ObjectSource:tables] row %s references unknown column %d — skipped (drift)', (string) ($row['id'] ?? '?'), $columnId)
                );
                continue;
            }

            $column = ($byId[$columnId] ?? []);
            $data[$columnMap[$columnId]] = $this->coerceCell(
                value: ($cell['value'] ?? null),
                column: $column,
                targetSchemaExists: $targetSchemaExists
            );
        }//end foreach

        return $data;
    }//end projectRow()

    /**
     * Build the virtual schema `properties` + `required` blocks for a table.
     *
     * @param array<int, array<string, mixed>> $columns The table's column descriptors.
     *
     * @return array{properties: array<string, mixed>, required: array<int, string>}
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function buildSchemaProperties(array $columns): array
    {
        $columnMap  = $this->buildColumnMap(columns: $columns);
        $properties = [];
        $required   = [];
        foreach ($columns as $column) {
            $columnId = (int) ($column['id'] ?? 0);
            if (isset($columnMap[$columnId]) === false) {
                continue;
            }

            $name = $columnMap[$columnId];
            $properties[$name] = $this->columnToProperty(column: $column);
            if (($column['mandatory'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return [
            'properties' => $properties,
            'required'   => $required,
        ];
    }//end buildSchemaProperties()

    /**
     * Map one Tables column onto its JSON-schema property fragment (design D5).
     *
     * @param array<string, mixed> $column The column descriptor.
     *
     * @return array<string, mixed> The JSON-schema property fragment.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    public function columnToProperty(array $column): array
    {
        $type    = (string) ($column['type'] ?? 'text');
        $subtype = (string) ($column['subtype'] ?? '');

        switch ($type) {
            case 'number':
                return $this->numberProperty(column: $column, subtype: $subtype);
            case 'datetime':
                return ['type' => 'string', 'format' => $this->dateFormat(subtype: $subtype)];
            case 'selection':
                return $this->selectionProperty(column: $column, subtype: $subtype);
            case 'usergroup':
                return ['type' => 'array', 'items' => ['type' => 'object']];
            case 'relation':
                return ['type' => 'string', 'format' => 'uuid'];
            case 'text':
            default:
                if ($subtype === 'link') {
                    return ['type' => 'string', 'format' => 'uri'];
                }
                return ['type' => 'string'];
        }//end switch
    }//end columnToProperty()

    /**
     * Resolve a column's property name (technicalName, else slug of title).
     *
     * @param array<string, mixed> $column The column descriptor.
     *
     * @return string The property name.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function propertyName(array $column): string
    {
        $technical = trim((string) ($column['technicalName'] ?? ''));
        if ($technical !== '') {
            return $this->slug(value: $technical);
        }

        $slug = $this->slug(value: (string) ($column['title'] ?? ''));
        if ($slug === '') {
            return 'column_'.((int) ($column['id'] ?? 0));
        }

        return $slug;
    }//end propertyName()

    /**
     * Slugify a value into a stable, lower-case property name.
     *
     * @param string $value The raw value.
     *
     * @return string The slug (may be empty when the value has no word chars).
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function slug(string $value): string
    {
        $lower   = strtolower(trim($value));
        $slugged = preg_replace('/[^a-z0-9]+/', '_', $lower);

        return trim((string) $slugged, '_');
    }//end slug()

    /**
     * Coerce a raw cell value to its projected type (design D5).
     *
     * @param mixed                $value              The raw cell value.
     * @param array<string, mixed> $column             The column descriptor.
     * @param callable(int): bool  $targetSchemaExists Predicate for relation targets.
     *
     * @return mixed The coerced value.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function coerceCell(mixed $value, array $column, callable $targetSchemaExists): mixed
    {
        $type    = (string) ($column['type'] ?? 'text');
        $subtype = (string) ($column['subtype'] ?? '');

        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'number':
                if ($subtype === 'progress' || $subtype === 'stars' || $this->isIntegral(column: $column) === true) {
                    return (int) $value;
                }
                return (float) $value;
            case 'selection':
                if ($subtype === 'check') {
                    // Tables stores check cells as the strings 'true'/'false';
                    // a plain (bool) cast would turn 'false' into true.
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }

                if ($subtype === 'multi') {
                    return (array) $value;
                }
                return (string) $value;
            case 'usergroup':
                return (array) $value;
            case 'relation':
                return $this->relationValue(value: $value, column: $column, targetSchemaExists: $targetSchemaExists);
            case 'datetime':
            case 'text':
            default:
                if (is_array($value) === true) {
                    return $value;
                }
                return (string) $value;
        }//end switch
    }//end coerceCell()

    /**
     * Map a relation cell to the referenced virtual object's uuid, or fall back.
     *
     * @param mixed                $value              The raw relation cell value (referenced rowId).
     * @param array<string, mixed> $column             The relation column descriptor (carries the target tableId).
     * @param callable(int): bool  $targetSchemaExists Predicate: does the target table have a seeded schema?
     *
     * @return string|int The derived uuid, or the raw integer rowId on fallback.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function relationValue(mixed $value, array $column, callable $targetSchemaExists): string|int
    {
        $targetTableId = (int) ($column['relationTargetTableId'] ?? 0);
        $rowId         = (int) $value;

        if ($targetTableId !== 0 && $targetSchemaExists($targetTableId) === true) {
            return $this->uuidDeriver->deriveObjectUuid(tableId: $targetTableId, rowId: $rowId);
        }

        $this->logger->warning(
            sprintf(
                '[ObjectSource:tables] relation column %d target table %d has no seeded schema — falling back to raw rowId %d',
                (int) ($column['id'] ?? 0),
                $targetTableId,
                $rowId
            )
        );

        return $rowId;
    }//end relationValue()

    /**
     * Build the JSON-schema fragment for a `number` column (design D5).
     *
     * @param array<string, mixed> $column  The column descriptor.
     * @param string               $subtype The column subtype (progress/stars/...).
     *
     * @return array<string, mixed> The JSON-schema property fragment.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function numberProperty(array $column, string $subtype): array
    {
        if ($subtype === 'progress') {
            return ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];
        }

        if ($subtype === 'stars') {
            return ['type' => 'integer', 'minimum' => 0, 'maximum' => 5];
        }

        $numberType = 'number';
        if ($this->isIntegral(column: $column) === true) {
            $numberType = 'integer';
        }

        $property = ['type' => $numberType];
        if (array_key_exists('numberMin', $column) === true && $column['numberMin'] !== null) {
            $property['minimum'] = $column['numberMin'] + 0;
        }

        if (array_key_exists('numberMax', $column) === true && $column['numberMax'] !== null) {
            $property['maximum'] = $column['numberMax'] + 0;
        }

        return $property;
    }//end numberProperty()

    /**
     * Build the JSON-schema fragment for a `selection` column (design D5).
     *
     * @param array<string, mixed> $column  The column descriptor.
     * @param string               $subtype The column subtype (check/multi/...).
     *
     * @return array<string, mixed> The JSON-schema property fragment.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function selectionProperty(array $column, string $subtype): array
    {
        if ($subtype === 'check') {
            return ['type' => 'boolean'];
        }

        $options = array_values(array_map('strval', (array) ($column['selectionOptions'] ?? [])));

        if ($subtype === 'multi') {
            return ['type' => 'array', 'items' => ['enum' => $options]];
        }

        $property = ['type' => 'string'];
        if (empty($options) === false) {
            $property['enum'] = $options;
        }

        return $property;
    }//end selectionProperty()

    /**
     * Resolve the JSON-schema `format` for a datetime subtype.
     *
     * @param string $subtype The datetime subtype (date/time/...).
     *
     * @return string The JSON-schema format keyword.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function dateFormat(string $subtype): string
    {
        if ($subtype === 'date') {
            return 'date';
        }

        if ($subtype === 'time') {
            return 'time';
        }

        return 'date-time';
    }//end dateFormat()

    /**
     * Whether a number column carries an integral step (⇒ integer, not number).
     *
     * @param array<string, mixed> $column The column descriptor.
     *
     * @return bool True when the column is integral.
     *
     * @spec openspec/specs/tables-virtual-register/spec.md
     */
    private function isIntegral(array $column): bool
    {
        if (array_key_exists('numberDecimals', $column) === false || $column['numberDecimals'] === null) {
            return false;
        }

        return ((int) $column['numberDecimals']) === 0;
    }//end isIntegral()
}//end class
