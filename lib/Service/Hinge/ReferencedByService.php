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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
	 * @param MagicMapperInterface     $magicMapper    Object storage, one table per register+schema.
	 * @param RegisterMapperInterface  $registerMapper Register lookup for each candidate table.
	 * @param SchemaMapperInterface    $schemaMapper   Schema lookup for each candidate table.
	 * @param LoggerInterface $logger         PSR logger for per-table failures.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $magicMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
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
		$targetUuid = (string)$object->getUuid();
		$limit = $this->resolveLimit(value: ($query['_limit'] ?? null));
		$offset = max(0, (int)($query['_offset'] ?? 0));
		$onlySchema = $query['_schema'] ?? null;

		$groups = [];
		$total = 0;

		$page = new ReferencedByPage(
			targetUuid: $targetUuid,
			limit: $limit,
			offset: $offset,
			rbac: $_rbac
		);

		foreach ($this->magicMapper->getExistingRegisterSchemaTables() as $tableInfo) {
			$group = $this->readGroup(tableInfo: $tableInfo, page: $page, onlySchema: $onlySchema);

			if ($group === null) {
				continue;
			}

			$groups[] = $group;
			$total += (int)$group['total'];
		}

		usort($groups, static fn (array $left, array $right): int => ($right['total'] <=> $left['total']));

		return [
			'object' => [
				'id' => $targetUuid,
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
			],
			'groups' => $groups,
			'total' => $total,
		];
	}//end getReferencingGroups()

	/**
	 * Read one schema group: its total and one page of summarised records.
	 *
	 * @param array            $tableInfo  The register+schema table descriptor.
	 * @param ReferencedByPage $page       The reference, page and access flag every group is read with.
	 * @param string|null      $onlySchema Restrict to one schema (id or slug) when given.
	 *
	 * @return array|null The group, or null when it holds nothing or cannot be read.
	 */
	private function readGroup(array $tableInfo, ReferencedByPage $page, ?string $onlySchema): ?array {
		$resolved = $this->resolveWantedTable(tableInfo: $tableInfo, onlySchema: $onlySchema);
		if ($resolved === null) {
			return null;
		}

		$register = $resolved['register'];
		$schema = $resolved['schema'];

		$found = $this->queryGroup(tableInfo: $tableInfo, register: $register, schema: $schema, page: $page);
		if ($found === null) {
			return null;
		}

		return [
			'register' => $this->describe(entity: $register),
			'schema' => $this->describe(entity: $schema),
			'total' => $found['total'],
			'limit' => $page->limit,
			'offset' => $page->offset,
			'results' => $this->summariseRows(
				rows: $found['rows'],
				targetUuid: $page->targetUuid,
				statusField: $this->resolveStatusField(schema: $schema)
			),
		];
	}//end readGroup()

	/**
	 * Resolve a table's register and schema, keeping it only when it is the schema asked for.
	 *
	 * @param array       $tableInfo  The register+schema table descriptor.
	 * @param string|null $onlySchema Restrict to one schema (id or slug) when given.
	 *
	 * @return array{register: Register, schema: Schema}|null The pair, or null when unresolvable or not wanted.
	 */
	private function resolveWantedTable(array $tableInfo, ?string $onlySchema): ?array {
		$resolved = $this->resolveTable(tableInfo: $tableInfo);
		if ($resolved === null) {
			return null;
		}

		if ($this->isWanted(schema: $resolved['schema'], onlySchema: $onlySchema) === false) {
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
	 * @return array{register: Register, schema: Schema}|null The pair, or null when either cannot be found.
	 */
	private function resolveTable(array $tableInfo): ?array {
		try {
			$register = $this->registerMapper->find($tableInfo['registerId']);
			$schema = $this->schemaMapper->find($tableInfo['schemaId']);
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

		return ['register' => $register, 'schema' => $schema];
	}//end resolveTable()

	/**
	 * Count one group under the caller's access and, when it holds anything, load its page.
	 *
	 * @param array            $tableInfo The register+schema table descriptor.
	 * @param Register         $register  The table's register.
	 * @param Schema           $schema    The table's schema.
	 * @param ReferencedByPage $page      The reference, page and access flag to query with.
	 *
	 * @return array{total: int, rows: array}|null The total and rows, or null when empty or unreadable.
	 */
	private function queryGroup(array $tableInfo, Register $register, Schema $schema, ReferencedByPage $page): ?array {
		$filters = $page->filters();

		try {
			$groupTotal = $this->magicMapper->countObjectsInRegisterSchemaTable(
				query: $filters,
				register: $register,
				schema: $schema
			);

			if ($groupTotal === 0) {
				return null;
			}

			$rows = $this->magicMapper->findAllInRegisterSchemaTable(
				register: $register,
				schema: $schema,
				limit: $page->limit,
				offset: $page->offset,
				filters: $filters,
				sort: ['_updated' => 'DESC']
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[ReferencedByService] Reverse lookup failed for a table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => ($tableInfo['tableName'] ?? 'unknown'),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		return ['total' => $groupTotal, 'rows' => $rows];
	}//end queryGroup()

	/**
	 * Summarise the loaded rows, leaving out anything that is not a record and the hinge itself.
	 *
	 * @param array       $rows        The rows one group's page returned.
	 * @param string      $targetUuid  The uuid of the object being read as the hinge.
	 * @param string|null $statusField The schema's declared lifecycle field, when it has one.
	 *
	 * @return array<int, array> The summaries.
	 */
	private function summariseRows(array $rows, string $targetUuid, ?string $statusField): array {
		$records = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)$row->getUuid() === $targetUuid) {
				continue;
			}

			$records[] = $this->summarise(record: $row, statusField: $statusField);
		}

		return $records;
	}//end summariseRows()

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
