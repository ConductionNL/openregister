<?php

/**
 * ExtendedByReads: who composes with a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads which schemas reference another in their allOf, oneOf or anyOf.
 *
 * @spec exclude schema read plumbing moved out of SchemaMapper, not a capability of its own
 */
final class ExtendedByReads {

	private const COMPOSITION_COLUMNS = ['all_of', 'one_of', 'any_of'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db        The database.
	 * @param string        $tableName The schema table.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly string $tableName,
	) {
	}//end __construct()

	/**
	 * The uuids of the schemas that reference any of the given identifiers in composition.
	 *
	 * @param array<int, string|null> $identifiers The id, uuid and slug of the target.
	 *
	 * @return array<int, mixed> Uuids, in row order.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function referencing(array $identifiers): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid')
			->from($this->tableName);

		$given = array_filter($identifiers, static fn (?string $value): bool => $value !== null && $value !== '');
		$conditions = $this->conditions(qb: $qb, identifiers: $given);
		if ($conditions === []) {
			return [];
		}

		$qb->where($qb->expr()->orX(...$conditions));
		$rows = $this->rows(qb: $qb);

		return array_values(array_filter(array_column($rows, 'uuid'), static fn (mixed $uuid): bool => $uuid !== null));
	}//end referencing()

	/**
	 * Find all schema extension relationships in a single query.
	 *
	 * Scans all schemas for allOf/oneOf/anyOf references and builds a reverse map
	 * of which schemas extend which. Replaces N individual findExtendedBy() calls
	 * with 1 query.
	 *
	 * @return array<int, string[]> Map of targetSchemaId => [extendingSchemaUuid, ...].
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function reverseMap(): array {
		$extending = $this->extendingRows();
		$lookup = $this->identifierLookup();

		$extendedByMap = [];
		foreach ($extending as $row) {
			foreach ($this->targetsOf(row: $row, lookup: $lookup) as $targetId) {
				$extendedByMap[$targetId][] = $row['uuid'];
			}
		}

		return array_map(static fn (array $uuids): array => array_values(array_unique($uuids)), $extendedByMap);
	}//end reverseMap()

	/**
	 * One LIKE per column and identifier, by column.
	 *
	 * @param IQueryBuilder      $qb          The query.
	 * @param array<int, string> $identifiers The non-empty identifiers.
	 *
	 * @return array<int, mixed>
	 */
	private function conditions(IQueryBuilder $qb, array $identifiers): array {
		$conditions = [];
		foreach (self::COMPOSITION_COLUMNS as $column) {
			foreach ($identifiers as $identifier) {
				$conditions[] = $qb->expr()->like($column, $qb->createNamedParameter('%"' . $identifier . '"%'));
			}
		}

		return $conditions;
	}//end conditions()

	/**
	 * The rows that carry any composition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extendingRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uuid', 'slug', 'all_of', 'one_of', 'any_of')
			->from($this->tableName)
			->where(
				$qb->expr()->orX(
					$qb->expr()->isNotNull('all_of'),
					$qb->expr()->isNotNull('one_of'),
					$qb->expr()->isNotNull('any_of')
				)
			);

		return array_filter($this->rows(qb: $qb), static fn (array $row): bool => ($row['uuid'] ?? null) !== null);
	}//end extendingRows()

	/**
	 * Every schema id, keyed by each identifier that reaches it.
	 *
	 * @return array<string, int>
	 */
	private function identifierLookup(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uuid', 'slug')
			->from($this->tableName);

		$lookup = [];
		foreach ($this->rows(qb: $qb) as $row) {
			foreach (array_filter([(string)$row['id'], $row['uuid'] ?? null, $row['slug'] ?? null], 'is_string') as $identifier) {
				$lookup[$identifier] = (int)$row['id'];
			}
		}

		return $lookup;
	}//end identifierLookup()

	/**
	 * The ids of the schemas a composition row references.
	 *
	 * 🔴 THROUGH ParentReference, NOT `(string)$entry`. An `allOf` entry is a
	 * SCHEMA OBJECT in standard JSON Schema (`{"$ref": "person"}`), and casting
	 * one to string yields the literal "Array", which matches no key in the
	 * lookup. Measured across the fleet's descriptors: 236 allOf/oneOf/anyOf
	 * entries, ALL of them objects, NOT ONE a bare string.
	 *
	 * @param array<string, mixed> $row    The composition row.
	 * @param array<string, int>   $lookup The identifier lookup.
	 *
	 * @return array<int, int>
	 */
	private function targetsOf(array $row, array $lookup): array {
		$targets = [];
		foreach ($this->referencesOf(row: $row) as $entry) {
			$identifier = (new ParentReference())->fromEntry(entry: $entry);
			if ($identifier !== null && isset($lookup[(string)$identifier]) === true) {
				$targets[] = $lookup[(string)$identifier];
			}
		}

		return $targets;
	}//end targetsOf()

	/**
	 * The decoded entries of a row's composition columns.
	 *
	 * @param array<string, mixed> $row The composition row.
	 *
	 * @return array<mixed>
	 */
	private function referencesOf(array $row): array {
		$references = [];
		foreach (self::COMPOSITION_COLUMNS as $column) {
			$decoded = json_decode(json: ($row[$column] ?? 'null'), associative: true);
			if (is_array($decoded) === true) {
				$references = array_merge($references, $decoded);
			}
		}

		return $references;
	}//end referencesOf()

	/**
	 * All rows of a query, with the cursor closed.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = [];
		for ($row = $result->fetch(); $row !== false; $row = $result->fetch()) {
			$rows[] = $row;
		}

		$result->closeCursor();

		return $rows;
	}//end rows()
}//end class
