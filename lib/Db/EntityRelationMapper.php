<?php

/**
 * Mapper for entity relations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DecisionConflictException;
use OCA\OpenRegister\Files\Db\EntityMention;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\RelationDecisionUpdate;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class EntityRelationMapper
 *
 * @method EntityRelation insert(Entity $entity)
 * @method EntityRelation update(Entity $entity)
 * @method EntityRelation insertOrUpdate(Entity $entity)
 * @method EntityRelation delete(Entity $entity)
 * @method EntityRelation find(int|string $id)
 * @method EntityRelation findEntity(IQueryBuilder $query)
 * @method EntityRelation[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<EntityRelation> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<EntityRelation>
 */
class EntityRelationMapper extends QBMapper implements EntityRelationMapperInterface {

	/**
	 * The relation and entity columns a per-file entity read returns.
	 */
	private const FILE_ENTITY_COLUMNS = [
		'r.id as relation_id',
		'r.entity_id',
		'r.position_start',
		'r.position_end',
		'r.confidence',
		'e.type as entity_type',
		'e.value as entity_value',
		'e.category',
	];

	/**
	 * The optional fields buildRelationFromRow() sets from a row, in order:
	 * [setter, cast, whether a null value leaves the field unset].
	 */
	private const ROW_FIELDS = [
		'fileId' => ['setFileId', 'int', true],
		'chunkId' => ['setChunkId', 'int', true],
		'objectId' => ['setObjectId', 'int', true],
		'emailId' => ['setEmailId', 'int', true],
		'positionStart' => ['setPositionStart', 'int', false],
		'positionEnd' => ['setPositionEnd', 'int', false],
		'confidence' => ['setConfidence', 'float', false],
		'detectionMethod' => ['setDetectionMethod', 'string', true],
		'context' => ['setContext', '?string', false],
		'role' => ['setRole', '?string', false],
		'anonymized' => ['setAnonymized', 'bool', false],
		'skipAnonymization' => ['setSkipAnonymization', 'bool', false],
		'bases' => ['setBases', 'raw', false],
	];

	/**
	 * Maximum number of expressions allowed in a single SQL IN() list.
	 *
	 * Nextcloud's QueryBuilder refuses more than 1000 expressions in an IN()
	 * list (Oracle limit); exceeding it logs an error and emits an "Undefined
	 * array key 0" PHP warning. Batched id lookups must chunk below this bound.
	 *
	 * @var integer
	 */
	private const MAX_IN_LIST_SIZE = 1000;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit-trail persistence (used by updateDecisionMetadata).
	 * @param IUserSession $userSession Session user lookup for audit-trail actor.
	 * @param EntityEventsInterface $entityEvents Announces the decision-metadata write to listeners
	 *                                           (used by updateDecisionMetadata).
	 * @param LoggerInterface $logger Structured log sink.
	 */
	public function __construct(
		IDBConnection $db,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly EntityEventsInterface $entityEvents,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(db: $db, tableName: 'openregister_entity_relations', entityClass: EntityRelation::class);
	}//end __construct()

	/**
	 * Find a single entity relation by its primary id.
	 *
	 * QBMapper exposes `find()` via a `@method` docblock only; concrete
	 * mappers add it themselves when needed. This implementation wraps the
	 * inherited protected `findEntity()` so HTTP/DI callers get a typed
	 * 404 path (`DoesNotExistException`) for unknown ids.
	 *
	 * @param int $id The relation row id.
	 *
	 * @return EntityRelation The matching row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When $id does not resolve.
	 */
	public function find(int $id): EntityRelation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/*
		 * @var EntityRelation
		 */

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find entity relations by file ID.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 */
	public function findByFileId(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities(query: $qb);
	}//end findByFileId()

	/**
	 * Find entity relations by entity ID.
	 *
	 * @param int $entityId The entity ID.
	 *
	 * @return EntityRelation[] Array of entity relations.
	 */
	public function findByEntityId(int $entityId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities(query: $qb);
	}//end findByEntityId()

	/**
	 * Find entity relations with entity details by file ID.
	 *
	 * Returns entity relations joined with entity data for anonymization.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array Array of entity data with type, value, and relation info.
	 */
	public function findEntitiesForFile(int $fileId): array {
		$qb = $this->selectFileEntities(fileId: $fileId);
		$qb->orderBy('r.position_start', 'ASC');

		$result = $qb->executeQuery();
		$entities = $result->fetchAll();
		$result->closeCursor();

		return $entities;
	}//end findEntitiesForFile()

	/**
	 * Find anonymised entity relations with the full decision-metadata + entity context for a file.
	 *
	 * Differs from {@see findEntitiesForFile} in two ways:
	 *
	 *   1. Filters to rows where `r.anonymized = true` — the caller wants
	 *      the "what was actually redacted" set, not the "what was detected" set.
	 *   2. Selects `r.bases`, `r.skip_anonymization`, `r.anonymized`,
	 *      `r.anonymized_value` so downstream renderers (DocuDesk's
	 *      `anonymisation-grondslagen-summary` change) can produce a
	 *      grondslagen-traceable audit page without an N+1 of `find($id)`
	 *      calls.
	 *
	 * Returned rows are flat associative arrays. `bases` is JSON-decoded
	 * into an `array` (or null if the column is null in the DB).
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return array<int, array<string, mixed>> Rows shaped as
	 *                                          `{relation_id, entity_id, position_start, position_end, confidence,
	 *                                          entity_type, entity_value, category, bases (array|null),
	 *                                          skip_anonymization (bool), anonymized (bool), anonymized_value (string|null)}`.
	 */
	public function findAnonymisedEntitiesWithBasesForFile(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(
			'r.id as relation_id',
			'r.entity_id',
			'r.position_start',
			'r.position_end',
			'r.confidence',
			'r.bases',
			'r.skip_anonymization',
			'r.anonymized',
			'r.anonymized_value',
			'e.type as entity_type',
			'e.value as entity_value',
			'e.category'
		)
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('r.anonymized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				)
			)
			->orderBy('r.position_start', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $index => $row) {
			$rows[$index] = $this->normaliseAnonymisedRow(row: $row);
		}

		return $rows;
	}//end findAnonymisedEntitiesWithBasesForFile()

	/**
	 * Decode the `bases` column and the boolean flags of one anonymised-entity row.
	 *
	 * Doctrine returns `bases` as a JSON string; callers expect an array, or null
	 * when no bases were recorded or the value does not decode to an array. Some
	 * DB drivers return the flags as integers or strings.
	 *
	 * @param array<string, mixed> $row The raw row.
	 *
	 * @return array<string, mixed> The normalised row.
	 */
	private function normaliseAnonymisedRow(array $row): array {
		if (isset($row['bases']) === true && is_string($row['bases']) === true) {
			$row['bases'] = $this->decodeBasesColumn(json: $row['bases']);
		}

		// Normalise boolean-as-int values returned by some DB drivers.
		foreach (['skip_anonymization', 'anonymized'] as $flag) {
			if (isset($row[$flag]) === true) {
				$row[$flag] = (bool)$row[$flag];
			}
		}

		return $row;
	}//end normaliseAnonymisedRow()

	/**
	 * The decoded `bases` column, or null when it is not a JSON array or object.
	 *
	 * @param string $json The stored column value.
	 *
	 * @return array|null The decoded bases.
	 */
	private function decodeBasesColumn(string $json): ?array {
		$decoded = json_decode($json, associative: true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decodeBasesColumn()

	/**
	 * Build a `(entity_value → {id, type})` map for every entity relation on a file.
	 *
	 * Used by `DocumentProcessingHandler::anonymizeDocument` to look up the
	 * stable `entity_id` for each entity occurrence so the in-file
	 * placeholder format `[<TYPE>: <entity_id>]` matches what downstream
	 * consumers (DocuDesk's grondslagen-summary report) display. Without
	 * this lookup the anonymise step generates a fresh UUID-prefix per
	 * call, and the report's view of "what's in the file" diverges from
	 * the actual substitution.
	 *
	 * Returns a single entry per distinct `entity_value` on the file.
	 * When the same value is detected as multiple `entity_types`, the
	 * first one encountered wins — callers should use their own
	 * `entityType` to discriminate at the substitution site.
	 *
	 * @param int $fileId The Nextcloud file ID whose entity relations are mapped.
	 *
	 * @return array<string, array{id: int, type: string}> Map keyed by entity value.
	 */
	public function findEntityIdsByValueForFile(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['e.id', 'e.type', 'e.value'])
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$map = [];
		foreach ($rows as $row) {
			$value = (string)($row['value'] ?? '');
			if ($value === '' || isset($map[$value]) === true) {
				continue;
			}

			$map[$value] = [
				'id' => (int)($row['id'] ?? 0),
				'type' => (string)($row['type'] ?? ''),
			];
		}

		return $map;
	}//end findEntityIdsByValueForFile()

	/**
	 * Load the entity-relation rows for several files in one query — the
	 * multi-file sibling of `findEntityIdsByValueForFile`.
	 *
	 * Used by the per-dossier placeholder numbering (Decision 3): the dossier
	 * number is recomputed as a pure function of the dossier's stored rows, so
	 * this returns the raw `(entity_id, file_id, position_start)` tuples for
	 * every relation on the given files. The caller (`PlaceholderIdTranslator`)
	 * imposes the total stable order `(file_id, position_start, entity_id)` and
	 * ranks distinct `entity_id`s by first appearance. No join to
	 * `openregister_entities` is needed — the relation row already carries the
	 * `entity_id` that is the numbering key.
	 *
	 * @param array<int, int> $fileIds The Nextcloud file IDs of the dossier's files.
	 *
	 * @return array<int, array{entity_id: int, file_id: int, position_start: int}>
	 *                                                                              One record per relation row across all given files (unordered;
	 *                                                                              the caller imposes the ranking order).
	 */
	public function findEntityIdsByValueForFiles(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}

		$ids = array_values(array_unique(array_map('intval', $fileIds)));

		// Chunk below the 1000-expression IN() ceiling (Oracle limit enforced by
		// Nextcloud's QueryBuilder): a dossier can legitimately hold more than
		// 1000 files.
		$rows = [];
		foreach (array_chunk($ids, self::MAX_IN_LIST_SIZE) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('entity_id', 'file_id', 'position_start')
				->from($this->getTableName())
				->where(
					$qb->expr()->in(
						'file_id',
						$qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
					)
				);

			$result = $qb->executeQuery();
			$rows = array_merge($rows, $result->fetchAll());
			$result->closeCursor();
		}

		$records = [];
		foreach ($rows as $row) {
			$records[] = [
				'entity_id' => (int)($row['entity_id'] ?? 0),
				'file_id' => (int)($row['file_id'] ?? 0),
				'position_start' => (int)($row['position_start'] ?? 0),
			];
		}

		return $records;
	}//end findEntityIdsByValueForFiles()

	/**
	 * Probe whether a relation already exists at a specific position
	 * for a (file, entity) pair.
	 *
	 * Used by `ManualEntityService` to make manual-entity adds
	 * idempotent: re-calling the endpoint for the same value on the
	 * same file does NOT create duplicate relation rows. The dedup key
	 * is the full (fileId, entityId, chunkId, positionStart, positionEnd)
	 * tuple — same positions across different entities (e.g. position
	 * 142 has both `"Jan Jansen"` PERSON and `"Jansen"` PERSON entities)
	 * are legitimately distinct rows.
	 *
	 * @param int           $fileId  Nextcloud file id.
	 * @param EntityMention $mention The entity, chunk and span within the chunk.
	 *
	 * @return bool True when a row with exactly these five values exists.
	 */
	public function existsForFileAtPosition(int $fileId, EntityMention $mention): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('entity_id', $qb->createNamedParameter($mention->entityId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('chunk_id', $qb->createNamedParameter($mention->chunkId, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq(
						'position_start',
						$qb->createNamedParameter($mention->positionStart, IQueryBuilder::PARAM_INT)
					),
					$qb->expr()->eq(
						'position_end',
						$qb->createNamedParameter($mention->positionEnd, IQueryBuilder::PARAM_INT)
					)
				)
			)
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return ($row !== false);
	}//end existsForFileAtPosition()

	/**
	 * Insert multiple relation rows in a single pass.
	 *
	 * The caller is expected to manage the surrounding transaction
	 * (`IDBConnection::beginTransaction()` / `commit()` / `rollBack()`).
	 * `ManualEntityService` does this so the entity-create + batch
	 * relation insert + audit-trail write all commit atomically.
	 *
	 * Each input row is an associative array carrying the fields the
	 * caller wants to persist. Recognised keys (all optional unless
	 * marked):
	 *
	 *   - `entityId`          (required, int)
	 *   - `fileId`            (int|null)
	 *   - `chunkId`           (int|null)
	 *   - `objectId`          (int|null)
	 *   - `emailId`           (int|null)
	 *   - `positionStart`     (int)
	 *   - `positionEnd`       (int)
	 *   - `confidence`        (float)
	 *   - `detectionMethod`   (string)
	 *   - `context`           (string|null)
	 *   - `role`              (string|null)
	 *   - `anonymized`        (bool)
	 *   - `skipAnonymization` (bool)
	 *   - `bases`             (array|null)
	 *   - `createdAt`         (DateTime, defaults to now)
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to insert.
	 *
	 * @return EntityRelation[] The inserted entities with their generated ids,
	 *                          in the same order as the input.
	 *
	 * @throws \Throwable Any DB error is re-thrown verbatim so the caller's
	 *                    transaction can roll back.
	 */
	public function insertBatch(array $rows): array {
		$inserted = [];
		foreach ($rows as $row) {
			$relation = $this->buildRelationFromRow(row: $row);
			$inserted[] = $this->insert(entity: $relation);
		}

		return $inserted;
	}//end insertBatch()

	/**
	 * Materialise an EntityRelation entity from a raw row array.
	 *
	 * Pulled out of `insertBatch` so the field-by-field setter mapping
	 * lives in one place. Unknown keys are silently ignored; missing
	 * required key (`entityId`) trigger an exception via the setter
	 * type-hint. The `openregister_entity_relations` table has no uuid
	 * column — relations are identified by their auto-increment `id`.
	 *
	 * @param array<string, mixed> $row Field values keyed by camelCase setter name.
	 *
	 * @return EntityRelation Populated, ready to insert.
	 */
	private function buildRelationFromRow(array $row): EntityRelation {
		$relation = new EntityRelation();
		$relation->setEntityId((int)$row['entityId']);
		// phpcs:disable CustomSniffs.Functions.NamedParameters -- Entity __call breaks with named args.
		foreach (self::ROW_FIELDS as $field => [$setter, $cast]) {
			if ($this->rowFieldIsSkipped(row: $row, field: $field) === true) {
				continue;
			}

			$relation->$setter($this->castRowValue(value: $row[$field], cast: $cast));
		}

		// phpcs:enable
		$relation->setCreatedAt(new DateTime());
		if (($row['createdAt'] ?? null) !== null) {
			$relation->setCreatedAt($row['createdAt']);
		}

		return $relation;
	}//end buildRelationFromRow()

	/**
	 * Whether a row field is left unset: it is absent, or null where ROW_FIELDS says null means "skip".
	 *
	 * @param array<string, mixed> $row Field values keyed by camelCase setter name.
	 * @param string $field A ROW_FIELDS key.
	 *
	 * @return bool True when the setter is not called.
	 */
	private function rowFieldIsSkipped(array $row, string $field): bool {
		return array_key_exists($field, $row) === false || (self::ROW_FIELDS[$field][2] === true && $row[$field] === null);
	}//end rowFieldIsSkipped()

	/**
	 * A row value cast for its field.
	 *
	 * @param mixed $value The value.
	 * @param string $cast One of int, float, string, ?string, bool or raw.
	 *
	 * @return mixed
	 */
	private function castRowValue(mixed $value, string $cast): mixed {
		if ($value === null && $cast === '?string') {
			return null;
		}

		if ($cast === '?string') {
			return (string)$value;
		}

		return match ($cast) {
			'int' => (int)$value,
			'float' => (float)$value,
			'string' => (string)$value,
			'bool' => (bool)$value,
			default => $value,
		};
	}//end castRowValue()

	/**
	 * Mark entity relations as anonymized, persisting each entity's EXACT
	 * emitted placeholder into its `anonymized_value`.
	 *
	 * The scope-local placeholder (number + localized label) is computed once,
	 * at anonymise time, inside the run — it is not otherwise recoverable later
	 * (the number depends on the scope and is not derivable from the stored
	 * rows alone). Persisting it here, per relation, is the only durable record
	 * of "what each entity became" — and it lives exactly as long as the
	 * relation does (overwritten on re-anonymise, removed when the relation is
	 * deleted/re-extracted), so there is no separate store to keep in sync or
	 * clean up.
	 *
	 * ONLY the entities actually redacted are marked: each `entity_id` present
	 * in `$placeholders` (i.e. the entities that were substituted into
	 * the document) has all its non-skipped relations on the file set to
	 * `anonymized = true` with `anonymized_value` = its exact placeholder.
	 *
	 * This intentionally does NOT blanket-mark every non-skipped relation:
	 * an entity that was detected but NOT redacted (absent from the map — e.g.
	 * a value matched only under a second type, or filtered out) was never
	 * substituted into the document, so marking it anonymized would make the
	 * grondslagen-summary list a placeholder that isn't in the file and (with
	 * no scope-local number) leak the global entity id. Skip decisions are
	 * honoured (skipped rows untouched).
	 *
	 * @param int $fileId The file ID.
	 * @param array<int|string, string> $placeholders Keys are int|string,
	 *                                                not string: a stringified entity id is a canonical numeric string,
	 *                                                which PHP coerces to an int array key. Map of entity id → the
	 *                                                exact placeholder emitted for it
	 *                                                (e.g. "7" => "[PERSOON: 1]").
	 *
	 * @return int Number of relation rows marked anonymized.
	 */
	public function markAsAnonymizedWithPlaceholders(
		int $fileId,
		array $placeholders,
	): int {
		$total = 0;
		foreach ($placeholders as $entityId => $placeholder) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('anonymized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set('anonymized_value', $qb->createNamedParameter((string)$placeholder))
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter((int)$entityId, IQueryBuilder::PARAM_INT)))
				->andWhere(
					$qb->expr()->eq('skip_anonymization', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				);
			$total += $qb->executeStatement();
		}

		return $total;
	}//end markAsAnonymizedWithPlaceholders()

	/**
	 * Find entity relations for the anonymise pass — skip-aware.
	 *
	 * Same shape as `findEntitiesForFile` but filters out rows the operator
	 * has flagged with `skip_anonymization = true`. The anonymise flow
	 * (`FileTextController::anonymizeFile`) uses this method to build the
	 * replacements list so skipped occurrences are excluded from redaction.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array<int, array<string, mixed>> Array of entity rows joined with their Entity record.
	 */
	public function findEntitiesForAnonymization(int $fileId): array {
		$qb = $this->selectFileEntities(fileId: $fileId);
		$qb->andWhere($qb->expr()->eq('r.skip_anonymization', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('r.position_start', 'ASC');

		$result = $qb->executeQuery();
		$entities = $result->fetchAll();
		$result->closeCursor();

		return $entities;
	}//end findEntitiesForAnonymization()

	/**
	 * A select of a file's entity relations joined with their entity's type, value and category.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return IQueryBuilder The select, open for more conditions and an order.
	 */
	private function selectFileEntities(int $fileId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::FILE_ENTITY_COLUMNS)
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $qb;
	}//end selectFileEntities()

	/**
	 * Collect distinct `Entity.value` strings for relations on a file flagged
	 * with `skip_anonymization = true`.
	 *
	 * Used by the anonymise text-replacement code path as a defensive filter:
	 * even when the caller (e.g. DocuDesk) passes an entities[] payload that
	 * includes skipped occurrences, the redaction step removes those entries
	 * by matching their `text` against the values returned here. Per the
	 * `entity-relation-grondslagen` spec: "skipped relations are never
	 * redacted, full stop", regardless of caller behaviour.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return array<int, string> Distinct entity values for skipped relations.
	 */
	public function findSkippedEntityValuesForFile(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('e.value')
			->from($this->getTableName(), 'r')
			->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('r.skip_anonymization', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_values(
			array_filter(
				array_map(static fn (array $row): string => (string)($row['value'] ?? ''), $rows),
				static fn (string $value): bool => $value !== ''
			)
		);
	}//end findSkippedEntityValuesForFile()

	/**
	 * Update operator-decision metadata on an EntityRelation row.
	 *
	 * Single audited write path for the two decision-only fields:
	 *   - `bases` (?array) — UUIDs of legal grondslagen
	 *   - `skipAnonymization` (bool) — opt-out from the next anonymise pass
	 *
	 * Strict whitelist: any other key in `$fields` causes a
	 * `CustomValidationException`. Shape validation: `bases` MUST be
	 * null or array<string>; `skipAnonymization` MUST be a bool.
	 *
	 * Diff-aware: if every supplied value matches the current row state,
	 * the method returns the unchanged row and writes NO audit entry
	 * (semantic no-op). Empty `$fields` is also a no-op.
	 *
	 * Otherwise the row is updated and exactly one audit-trail entry is
	 * emitted via the OpenRegister immutable audit subsystem (ADR-022),
	 * capturing the acting user UID (per ADR-005, the UID — NOT the
	 * display name in the structured changed-fields payload), the
	 * timestamp, the subject (table + row id), and only the fields that
	 * actually changed.
	 *
	 * @param EntityRelation $relation Pre-loaded relation row (callers handle find/404).
	 * @param array $fields Subset of whitelist keys with new values.
	 * @param IUser|null $actingUser Optional explicit acting user; falls back
	 *                               to the session user. If null and no
	 *                               session user, the audit entry records the
	 *                               actor as 'system'.
	 *
	 * @return EntityRelation The (possibly unchanged) row.
	 *
	 * @throws CustomValidationException When a whitelist or shape violation
	 *                                   is detected.
	 */
	public function updateDecisionMetadata(EntityRelation $relation, array $fields, ?IUser $actingUser = null): EntityRelation {
		self::assertDecisionFieldsValid(fields: $fields);
		$changedFields = self::applyDecisionChanges(relation: $relation, fields: $fields);
		if (count($changedFields) === 0) {
			return $relation;
		}

		$actingUser = ($actingUser ?? $this->userSession->getUser());
		$change     = new DecisionMetadataChange(relation: $relation, changedFields: $changedFields, actingUser: $actingUser);
		return $this->recordDecisionChange(change: $change);
	}//end updateDecisionMetadata()

	/**
	 * Store a decision-metadata change with its audit entry, then notify listeners.
	 *
	 * Audit-invariant: every persisted state change MUST have an audit-trail
	 * entry. Both writes go in a single transaction so a failed audit INSERT
	 * rolls back the row UPDATE: clients see HTTP 500 instead of an
	 * undetectable audit gap. The event dispatch stays OUTSIDE the
	 * transaction (post-commit, informational; listener failures must not
	 * roll back).
	 *
	 * @param DecisionMetadataChange $change The relation, its changed fields and the acting user.
	 *
	 * @return EntityRelation The stored relation.
	 */
	private function recordDecisionChange(DecisionMetadataChange $change): EntityRelation {
		$this->db->beginTransaction();
		try {
			$relation = $this->update(entity: $change->relation);
			$this->emitDecisionMetadataAuditEntry(relation: $relation, change: $change);
			$this->db->commit();
		} catch (\Throwable $writeError) {
			$this->db->rollBack();
			$message = '[EntityRelationMapper] Transactional decision-metadata write failed, rolled back';
			$this->logDecisionFailure(message: $message, change: $change, error: $writeError);
			throw $writeError;
		}

		$this->notifyDecisionUpdated(relation: $relation, change: $change);
		return $relation;
	}//end recordDecisionChange()

	/**
	 * Tell listeners a relation's decision metadata changed.
	 *
	 * Downstream apps subscribe here (DocuDesk's publication-clearance-via-
	 * anonymise change creates a publicationConsent record whenever
	 * skipAnonymization flips false to true, so the 28-day Woo clock starts at
	 * decision time). A dispatch or listener failure MUST NOT roll back the
	 * persisted change: it is logged and the write stands, same contract as audit.
	 *
	 * @param EntityRelation         $relation The stored relation.
	 * @param DecisionMetadataChange $change   The change that was stored.
	 *
	 * @return void
	 */
	private function notifyDecisionUpdated(EntityRelation $relation, DecisionMetadataChange $change): void {
		try {
			$this->entityEvents->entityRelationDecisionUpdated(
				update: new RelationDecisionUpdate(relation: $relation, changedFields: $change->changedFields, actingUser: $change->actingUser)
			);
		} catch (\Throwable $dispatchError) {
			$message = '[EntityRelationMapper] Failed to dispatch EntityRelationDecisionUpdatedEvent';
			$this->logDecisionFailure(message: $message, change: $change, error: $dispatchError);
		}
	}//end notifyDecisionUpdated()

	/**
	 * Log a failed decision-metadata write or dispatch with the relation and the changed keys.
	 *
	 * @param string                 $message The log message.
	 * @param DecisionMetadataChange $change  The change being stored.
	 * @param \Throwable             $error   The failure.
	 *
	 * @return void
	 */
	private function logDecisionFailure(string $message, DecisionMetadataChange $change, \Throwable $error): void {
		$this->logger->error(
			message: $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'relation_id' => (int)$change->relation->getId(),
				'changedKeys' => array_keys($change->changedFields),
				'error' => $error->getMessage(),
			]
		);
	}//end logDecisionFailure()

	/**
	 * Reject decision-metadata fields outside the whitelist or of the wrong shape.
	 *
	 * @param array $fields Subset of whitelist keys with new values.
	 *
	 * @return void
	 *
	 * @throws CustomValidationException When a whitelist or shape violation is detected.
	 */
	private static function assertDecisionFieldsValid(array $fields): void {
		$unknown = array_diff(array_keys($fields), ['bases', 'skipAnonymization']);
		if (count($unknown) > 0) {
			$first = (string)reset($unknown);
			throw new CustomValidationException(
				message: 'Field not editable: ' . $first,
				errors: ['field' => $first]
			);
		}

		if (array_key_exists('bases', $fields) === true) {
			self::assertBasesShape(bases: $fields['bases']);
		}

		if (array_key_exists('skipAnonymization', $fields) === true
			&& is_bool($fields['skipAnonymization']) === false
		) {
			throw new CustomValidationException(
				message: 'Invalid skipAnonymization shape: must be a boolean',
				errors: ['field' => 'skipAnonymization', 'reason' => 'must_be_boolean']
			);
		}
	}//end assertDecisionFieldsValid()

	/**
	 * Reject a `bases` value that is neither null nor an array of strings.
	 *
	 * @param mixed $bases The supplied bases.
	 *
	 * @return void
	 *
	 * @throws CustomValidationException When the shape is wrong.
	 */
	private static function assertBasesShape(mixed $bases): void {
		if ($bases !== null && is_array($bases) === false) {
			throw new CustomValidationException(
				message: 'Invalid bases shape: must be null or array of strings',
				errors: ['field' => 'bases', 'reason' => 'must_be_null_or_array_of_strings']
			);
		}

		foreach ($bases ?? [] as $element) {
			if (is_string($element) === false) {
				throw new CustomValidationException(
					message: 'Invalid bases shape: array elements must be strings',
					errors: ['field' => 'bases', 'reason' => 'must_be_null_or_array_of_strings']
				);
			}
		}
	}//end assertBasesShape()

	/**
	 * Apply the supplied decision metadata to the relation and list what changed.
	 *
	 * @param EntityRelation $relation The relation row, updated in place.
	 * @param array $fields Validated decision-metadata fields.
	 *
	 * @return array<string, array{previous: mixed, new: mixed}> The changed fields.
	 */
	private static function applyDecisionChanges(EntityRelation $relation, array $fields): array {
		$changedFields = [];

		// Compare as multiset — same UUIDs in different order is a
		// semantic no-op for redaction policy. Storage preserves
		// operator-supplied order; only the diff check normalises.
		if (array_key_exists('bases', $fields) === true
			&& self::basesAreEqual(a: $relation->getBases(), b: $fields['bases']) === false
		) {
			$changedFields['bases'] = ['previous' => $relation->getBases(), 'new' => $fields['bases']];
			$relation->setBases($fields['bases']);
		}

		if (array_key_exists('skipAnonymization', $fields) === true
			&& $relation->getSkipAnonymization() !== $fields['skipAnonymization']
		) {
			$changedFields['skipAnonymization'] = [
				'previous' => $relation->getSkipAnonymization(),
				'new' => $fields['skipAnonymization'],
			];
			$relation->setSkipAnonymization($fields['skipAnonymization']);
		}

		return $changedFields;
	}//end applyDecisionChanges()

	/**
	 * Multiset-equality on two `bases` values.
	 *
	 * Distinguishes the four nullability/empty cases that callers care about:
	 *   - null vs null → equal (both "no bases")
	 *   - null vs [] → NOT equal (different intent: "unset" vs "explicitly empty")
	 *   - [a, b] vs [b, a] → equal (order-insensitive for redaction policy)
	 *   - [a, b, a] vs [b, a] → equal (duplicates collapsed)
	 *
	 * Storage preserves operator-supplied order + duplicates; only the
	 * diff check normalises. Used by `updateDecisionMetadata` to avoid
	 * spurious audit entries on cosmetic reorderings.
	 *
	 * @param array|null $a First value.
	 * @param array|null $b Second value.
	 *
	 * @return bool True when the two values represent the same redaction policy.
	 */
	private static function basesAreEqual(?array $a, ?array $b): bool {
		// Both null is equal; exactly one null is not.
		if ($a === null || $b === null) {
			return $a === $b;
		}

		$normA = array_values(array_unique($a, SORT_STRING));
		$normB = array_values(array_unique($b, SORT_STRING));
		sort($normA);
		sort($normB);
		return $normA === $normB;
	}//end basesAreEqual()

	/**
	 * Emit one audit-trail entry summarising a decision-metadata write.
	 *
	 * The AuditTrail entity's `object`, `objectUuid`, `register`, `schema`
	 * fields are designed around ObjectEntity rows; EntityRelation is a
	 * non-ObjectEntity table, so we set those nullable fields to null and
	 * encode the subject (table + row id) inside the `changed` JSON
	 * payload alongside the per-field diff.
	 *
	 * @param EntityRelation         $relation The relation as stored now.
	 * @param DecisionMetadataChange $change   The changed fields and the acting user (already resolved to the session user).
	 *
	 * @return void
	 */
	private function emitDecisionMetadataAuditEntry(EntityRelation $relation, DecisionMetadataChange $change): void {
		$user = $change->actingUser;
		$userId = 'system';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$auditTrail = new AuditTrail();
		$auditTrail->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
		$auditTrail->setAction('entity_relation_decision_updated');
		$auditTrail->setUser($userId);
		// ADR-005: actor is the UID only. Display name is PII; do not
		// persist it on entity-relation decision audit entries.
		$auditTrail->setUserName(null);
		$auditTrail->setCreated(new DateTime());
		$auditTrail->setChanged(
			[
				'subjectType' => 'openregister_entity_relations',
				'subjectId' => (int)$relation->getId(),
				'fields' => $change->changedFields,
			]
		);

		$this->auditTrailMapper->insert($auditTrail);
	}//end emitDecisionMetadataAuditEntry()

	/**
	 * Link a relation to its current decision object.
	 *
	 * The decision itself (who, when, verdict, note) is its own object in
	 * `openregister_decisions` (decision 197); the relation only points at it.
	 * The audit trail of the verdict stays with `updateDecisionMetadata()`.
	 *
	 * With `$guarded`, the link is a compare-and-swap: it is written only while
	 * the stored link is still `$expectedUuid` (null: none), so two reviewers
	 * taking a first decision at once cannot both link theirs.
	 *
	 * @param EntityRelation $relation The relation.
	 * @param string $decisionUuid The decision's uuid.
	 * @param string|null $expectedUuid The link the caller read before deciding; only read when guarded.
	 * @param bool $guarded Whether to write only while the stored link is still `$expectedUuid`.
	 *
	 * @return EntityRelation The stored relation.
	 *
	 * @throws DecisionConflictException When guarded and the stored link changed in the meantime.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The default keeps every unguarded caller unchanged; the guard is one
	 * extra statement, not a second behaviour.
	 */
	public function linkDecision(
		EntityRelation $relation,
		string $decisionUuid,
		?string $expectedUuid = null,
		bool $guarded = false,
	): EntityRelation {
		if ($guarded === true) {
			$this->swapStoredLink(qb: $this->decisionLinkUpdate(relation: $relation, decisionUuid: $decisionUuid), expectedUuid: $expectedUuid);
		}

		$relation->setDecisionUuid($decisionUuid);

		return $this->update(entity: $relation);
	}//end linkDecision()

	/**
	 * The update that points one relation at a decision, before its guard.
	 *
	 * @param EntityRelation $relation     The relation.
	 * @param string         $decisionUuid The decision's uuid.
	 *
	 * @return IQueryBuilder The update.
	 */
	private function decisionLinkUpdate(EntityRelation $relation, string $decisionUuid): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('decision_uuid', $qb->createNamedParameter($decisionUuid))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($relation->getId(), IQueryBuilder::PARAM_INT)));

		return $qb;
	}//end decisionLinkUpdate()

	/**
	 * Run a link update only while the stored link is still the expected one.
	 *
	 * @param IQueryBuilder $qb           The link update.
	 * @param string|null   $expectedUuid The link the caller read before deciding (null: none).
	 *
	 * @return void
	 *
	 * @throws DecisionConflictException When the stored link changed in the meantime.
	 */
	private function swapStoredLink(IQueryBuilder $qb, ?string $expectedUuid): void {
		$qb->andWhere($this->storedLinkIs(qb: $qb, expectedUuid: $expectedUuid));

		if ($qb->executeStatement() === 0) {
			throw new DecisionConflictException(message: 'The relation was decided by somebody else in the meantime.');
		}
	}//end swapStoredLink()

	/**
	 * The condition "the stored decision link is still what the caller read".
	 *
	 * @param IQueryBuilder $qb The query builder the condition is for.
	 * @param string|null $expectedUuid The link the caller read; null or empty means none.
	 *
	 * @return ICompositeExpression|string The condition for `andWhere()`.
	 */
	private function storedLinkIs(IQueryBuilder $qb, ?string $expectedUuid): ICompositeExpression|string {
		if ($expectedUuid !== null && $expectedUuid !== '') {
			return $qb->expr()->eq('decision_uuid', $qb->createNamedParameter($expectedUuid));
		}

		return $qb->expr()->orX($qb->expr()->isNull('decision_uuid'), $qb->expr()->eq('decision_uuid', $qb->createNamedParameter('')));
	}//end storedLinkIs()
}//end class
