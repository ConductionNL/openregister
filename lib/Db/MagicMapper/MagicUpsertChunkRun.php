<?php

/**
 * MagicUpsertChunkRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The state of one bulk upsert chunk: the rows to write, their columns and
 * uuids, and which uuids existed before the write.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MagicUpsertChunkRun {

	/**
	 * The table name with the configured prefix.
	 *
	 * @var string
	 */
	public string $fullTableName = '';

	/**
	 * Whether the connection is PostgreSQL.
	 *
	 * @var boolean
	 */
	public bool $isPostgres = false;

	/**
	 * Whether callers read the pre-update rows; false narrows the existence check to the uuid.
	 *
	 * @var boolean
	 */
	public bool $needsPreUpdateState = true;

	/**
	 * When the operation started (Y-m-d H:i:s), to tell an update from an unchanged row.
	 *
	 * @var string
	 */
	public string $operationStartTime = '';

	/**
	 * The number of objects the chunk was handed, before filtering.
	 *
	 * @var integer
	 */
	public int $objectCount = 0;

	/**
	 * The rows to write, filtered to the table's columns and deduplicated by uuid.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every column any row carries, in first-seen order.
	 *
	 * @var array<int, string>
	 */
	public array $columns = [];

	/**
	 * The uuids of the rows.
	 *
	 * @var array<int, mixed>
	 */
	public array $uuids = [];

	/**
	 * The uuids that existed before the write, as keys.
	 *
	 * @var array<string, true>
	 */
	public array $existingUuids = [];

	/**
	 * The full pre-update rows by uuid, when the caller reads them.
	 *
	 * @var array<string, array>
	 */
	public array $preUpdateRows = [];

	/**
	 * Constructor.
	 *
	 * @param string $tableName   The bare table name.
	 * @param int    $chunkNumber The chunk number, for logging.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly int $chunkNumber,
	) {
	}//end __construct()

	/**
	 * A column list quoted for the platform.
	 *
	 * @return string The quoted, comma-separated columns.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function quotedColumnList(): string {
		if ($this->isPostgres === true) {
			return '"' . implode('", "', $this->columns) . '"';
		}

		return '`' . implode('`, `', $this->columns) . '`';
	}//end quotedColumnList()

	/**
	 * A SELECT of the given columns of every row of this chunk, by uuid.
	 *
	 * @param string $columns The select list.
	 *
	 * @return string The SQL, with one positional placeholder per uuid.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function selectByUuidSql(string $columns): string {
		$placeholders = implode(',', array_fill(0, count($this->uuids), '?'));
		if ($this->isPostgres === true) {
			return "SELECT {$columns} FROM \"{$this->fullTableName}\" WHERE \"_uuid\" IN ({$placeholders})";
		}

		return "SELECT {$columns} FROM `{$this->fullTableName}` WHERE `_uuid` IN ({$placeholders})";
	}//end selectByUuidSql()

	/**
	 * Collect every column any row carries, in first-seen order (so a property
	 * present in some rows but not others is still written), and the row uuids.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function collectColumnsAndUuids(): void {
		$this->columns = [];
		foreach ($this->rows as $objectData) {
			foreach (array_keys($objectData) as $column) {
				if (in_array($column, $this->columns, true) === false) {
					$this->columns[] = $column;
				}
			}
		}

		$this->uuids = array_column($this->rows, '_uuid');
	}//end collectColumnsAndUuids()

	/**
	 * The VALUES clause of the upsert and its named parameters.
	 *
	 * Booleans are bound as 0/1: execute() binds every parameter as PARAM_STR,
	 * which turns `false` into '', and PostgreSQL refuses '' for a boolean column.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The clause and its parameters.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function valuesAndParameters(): array {
		$valuesClause = [];
		$parameters = [];
		foreach ($this->rows as $objectData) {
			$row = $this->rowValues(objectData: $objectData, offset: count($parameters));
			$valuesClause[] = $row[0];
			$parameters += $row[1];
		}

		return [implode(',', $valuesClause), $parameters];
	}//end valuesAndParameters()

	/**
	 * The VALUES tuple of one row and its named parameters.
	 *
	 * @param array<string, mixed> $objectData The row.
	 * @param int                  $offset     The number of parameters bound by earlier rows.
	 *
	 * @return array{0: string, 1: array<string, mixed>} The tuple and its parameters.
	 */
	private function rowValues(array $objectData, int $offset): array {
		$placeholders = [];
		$parameters = [];
		foreach ($this->columns as $column) {
			$paramName = 'p' . ($offset + count($parameters));
			$placeholders[] = ':' . $paramName;
			$parameters[$paramName] = $this->bindable(value: $objectData[$column] ?? null);
		}

		return ['(' . implode(',', $placeholders) . ')', $parameters];
	}//end rowValues()

	/**
	 * A value as it is bound: booleans become 0/1.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The bindable value.
	 */
	private function bindable(mixed $value): mixed {
		if (is_bool($value) === true) {
			return (int) $value;
		}

		return $value;
	}//end bindable()

	/**
	 * Mark one read-back row created, updated or unchanged.
	 *
	 * A uuid that did not exist before is `created`; one that did is `updated`
	 * when its `_updated` is at or after the operation start, else `unchanged`.
	 * An updated row carries its pre-update row for the audit changeset.
	 *
	 * @param array<string, mixed> $obj The row as read back.
	 *
	 * @return array<string, mixed> The row with `object_status` and `operation_start_time`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function classify(array $obj): array {
		$objUuid = $obj['_uuid'] ?? null;
		$obj['object_status'] = 'created';
		if (isset($this->existingUuids[$objUuid]) === true) {
			$obj = $this->classifyExisting(obj: $obj, objUuid: $objUuid);
		}

		$obj['operation_start_time'] = $this->operationStartTime;
		return $obj;
	}//end classify()

	/**
	 * Mark a read-back row whose uuid existed before the write updated or unchanged.
	 *
	 * @param array<string, mixed> $obj     The row as read back.
	 * @param mixed                $objUuid The row's uuid.
	 *
	 * @return array<string, mixed> The row with `object_status`, and `_pre_update_row` when updated.
	 */
	private function classifyExisting(array $obj, mixed $objUuid): array {
		$updatedTime = $obj['_updated'] ?? null;
		$obj['object_status'] = 'unchanged';
		if ($updatedTime === null || ($updatedTime >= $this->operationStartTime) === false) {
			return $obj;
		}

		$obj['object_status'] = 'updated';
		if (isset($this->preUpdateRows[$objUuid]) === true) {
			$obj['_pre_update_row'] = $this->preUpdateRows[$objUuid];
		}

		return $obj;
	}//end classifyExisting()
}//end class
