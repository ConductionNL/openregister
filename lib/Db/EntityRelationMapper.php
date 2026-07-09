<?php

/**
 * Mapper for entity relations.
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
use OCA\OpenRegister\Event\EntityRelationDecisionUpdatedEvent;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
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
class EntityRelationMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection    $db               Database connection.
     * @param AuditTrailMapper $auditTrailMapper Audit-trail persistence (used by updateDecisionMetadata).
     * @param IUserSession     $userSession      Session user lookup for audit-trail actor.
     * @param IEventDispatcher $eventDispatcher  Symfony event dispatcher (used by updateDecisionMetadata
     *                                           to notify listeners after a decision-metadata write).
     * @param LoggerInterface  $logger           Structured log sink.
     */
    public function __construct(
        IDBConnection $db,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IUserSession $userSession,
        private readonly IEventDispatcher $eventDispatcher,
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
    public function find(int $id): EntityRelation
    {
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
    public function findByFileId(int $fileId): array
    {
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
    public function findByEntityId(int $entityId): array
    {
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
    public function findEntitiesForFile(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'r.id as relation_id',
            'r.entity_id',
            'r.position_start',
            'r.position_end',
            'r.confidence',
            'e.type as entity_type',
            'e.value as entity_value',
            'e.category'
        )
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
            ->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('r.chunk_id', 'ASC')
            ->addOrderBy('r.position_start', 'ASC');

        $result   = $qb->executeQuery();
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
     *         `{relation_id, entity_id, position_start, position_end, confidence,
     *           entity_type, entity_value, category, bases (array|null),
     *           skip_anonymization (bool), anonymized (bool), anonymized_value (string|null)}`.
     */
    public function findAnonymisedEntitiesWithBasesForFile(int $fileId): array
    {
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
            ->orderBy('r.chunk_id', 'ASC')
            ->addOrderBy('r.position_start', 'ASC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        // Decode the JSON `bases` column. Doctrine returns it as a string;
        // callers expect an array (or null when no bases were recorded).
        foreach ($rows as &$row) {
            if (isset($row['bases']) === true && is_string($row['bases']) === true) {
                $decoded      = json_decode($row['bases'], associative: true);
                $row['bases'] = is_array($decoded) === true ? $decoded : null;
            }

            // Normalise boolean-as-int values returned by some DB drivers.
            if (isset($row['skip_anonymization']) === true) {
                $row['skip_anonymization'] = (bool) $row['skip_anonymization'];
            }

            if (isset($row['anonymized']) === true) {
                $row['anonymized'] = (bool) $row['anonymized'];
            }
        }

        unset($row);

        return $rows;
    }//end findAnonymisedEntitiesWithBasesForFile()

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
    public function findEntityIdsByValueForFile(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['e.id', 'e.type', 'e.value'])
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
            ->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $map = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value === '' || isset($map[$value]) === true) {
                continue;
            }

            $map[$value] = [
                'id'   => (int) ($row['id'] ?? 0),
                'type' => (string) ($row['type'] ?? ''),
            ];
        }

        return $map;

    }//end findEntityIdsByValueForFile()

    /**
     * Load the entity-relation rows for several files in one query — the
     * multi-file sibling of `findEntityIdsByValueForFile`.
     *
     * Used by the per-dossier placeholder numbering: the dossier number is
     * recomputed as a pure function of the dossier's stored rows, so this
     * returns the raw `(entity_id, file_id, position_start)` tuples for every
     * relation on the given files. The caller (`PlaceholderIdTranslator`)
     * imposes the total stable order `(file_id, position_start, entity_id)`
     * and ranks distinct `entity_id`s by first appearance. No join to
     * `openregister_entities` is needed — the relation row already carries the
     * `entity_id` that is the numbering key.
     *
     * @param array<int, int> $fileIds The Nextcloud file IDs of the dossier's files.
     *
     * @return array<int, array{entity_id: int, file_id: int, position_start: int}>
     *         One record per relation row across all given files (unordered;
     *         the caller imposes the ranking order).
     */
    public function findEntityIdsByValueForFiles(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $fileIds)));

        $qb = $this->db->getQueryBuilder();
        $qb->select('entity_id', 'file_id', 'position_start')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->in(
                    'file_id',
                    $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
                )
            );

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'entity_id'      => (int) ($row['entity_id'] ?? 0),
                'file_id'        => (int) ($row['file_id'] ?? 0),
                'position_start' => (int) ($row['position_start'] ?? 0),
            ];
        }

        return $records;

    }//end findEntityIdsByValueForFiles()

    /**
     * Mark entity relations as anonymized.
     *
     * Skip-aware: rows where `skip_anonymization = true` are excluded
     * (those reflect an operator decision NOT to redact the occurrence).
     * Per the `entity-relation-grondslagen` change, skipped rows retain
     * `anonymized = false` after the file's anonymise pass.
     *
     * @param int    $fileId          The file ID.
     * @param string $anonymizedValue The placeholder value used.
     *
     * @return int Number of relations updated.
     */
    public function markAsAnonymized(int $fileId, string $anonymizedValue): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('anonymized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->set('anonymized_value', $qb->createNamedParameter($anonymizedValue))
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('skip_anonymization', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

        return $qb->executeStatement();
    }//end markAsAnonymized()

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
     * in `$placeholderByEntityId` (i.e. the entities that were substituted into
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
     * @param int                   $fileId                The file ID.
     * @param array<string, string> $placeholderByEntityId Map of (stringified) entity id → the
     *                                                     exact placeholder emitted for it
     *                                                     (e.g. "7" => "[PERSOON: 1]").
     *
     * @return int Number of relation rows marked anonymized.
     */
    public function markAsAnonymizedWithPlaceholders(
        int $fileId,
        array $placeholderByEntityId
    ): int {
        $total = 0;
        foreach ($placeholderByEntityId as $entityId => $placeholder) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->getTableName())
                ->set('anonymized', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
                ->set('anonymized_value', $qb->createNamedParameter((string) $placeholder))
                ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter((int) $entityId, IQueryBuilder::PARAM_INT)))
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
    public function findEntitiesForAnonymization(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'r.id as relation_id',
            'r.entity_id',
            'r.position_start',
            'r.position_end',
            'r.confidence',
            'e.type as entity_type',
            'e.value as entity_value',
            'e.category'
        )
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
            ->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('r.skip_anonymization', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('r.chunk_id', 'ASC')
            ->addOrderBy('r.position_start', 'ASC');

        $result   = $qb->executeQuery();
        $entities = $result->fetchAll();
        $result->closeCursor();

        return $entities;
    }//end findEntitiesForAnonymization()

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
    public function findSkippedEntityValuesForFile(int $fileId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('e.value')
            ->from($this->getTableName(), 'r')
            ->innerJoin('r', 'openregister_entities', 'e', $qb->expr()->eq('r.entity_id', 'e.id'))
            ->where($qb->expr()->eq('r.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('r.skip_anonymization', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return array_values(
                array_filter(
            array_map(static fn (array $row): string => (string) ($row['value'] ?? ''), $rows),
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
     * @param EntityRelation $relation   Pre-loaded relation row (callers handle find/404).
     * @param array          $fields     Subset of whitelist keys with new values.
     * @param IUser|null     $actingUser Optional explicit acting user; falls back
     *                                   to the session user. If null and no
     *                                   session user, the audit entry records the
     *                                   actor as 'system'.
     *
     * @return EntityRelation The (possibly unchanged) row.
     *
     * @throws CustomValidationException When a whitelist or shape violation
     *                                   is detected.
     */
    public function updateDecisionMetadata(EntityRelation $relation, array $fields, ?IUser $actingUser=null): EntityRelation
    {
        $allowed = ['bases', 'skipAnonymization'];
        $unknown = array_diff(array_keys($fields), $allowed);
        if (count($unknown) > 0) {
            $first = (string) reset($unknown);
            throw new CustomValidationException(
                message: 'Field not editable: '.$first,
                errors: ['field' => $first]
            );
        }

        if (array_key_exists('bases', $fields) === true) {
            $bases = $fields['bases'];
            if ($bases !== null && is_array($bases) === false) {
                throw new CustomValidationException(
                    message: 'Invalid bases shape: must be null or array of strings',
                    errors: ['field' => 'bases', 'reason' => 'must_be_null_or_array_of_strings']
                );
            }

            if (is_array($bases) === true) {
                foreach ($bases as $element) {
                    if (is_string($element) === false) {
                        throw new CustomValidationException(
                            message: 'Invalid bases shape: array elements must be strings',
                            errors: ['field' => 'bases', 'reason' => 'must_be_null_or_array_of_strings']
                        );
                    }
                }
            }
        }

        if (array_key_exists('skipAnonymization', $fields) === true
            && is_bool($fields['skipAnonymization']) === false
        ) {
            throw new CustomValidationException(
                message: 'Invalid skipAnonymization shape: must be a boolean',
                errors: ['field' => 'skipAnonymization', 'reason' => 'must_be_boolean']
            );
        }

        $changedFields = [];

        if (array_key_exists('bases', $fields) === true) {
            $previousBases = $relation->getBases();
            // Compare as multiset — same UUIDs in different order is a
            // semantic no-op for redaction policy. Storage preserves
            // operator-supplied order; only the diff check normalises.
            if (self::basesAreEqual(a: $previousBases, b: $fields['bases']) === false) {
                $changedFields['bases'] = [
                    'previous' => $previousBases,
                    'new'      => $fields['bases'],
                ];
                $relation->setBases($fields['bases']);
            }
        }

        if (array_key_exists('skipAnonymization', $fields) === true) {
            $previousSkip = $relation->getSkipAnonymization();
            if ($previousSkip !== $fields['skipAnonymization']) {
                $changedFields['skipAnonymization'] = [
                    'previous' => $previousSkip,
                    'new'      => $fields['skipAnonymization'],
                ];
                $relation->setSkipAnonymization($fields['skipAnonymization']);
            }
        }

        if (count($changedFields) === 0) {
            return $relation;
        }

        // Audit-invariant: every persisted state change MUST have an
        // audit-trail entry. Both writes go in a single transaction so a
        // failed audit-INSERT rolls back the row UPDATE — clients see
        // HTTP 500 instead of an undetectable audit gap. The downstream
        // event dispatch stays OUTSIDE the transaction (post-commit,
        // informational; listener failures must not roll back).
        $this->db->beginTransaction();
        try {
            $relation   = $this->update(entity: $relation);
            $relationId = (int) $relation->getId();
            $this->emitDecisionMetadataAuditEntry(
                relationId: $relationId,
                changedFields: $changedFields,
                actingUser: $actingUser
            );
            $this->db->commit();
        } catch (\Throwable $writeError) {
            $this->db->rollBack();
            $this->logger->error(
                message: '[EntityRelationMapper] Transactional decision-metadata write failed, rolled back',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'relation_id' => (int) $relation->getId(),
                    'changedKeys' => array_keys($changedFields),
                    'error'       => $writeError->getMessage(),
                ]
            );
            throw $writeError;
        }//end try

        // Notify listeners (downstream apps — e.g. DocuDesk's
        // publication-clearance-via-anonymise change subscribes here to
        // create a publicationConsent record whenever skipAnonymization
        // flips false → true, so the 28-day Woo clock starts ticking at
        // decision time rather than at anonymise time). Failure to
        // dispatch / listener failure MUST NOT roll back the persisted
        // state change — log and continue, same contract as audit.
        try {
            $this->eventDispatcher->dispatchTyped(
                new EntityRelationDecisionUpdatedEvent(
                    relation: $relation,
                    changedFields: $changedFields,
                    actingUser: ($actingUser ?? $this->userSession->getUser())
                )
            );
        } catch (\Throwable $dispatchError) {
            $this->logger->error(
                message: '[EntityRelationMapper] Failed to dispatch EntityRelationDecisionUpdatedEvent',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'relation_id' => $relationId,
                    'changedKeys' => array_keys($changedFields),
                    'error'       => $dispatchError->getMessage(),
                ]
            );
        }//end try

        return $relation;
    }//end updateDecisionMetadata()

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
    private static function basesAreEqual(?array $a, ?array $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
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
     * @param int        $relationId    The EntityRelation row id.
     * @param array      $changedFields Map of field name → { previous, new }.
     * @param IUser|null $actingUser    Optional explicit acting user.
     *
     * @return void
     */
    private function emitDecisionMetadataAuditEntry(
        int $relationId,
        array $changedFields,
        ?IUser $actingUser=null
    ): void {
        $user   = $actingUser ?? $this->userSession->getUser();
        $userId = $user !== null ? $user->getUID() : 'system';

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
                    'subjectId'   => $relationId,
                    'fields'      => $changedFields,
                ]
                );

        $this->auditTrailMapper->insert($auditTrail);
    }//end emitDecisionMetadataAuditEntry()

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
     * @param int $fileId        Nextcloud file id.
     * @param int $entityId      Catalogue entity id.
     * @param int $chunkId       Chunk row id the position is relative to.
     * @param int $positionStart Position of the first byte of the match within the chunk.
     * @param int $positionEnd   Position one past the last byte of the match within the chunk.
     *
     * @return bool True when a row with exactly these five values exists.
     */
    public function existsForFileAtPosition(
        int $fileId,
        int $entityId,
        int $chunkId,
        int $positionStart,
        int $positionEnd
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('chunk_id', $qb->createNamedParameter($chunkId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq(
                        'position_start',
                        $qb->createNamedParameter($positionStart, IQueryBuilder::PARAM_INT)
                    ),
                    $qb->expr()->eq(
                        'position_end',
                        $qb->createNamedParameter($positionEnd, IQueryBuilder::PARAM_INT)
                    )
                )
            )
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
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
     *                   transaction can roll back.
     */
    public function insertBatch(array $rows): array
    {
        $inserted = [];
        foreach ($rows as $row) {
            $relation   = $this->buildRelationFromRow(row: $row);
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
    private function buildRelationFromRow(array $row): EntityRelation
    {
        $relation = new EntityRelation();

        $relation->setEntityId((int) $row['entityId']);

        if (array_key_exists('fileId', $row) === true && $row['fileId'] !== null) {
            $relation->setFileId((int) $row['fileId']);
        }

        if (array_key_exists('chunkId', $row) === true && $row['chunkId'] !== null) {
            $relation->setChunkId((int) $row['chunkId']);
        }

        if (array_key_exists('objectId', $row) === true && $row['objectId'] !== null) {
            $relation->setObjectId((int) $row['objectId']);
        }

        if (array_key_exists('emailId', $row) === true && $row['emailId'] !== null) {
            $relation->setEmailId((int) $row['emailId']);
        }

        if (array_key_exists('positionStart', $row) === true) {
            $relation->setPositionStart((int) $row['positionStart']);
        }

        if (array_key_exists('positionEnd', $row) === true) {
            $relation->setPositionEnd((int) $row['positionEnd']);
        }

        if (array_key_exists('confidence', $row) === true) {
            $relation->setConfidence((float) $row['confidence']);
        }

        if (array_key_exists('detectionMethod', $row) === true && $row['detectionMethod'] !== null) {
            $relation->setDetectionMethod((string) $row['detectionMethod']);
        }

        if (array_key_exists('context', $row) === true) {
            $relation->setContext($row['context'] !== null ? (string) $row['context'] : null);
        }

        if (array_key_exists('role', $row) === true) {
            $relation->setRole($row['role'] !== null ? (string) $row['role'] : null);
        }

        if (array_key_exists('anonymized', $row) === true) {
            $relation->setAnonymized((bool) $row['anonymized']);
        }

        if (array_key_exists('skipAnonymization', $row) === true) {
            $relation->setSkipAnonymization((bool) $row['skipAnonymization']);
        }

        if (array_key_exists('bases', $row) === true) {
            $relation->setBases($row['bases']);
        }

        if (array_key_exists('createdAt', $row) === true && $row['createdAt'] !== null) {
            $relation->setCreatedAt($row['createdAt']);
        } else {
            $relation->setCreatedAt(new DateTime());
        }

        return $relation;

    }//end buildRelationFromRow()
}//end class
