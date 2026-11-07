<?php

/**
 * MagicRelationSql: the statements of the inverse-relation lookups over magic tables.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Build the statements that find what references a uuid or a linked entity.
 *
 * Every lookup of a relation carries the organisation filter it is given.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class MagicRelationSql {
	/**
	 * The most rows one lookup of a single uuid reads per table.
	 *
	 * @var int
	 */
	private const LOOKUP_LIMIT = 100;

	/**
	 * Condition matching a UUID anywhere in _relations, array or object format (PostgreSQL).
	 *
	 * Uses @> containment instead of ?, which would clash with PDO placeholders.
	 */
	private const CONTAINS_POSTGRES = "((jsonb_typeof(_relations)='array' AND _relations @> to_jsonb(?::text))"
		. " OR (jsonb_typeof(_relations)='object' AND EXISTS(SELECT 1 FROM jsonb_each_text(_relations) kv WHERE kv.value=?)))";

	/**
	 * Condition matching a UUID anywhere in _relations, array or object format (MySQL/MariaDB).
	 */
	private const CONTAINS_MYSQL = 'JSON_SEARCH(_relations, \'one\', ?) IS NOT NULL';

	/**
	 * Constructor.
	 *
	 * @param bool                       $isPostgres         Whether the platform is PostgreSQL.
	 * @param array{0: string, 1: array} $organisationFilter The filter's SQL fragment and its parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(private readonly bool $isPostgres, private readonly array $organisationFilter) {
	}//end __construct()

	/**
	 * The statement finding the rows of a table that reference one uuid.
	 *
	 * @param string $fullTableName The table, with its prefix.
	 * @param string $uuid          The uuid to look for.
	 *
	 * @return array{0: string, 1: list<mixed>} The statement and its parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function referencing(string $fullTableName, string $uuid): array {
		[$where, $params] = $this->where(conditions: [$this->contains()], params: $this->containsParams(uuid: $uuid));

		return ["SELECT * FROM {$fullTableName} WHERE {$where} LIMIT " . self::LOOKUP_LIMIT, $params];
	}//end referencing()

	/**
	 * The WHERE clause of a batch inverse-relation lookup.
	 *
	 * Matches rows whose _relations holds any of the UUIDs, or whose extra
	 * reference columns hold one.
	 *
	 * @param array $uuids       The UUIDs to look for.
	 * @param array $extraFields Property names whose columns may hold a reference directly.
	 *
	 * @return array{0: string, 1: list<mixed>} The WHERE clause and its bound parameters.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function batchWhere(array $uuids, array $extraFields): array {
		$conditions = [];
		$params     = [];
		foreach ($uuids as $uuid) {
			$conditions[] = $this->contains();
			array_push($params, ...$this->containsParams(uuid: $uuid));
		}

		foreach ($extraFields as $extraField) {
			$conditions = array_merge($conditions, array_fill(0, count($uuids), $this->referenceColumnCondition(field: $extraField)));
			array_push($params, ...$this->referenceColumnParams(uuids: $uuids));
		}

		return $this->where(conditions: $conditions, params: $params);
	}//end batchWhere()

	/**
	 * The statement finding the rows of a table linked to one entity.
	 *
	 * The linked type columns hold JSON arrays of string ids. The ::jsonb cast
	 * is for polymorphic columns; it would defeat a GIN index on a jsonb column.
	 *
	 * @param string $fullTableName The table, with its prefix.
	 * @param string $columnName    The linked type column.
	 *
	 * @return string The statement; its one parameter is the entity id.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function linkedTo(string $fullTableName, string $columnName): string {
		$linked = "JSON_SEARCH({$columnName}, 'one', ?) IS NOT NULL";
		$live   = '_deleted IS NULL';
		if ($this->isPostgres === true) {
			$linked = "{$columnName}::jsonb @> to_jsonb(?::text)";
			$live   = "(_deleted IS NULL OR _deleted::text = 'null')";
		}

		return "SELECT * FROM {$fullTableName} WHERE {$live} AND {$columnName} IS NOT NULL AND {$linked} LIMIT " . self::LOOKUP_LIMIT;
	}//end linkedTo()

	/**
	 * The live rows that meet any of the conditions, within the organisation filter.
	 *
	 * @param array $conditions The alternatives.
	 * @param array $params     Their parameters.
	 *
	 * @return array{0: string, 1: list<mixed>} The clause and its parameters.
	 */
	private function where(array $conditions, array $params): array {
		[$organisation, $organisationParams] = $this->organisationFilter;
		$live = '_deleted IS NULL';
		if ($this->isPostgres === true) {
			$live = "(_deleted IS NULL OR _deleted = 'null'::jsonb)";
		}

		return [
			$live . ' AND (' . implode(' OR ', $conditions) . ')' . $organisation,
			array_merge($params, $organisationParams),
		];
	}//end where()

	/**
	 * The condition matching one uuid in _relations.
	 *
	 * @return string The condition.
	 */
	private function contains(): string {
		if ($this->isPostgres === true) {
			return self::CONTAINS_POSTGRES;
		}

		return self::CONTAINS_MYSQL;
	}//end contains()

	/**
	 * The parameters of the condition for one uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return list<string> The uuid once per placeholder.
	 */
	private function containsParams(string $uuid): array {
		return array_fill(0, substr_count($this->contains(), '?'), $uuid);
	}//end containsParams()

	/**
	 * The condition matching a uuid stored directly in a reference column.
	 *
	 * Matches both a plain uuid string and a {"value": "uuid"} JSON object.
	 *
	 * @param string $field The property name.
	 *
	 * @return string The condition.
	 */
	private function referenceColumnCondition(string $field): string {
		$columnName = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
		$quoted     = (new MagicColumnSql(isPostgres: $this->isPostgres))->quote(name: $columnName);
		$text       = "CAST({$quoted} AS CHAR)";
		if ($this->isPostgres === true) {
			$text = "{$quoted}::text";
		}

		return "({$quoted} = ? OR {$text} LIKE ?)";
	}//end referenceColumnCondition()

	/**
	 * The parameters of one reference column's conditions, one pair per uuid.
	 *
	 * @param array $uuids The uuids.
	 *
	 * @return list<string> The parameters.
	 */
	private function referenceColumnParams(array $uuids): array {
		$params = [];
		foreach ($uuids as $uuid) {
			array_push($params, $uuid, '%' . $uuid . '%');
		}

		return $params;
	}//end referenceColumnParams()
}//end class
