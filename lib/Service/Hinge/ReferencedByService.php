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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
class ReferencedByService {

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
	 * @param MagicMapper     $magicMapper    Object storage, one table per register+schema.
	 * @param RegisterMapper  $registerMapper Register lookup for each candidate table.
	 * @param SchemaMapper    $schemaMapper   Schema lookup for each candidate table.
	 * @param LoggerInterface $logger         PSR logger for per-table failures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
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
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function getReferencingGroups(ObjectEntity $object, array $query = [], bool $_rbac = true): array {
		$targetUuid = (string)$object->getUuid();
		$limit = $this->resolveLimit(value: ($query['_limit'] ?? null));
		$offset = max(0, (int)($query['_offset'] ?? 0));
		$onlySchema = $query['_schema'] ?? null;

		$groups = [];
		$total = 0;

		foreach ($this->magicMapper->getExistingRegisterSchemaTables() as $tableInfo) {
			$group = $this->readGroup(
				tableInfo: $tableInfo,
				targetUuid: $targetUuid,
				limit: $limit,
				offset: $offset,
				onlySchema: $onlySchema,
				_rbac: $_rbac
			);

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
	 * @param array       $tableInfo  The register+schema table descriptor.
	 * @param string      $targetUuid The uuid every returned record references.
	 * @param int         $limit      Page size for this group.
	 * @param int         $offset     Page offset for this group.
	 * @param string|null $onlySchema Restrict to one schema (id or slug) when given.
	 * @param bool        $_rbac      Apply the caller's access inside the query.
	 *
	 * @return array|null The group, or null when it holds nothing or cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One page of one group needs all six.
	 */
	private function readGroup(
		array $tableInfo,
		string $targetUuid,
		int $limit,
		int $offset,
		?string $onlySchema,
		bool $_rbac,
	): ?array {
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

		if ($onlySchema !== null && $this->matchesSchema(schema: $schema, wanted: $onlySchema) === false) {
			return null;
		}

		$filters = [
			'_relations_contains' => $targetUuid,
			'_rbac' => $_rbac,
		];

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
				limit: $limit,
				offset: $offset,
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

		$statusField = $this->resolveStatusField(schema: $schema);
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

		return [
			'register' => $this->describe(entity: $register),
			'schema' => $this->describe(entity: $schema),
			'total' => $groupTotal,
			'limit' => $limit,
			'offset' => $offset,
			'results' => $records,
		];
	}//end readGroup()

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
			$data = $record->getObject();
			if (is_array($data) === true && is_scalar(($data[$statusField] ?? null)) === true) {
				$status = (string)$data[$statusField];
			}
		}

		return [
			'id' => (string)$record->getUuid(),
			'title' => $record->getName(),
			'status' => $status,
			'updated' => $updatedFormatted,
		];
	}//end summarise()

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
