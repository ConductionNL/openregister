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
class TablesColumnMapper {
	/**
	 * Constructor.
	 *
	 * @param TablesUuidDeriver $uuidDeriver Deterministic uuid derivation (relations).
	 * @param LoggerInterface $logger Logger for drift / collision diagnostics.
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
	public function buildColumnMap(array $columns): array {
		$map = [];
		$seen = [];
		foreach ($columns as $column) {
			$columnId = (int)($column['id'] ?? 0);
			if ($columnId === 0) {
				continue;
			}

			$name = $this->uniqueName(column: $column, seen: $seen);
			$seen[$name] = $columnId;
			$map[$columnId] = $name;
		}

		return $map;
	}//end buildColumnMap()

	/**
	 * The property name, suffixed with the column id when an earlier column took it.
	 *
	 * @param array<string, mixed> $column The column descriptor (with a non-zero id).
	 * @param array<string, int>   $seen   Names taken so far => the column that took them.
	 *
	 * @return string The unique name.
	 */
	private function uniqueName(array $column, array $seen): string {
		$name     = $this->propertyName(column: $column);
		$columnId = (int)($column['id'] ?? 0);
		if (isset($seen[$name]) === false) {
			return $name;
		}

		$disambiguated = $name . '_' . $columnId;
		$this->logger->warning(
			sprintf('[ObjectSource:tables] column %d slug "%s" collides with column %d; using "%s"', $columnId, $name, $seen[$name], $disambiguated)
		);

		return $disambiguated;
	}//end uniqueName()

	/**
	 * Project a single row's cells onto a property-keyed object payload.
	 *
	 * Unknown `columnId`s (column drift after binding) are skipped and logged.
	 * Relation cells are mapped to the referenced virtual object's derived uuid
	 * when `$targetSchemaExists` confirms the target table has a seeded schema,
	 * otherwise they fall back to the raw integer rowId with a logged warning
	 * (design D9).
	 *
	 * @param array<string, mixed> $row        The row descriptor ({id, cells:[{columnId,value}]}).
	 * @param TablesRowProjection  $projection The table's columns, column map and relation-target check.
	 *
	 * @return array<string, mixed> The projected object payload (property → value).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function projectRow(array $row, TablesRowProjection $projection): array {
		$byId = $this->columnsById(columns: $projection->columns);
		$data = [];
		foreach (($row['cells'] ?? []) as $cell) {
			$columnId = (int)($cell['columnId'] ?? 0);
			if (isset($projection->columnMap[$columnId]) === false) {
				$this->logDrift(row: $row, columnId: $columnId);
				continue;
			}

			$typedCell = new TablesCell(value: ($cell['value'] ?? null), column: ($byId[$columnId] ?? []));
			$data[$projection->columnMap[$columnId]] = $this->coerceCell(cell: $typedCell, projection: $projection);
		}

		return $data;
	}//end projectRow()

	/**
	 * Log a cell whose column is not in the column map as drift.
	 *
	 * @param array<string, mixed> $row      The raw row (for its id in the log line).
	 * @param int                  $columnId The cell's column id.
	 *
	 * @return void
	 */
	private function logDrift(array $row, int $columnId): void {
		$this->logger->warning(
			sprintf('[ObjectSource:tables] row %s references unknown column %d — skipped (drift)', (string)($row['id'] ?? '?'), $columnId)
		);
	}//end logDrift()

	/**
	 * The column descriptors keyed by column id.
	 *
	 * @param array<int, array<string, mixed>> $columns The column descriptors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function columnsById(array $columns): array {
		$byId = [];
		foreach ($columns as $column) {
			$byId[(int)($column['id'] ?? 0)] = $column;
		}

		return $byId;
	}//end columnsById()

	/**
	 * Build the virtual schema `properties` + `required` blocks for a table.
	 *
	 * @param array<int, array<string, mixed>> $columns The table's column descriptors.
	 *
	 * @return array{properties: array<string, mixed>, required: array<int, string>}
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function buildSchemaProperties(array $columns): array {
		$columnMap = $this->buildColumnMap(columns: $columns);
		$schema    = ['properties' => [], 'required' => []];
		foreach ($columns as $column) {
			$name = ($columnMap[(int)($column['id'] ?? 0)] ?? null);
			if ($name === null) {
				continue;
			}

			$schema['properties'][$name] = $this->columnToProperty(column: $column);
			if (($column['mandatory'] ?? false) === true) {
				$schema['required'][] = $name;
			}
		}

		return $schema;
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
	public function columnToProperty(array $column): array {
		$subtype = (string)($column['subtype'] ?? '');

		return match ((string)($column['type'] ?? 'text')) {
			'number' => $this->numberProperty(column: $column, subtype: $subtype),
			'datetime' => ['type' => 'string', 'format' => $this->dateFormat(subtype: $subtype)],
			'selection' => $this->selectionProperty(column: $column, subtype: $subtype),
			'usergroup' => ['type' => 'array', 'items' => ['type' => 'object']],
			'relation' => ['type' => 'string', 'format' => 'uuid'],
			default => $this->textProperty(subtype: $subtype),
		};
	}//end columnToProperty()

	/**
	 * The property of a text (or unknown) column: a URI for the link subtype.
	 *
	 * @param string $subtype The column subtype.
	 *
	 * @return array<string, mixed>
	 */
	private function textProperty(string $subtype): array {
		if ($subtype === 'link') {
			return ['type' => 'string', 'format' => 'uri'];
		}

		return ['type' => 'string'];
	}//end textProperty()

	/**
	 * Resolve a column's property name (technicalName, else slug of title).
	 *
	 * @param array<string, mixed> $column The column descriptor.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function propertyName(array $column): string {
		$technical = trim((string)($column['technicalName'] ?? ''));
		if ($technical !== '') {
			return $this->slug(value: $technical);
		}

		$slug = $this->slug(value: (string)($column['title'] ?? ''));
		if ($slug === '') {
			return 'column_' . ((int)($column['id'] ?? 0));
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
	private function slug(string $value): string {
		$lower = strtolower(trim($value));
		$slugged = preg_replace('/[^a-z0-9]+/', '_', $lower);

		return trim((string)$slugged, '_');
	}//end slug()

	/**
	 * Coerce a raw cell value to its projected type (design D5).
	 *
	 * @param TablesCell          $cell       The raw cell value and its column descriptor.
	 * @param TablesRowProjection $projection The table projection (for relation targets).
	 *
	 * @return mixed The coerced value.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function coerceCell(TablesCell $cell, TablesRowProjection $projection): mixed {
		$value = $cell->value;
		if ($value === null) {
			return null;
		}

		$subtype = (string)($cell->column['subtype'] ?? '');
		return match ((string)($cell->column['type'] ?? 'text')) {
			'number' => $this->coerceNumber(value: $value, column: $cell->column),
			'selection' => $this->coerceSelection(value: $value, subtype: $subtype),
			'usergroup' => (array)$value,
			'relation' => $this->relationValue(cell: $cell, projection: $projection),
			default => $this->coerceText(value: $value),
		};
	}//end coerceCell()

	/**
	 * A number cell: an int for progress, stars and zero-decimal columns, else a float.
	 *
	 * @param mixed                $value  The raw value.
	 * @param array<string, mixed> $column The column descriptor.
	 *
	 * @return int|float
	 */
	private function coerceNumber(mixed $value, array $column): int|float {
		$subtype = (string)($column['subtype'] ?? '');
		if ($subtype === 'progress' || $subtype === 'stars' || $this->isIntegral(column: $column) === true) {
			return (int)$value;
		}

		return (float)$value;
	}//end coerceNumber()

	/**
	 * A selection cell: a bool for check, a list for multi, else a string.
	 *
	 * Tables stores check cells as the strings 'true'/'false'; a plain (bool)
	 * cast would turn 'false' into true.
	 *
	 * @param mixed  $value   The raw value.
	 * @param string $subtype The column subtype.
	 *
	 * @return mixed
	 */
	private function coerceSelection(mixed $value, string $subtype): mixed {
		return match ($subtype) {
			'check' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'multi' => (array)$value,
			default => (string)$value,
		};
	}//end coerceSelection()

	/**
	 * A text, datetime or unknown cell: an array stays as is, anything else becomes a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return mixed
	 */
	private function coerceText(mixed $value): mixed {
		if (is_array($value) === true) {
			return $value;
		}

		return (string)$value;
	}//end coerceText()

	/**
	 * Map a relation cell to the referenced virtual object's uuid, or fall back.
	 *
	 * @param TablesCell          $cell       The referenced rowId and the relation column (carries the target tableId).
	 * @param TablesRowProjection $projection Its targetSchemaExists predicate: does the target table have a seeded schema?
	 *
	 * @return string|int The derived uuid, or the raw integer rowId on fallback.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function relationValue(TablesCell $cell, TablesRowProjection $projection): string|int {
		[$targetTableId, $rowId] = [(int)($cell->column['relationTargetTableId'] ?? 0), (int)$cell->value];
		if ($targetTableId !== 0 && ($projection->targetSchemaExists)($targetTableId) === true) {
			return $this->uuidDeriver->deriveObjectUuid(tableId: $targetTableId, rowId: $rowId);
		}

		$this->logger->warning(
			sprintf(
				'[ObjectSource:tables] relation column %d target table %d has no seeded schema — falling back to raw rowId %d',
				(int)($cell->column['id'] ?? 0),
				$targetTableId,
				$rowId
			)
		);
		return $rowId;
	}//end relationValue()

	/**
	 * Build the JSON-schema fragment for a `number` column (design D5).
	 *
	 * @param array<string, mixed> $column The column descriptor.
	 * @param string $subtype The column subtype (progress/stars/...).
	 *
	 * @return array<string, mixed> The JSON-schema property fragment.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function numberProperty(array $column, string $subtype): array {
		if ($subtype === 'progress') {
			return ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];
		}

		if ($subtype === 'stars') {
			return ['type' => 'integer', 'minimum' => 0, 'maximum' => 5];
		}

		$property = ['type' => 'number'];
		if ($this->isIntegral(column: $column) === true) {
			$property['type'] = 'integer';
		}

		return $this->withBounds(property: $property, column: $column);
	}//end numberProperty()

	/**
	 * Add the column's numberMin / numberMax as minimum / maximum, when set.
	 *
	 * @param array<string, mixed> $property The property so far.
	 * @param array<string, mixed> $column   The column descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private function withBounds(array $property, array $column): array {
		if (array_key_exists('numberMin', $column) === true && $column['numberMin'] !== null) {
			$property['minimum'] = $column['numberMin'] + 0;
		}

		if (array_key_exists('numberMax', $column) === true && $column['numberMax'] !== null) {
			$property['maximum'] = $column['numberMax'] + 0;
		}

		return $property;
	}//end withBounds()

	/**
	 * Build the JSON-schema fragment for a `selection` column (design D5).
	 *
	 * @param array<string, mixed> $column The column descriptor.
	 * @param string $subtype The column subtype (check/multi/...).
	 *
	 * @return array<string, mixed> The JSON-schema property fragment.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	private function selectionProperty(array $column, string $subtype): array {
		if ($subtype === 'check') {
			return ['type' => 'boolean'];
		}

		$options = array_values(array_map('strval', (array)($column['selectionOptions'] ?? [])));

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
	private function dateFormat(string $subtype): string {
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
	private function isIntegral(array $column): bool {
		if (array_key_exists('numberDecimals', $column) === false || $column['numberDecimals'] === null) {
			return false;
		}

		return ((int)$column['numberDecimals']) === 0;
	}//end isIntegral()
}//end class
