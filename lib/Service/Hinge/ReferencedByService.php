<?php

/**
 * ReferencedByService — the reverse view: which records reference this object.
 *
 * An address, an asset or a licence is the thing several cases hinge on. The
 * relation store already knows which objects point at which; what nobody could
 * read was the other direction, grouped and summarised, so an address had no
 * history. This service answers that question without either schema being
 * taught about the other (D-1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers, for one object, which objects reference it, grouped by schema.
 *
 * Each group is one bounded query over that schema's own table (ADR-009), never
 * a walk over the relation graph. The caller's access is applied inside the
 * query — `_rbac` reaches MagicSearchHandler, which filters in SQL, so an
 * unreadable row is never loaded and the per-group total stays honest.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mappers for register, schema and objects.
 */
class ReferencedByService implements ReferencedByServiceInterface {

	/**
	 * Default number of referencing records returned per schema group.
	 */
	public const DEFAULT_GROUP_LIMIT = 10;

	/**
	 * Largest page a caller may ask for per schema group.
	 */
	public const MAX_GROUP_LIMIT = 100;

	/**
	 * Wire the mappers the reverse view reads through.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema lookups and the object tables.
	 * @param LoggerInterface        $logger  PSR logger for per-table failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the records that reference one object, grouped by schema.
	 *
	 * @param ObjectEntity $object The object being read as the hinge.
	 * @param array        $query  Paging: `_limit`, `_offset`, and `_schema` to page one group.
	 * @param bool         $_rbac  Apply the caller's access inside the query.
	 *
	 * @return array{object: array, groups: array<int, array>, total: int}
	 *                                                                    The grouped reverse view.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `_rbac` is the established
	 *   access-flag convention across the object layer (MagicSearchHandler,
	 *   PermissionHandlerInterface, ObjectService all take it); splitting this one method
	 *   in two would make it the exception rather than the rule.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getReferencingGroups(ObjectEntity $object, array $query = [], bool $_rbac = true): array {
		$page = new ReferencedByPage(
			targetUuid: (string)$object->getUuid(),
			limit: $this->resolveLimit(value: ($query['_limit'] ?? null)),
			offset: max(0, (int)($query['_offset'] ?? 0)),
			rbac: $_rbac
		);
		$groups = $this->groupsFor(page: $page, onlySchema: ($query['_schema'] ?? null));

		return ['object' => $this->describeObject(object: $object), 'groups' => $groups, 'total' => $this->totalOf(groups: $groups)];
	}//end getReferencingGroups()

	/**
	 * Every schema group that references the hinge, largest first.
	 *
	 * @param ReferencedByPage $page       The reference, page and access flag every group is read with.
	 * @param string|null      $onlySchema Restrict to one schema (id or slug) when given.
	 *
	 * @return array<int, array> The groups.
	 */
	private function groupsFor(ReferencedByPage $page, ?string $onlySchema): array {
		$groups = [];
		foreach ($this->catalog->tables()->getExistingRegisterSchemaTables() as $tableInfo) {
			$group = $this->readGroup(table: $this->resolveWantedTable(tableInfo: $tableInfo, onlySchema: $onlySchema), page: $page);
			if ($group !== null) {
				$groups[] = $group;
			}
		}

		usort($groups, static fn (array $left, array $right): int => ($right['total'] <=> $left['total']));

		return $groups;
	}//end groupsFor()

	/**
	 * The records referencing the hinge across every group.
	 *
	 * @param array<int, array> $groups The groups.
	 *
	 * @return int The total.
	 */
	private function totalOf(array $groups): int {
		$total = 0;
		foreach ($groups as $group) {
			$total += (int)$group['total'];
		}

		return $total;
	}//end totalOf()

	/**
	 * Identify the hinge object for the response.
	 *
	 * @param ObjectEntity $object The hinge.
	 *
	 * @return array{id: string, register: mixed, schema: mixed} The identification.
	 */
	private function describeObject(ObjectEntity $object): array {
		return [
			'id' => (string)$object->getUuid(),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
		];
	}//end describeObject()

	/**
	 * Read one schema group: its total and one page of summarised records.
	 *
	 * @param ReferencingTable|null $table The resolved table, or null when it is unresolvable or not wanted.
	 * @param ReferencedByPage      $page  The reference, page and access flag every group is read with.
	 *
	 * @return array|null The group, or null when it holds nothing or cannot be read.
	 */
	private function readGroup(?ReferencingTable $table, ReferencedByPage $page): ?array {
		if ($table === null) {
			return null;
		}

		$found = $this->queryGroup(table: $table, page: $page);
		if ($found === null) {
			return null;
		}

		$statusField = $this->resolveStatusField(schema: $table->schema);
		$results     = [];
		foreach ($this->referencingRecords(rows: $found['rows'], targetUuid: $page->targetUuid) as $record) {
			$results[] = $this->summarise(record: $record, statusField: $statusField);
		}

		return [
			'register' => $this->describe(entity: $table->register),
			'schema' => $this->describe(entity: $table->schema),
			'total' => $found['total'],
			'limit' => $page->limit,
			'offset' => $page->offset,
			'results' => $results,
		];
	}//end readGroup()

	/**
	 * Resolve a table's register and schema, keeping it only when it is the schema asked for.
	 *
	 * @param array       $tableInfo  The register+schema table descriptor.
	 * @param string|null $onlySchema Restrict to one schema (id or slug) when given.
	 *
	 * @return ReferencingTable|null The table, or null when unresolvable or not wanted.
	 */
	private function resolveWantedTable(array $tableInfo, ?string $onlySchema): ?ReferencingTable {
		$resolved = $this->resolveTable(tableInfo: $tableInfo);
		if ($resolved === null) {
			return null;
		}

		if ($this->isWanted(schema: $resolved->schema, onlySchema: $onlySchema) === false) {
			return null;
		}

		return $resolved;
	}//end resolveWantedTable()

	/**
	 * Whether a schema is in scope: every schema when none is asked for, else only the one asked for.
	 *
	 * @param Schema      $schema     The candidate schema.
	 * @param string|null $onlySchema The requested schema id, uuid or slug, when given.
	 *
	 * @return bool True when the schema's group is read.
	 */
	private function isWanted(Schema $schema, ?string $onlySchema): bool {
		if ($onlySchema === null) {
			return true;
		}

		return $this->matchesSchema(schema: $schema, wanted: $onlySchema);
	}//end isWanted()

	/**
	 * Resolve the register and schema a table belongs to.
	 *
	 * @param array $tableInfo The register+schema table descriptor.
	 *
	 * @return ReferencingTable|null The table, or null when its register or schema cannot be found.
	 */
	private function resolveTable(array $tableInfo): ?ReferencingTable {
		try {
			$register = $this->catalog->registers()->find($tableInfo['registerId']);
			$schema = $this->catalog->schemas()->find($tableInfo['schemaId']);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[ReferencedByService] Could not resolve register/schema for a table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => ($tableInfo['tableName'] ?? 'unknown'),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

		return new ReferencingTable(tableInfo: $tableInfo, register: $register, schema: $schema);
	}//end resolveTable()

	/**
	 * Count one group under the caller's access and, when it holds anything, load its page.
	 *
	 * @param ReferencingTable $table The table with its register and schema.
	 * @param ReferencedByPage $page  The reference, page and access flag to query with.
	 *
	 * @return array{total: int, rows: array}|null The total and rows, or null when empty or unreadable.
	 */
	private function queryGroup(ReferencingTable $table, ReferencedByPage $page): ?array {
		$filters  = $page->filters();
		$register = $table->register;
		$schema   = $table->schema;

		try {
			$groupTotal = $this->catalog->tables()->countObjectsInRegisterSchemaTable(
				query: $filters,
				register: $register,
				schema: $schema
			);

			if ($groupTotal === 0) {
				return null;
			}

			$rows = $this->catalog->tables()->findAllInRegisterSchemaTable(
				table: new RegisterSchema(register: $register, schema: $schema),
				listing: new ListQuery(limit: $page->limit, offset: $page->offset, filters: $filters, sort: ['_updated' => 'DESC'])
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[ReferencedByService] Reverse lookup failed for a table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => ($table->tableInfo['tableName'] ?? 'unknown'),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		return ['total' => $groupTotal, 'rows' => $rows];
	}//end queryGroup()

	/**
	 * The loaded rows that are referencing records: anything that is not a record and the hinge itself are left out.
	 *
	 * @param array  $rows       The rows one group's page returned.
	 * @param string $targetUuid The uuid of the object being read as the hinge.
	 *
	 * @return array<int, ObjectEntity> The referencing records.
	 */
	private function referencingRecords(array $rows, string $targetUuid): array {
		$records = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)$row->getUuid() === $targetUuid) {
				continue;
			}

			$records[] = $row;
		}

		return $records;
	}//end referencingRecords()

	/**
	 * Summarise one referencing record: what it is, where it stands, when it moved.
	 *
	 * @param ObjectEntity $record      The referencing record.
	 * @param string|null  $statusField The schema's declared lifecycle field, when it has one.
	 *
	 * @return array{id: string, title: string|null, status: string|null, updated: string|null}
	 *                                                                                         The summary.
	 */
	private function summarise(ObjectEntity $record, ?string $statusField): array {
		$updated = $record->getUpdated();
		$updatedFormatted = null;
		if ($updated !== null) {
			$updatedFormatted = $updated->format(\DateTimeInterface::ATOM);
		}

		$status = null;
		if ($statusField !== null) {
			$status = $this->statusOf(record: $record, statusField: $statusField);
		}

		return [
			'id' => (string)$record->getUuid(),
			'title' => $record->getName(),
			'status' => $status,
			'updated' => $updatedFormatted,
		];
	}//end summarise()

	/**
	 * The lifecycle status a record holds in its schema's status field.
	 *
	 * @param ObjectEntity $record      The referencing record.
	 * @param string       $statusField The schema's declared lifecycle field.
	 *
	 * @return string|null The status, or null when the record holds no scalar there.
	 */
	private function statusOf(ObjectEntity $record, string $statusField): ?string {
		$data = $record->getObject();
		if (is_array($data) === true && is_scalar(($data[$statusField] ?? null)) === true) {
			return (string)$data[$statusField];
		}

		return null;
	}//end statusOf()

	/**
	 * The property a schema keeps its status in, from its lifecycle annotation.
	 *
	 * @param Schema $schema The referencing record's schema.
	 *
	 * @return string|null The status property name, or null when the schema declares none.
	 */
	private function resolveStatusField(Schema $schema): ?string {
		$annotation = (($schema->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$field = ($annotation['field'] ?? ($annotation['property'] ?? null));
		if (is_string($field) === false || $field === '') {
			return null;
		}

		return $field;
	}//end resolveStatusField()

	/**
	 * Whether a schema is the one the caller asked to page.
	 *
	 * @param Schema $schema The candidate schema.
	 * @param string $wanted The requested schema id, uuid or slug.
	 *
	 * @return bool True when the schema matches.
	 */
	private function matchesSchema(Schema $schema, string $wanted): bool {
		$candidates = [
			(string)$schema->getId(),
			(string)$schema->getUuid(),
			(string)$schema->getSlug(),
		];

		return in_array($wanted, $candidates, true);
	}//end matchesSchema()

	/**
	 * Identify a register or schema for the response.
	 *
	 * @param Register|Schema $entity The entity to describe.
	 *
	 * @return array{id: string, slug: string|null, title: string|null} The identification.
	 */
	private function describe(Register|Schema $entity): array {
		return [
			'id' => (string)$entity->getId(),
			'slug' => $entity->getSlug(),
			'title' => $entity->getTitle(),
		];
	}//end describe()

	/**
	 * Clamp the requested page size to something a reverse view can serve.
	 *
	 * @param mixed $value The requested limit.
	 *
	 * @return int The page size to use.
	 */
	private function resolveLimit(mixed $value): int {
		if (is_numeric($value) === false) {
			return self::DEFAULT_GROUP_LIMIT;
		}

		$limit = (int)$value;
		if ($limit < 1) {
			return self::DEFAULT_GROUP_LIMIT;
		}

		return min($limit, self::MAX_GROUP_LIMIT);
	}//end resolveLimit()
}//end class
