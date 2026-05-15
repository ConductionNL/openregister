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
            ->orderBy('r.position_start', 'ASC');

        $result   = $qb->executeQuery();
        $entities = $result->fetchAll();
        $result->closeCursor();

        return $entities;
    }//end findEntitiesForFile()

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
            ->orderBy('r.position_start', 'ASC');

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
            if ($previousBases !== $fields['bases']) {
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

        $relation   = $this->update(entity: $relation);
        $relationId = (int) $relation->getId();

        try {
            $this->emitDecisionMetadataAuditEntry(
                relationId: $relationId,
                changedFields: $changedFields,
                actingUser: $actingUser
            );
        } catch (\Throwable $auditError) {
            // Audit-emission failure MUST NOT mask the persisted state
            // change — log loudly and continue. The next operator review
            // surfaces the row's new state; the audit-gap is captured in
            // the application log.
            $this->logger->error(
                message: '[EntityRelationMapper] Failed to emit decision-metadata audit entry',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'relation_id' => $relationId,
                    'changedKeys' => array_keys($changedFields),
                    'error'       => $auditError->getMessage(),
                ]
            );
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
        $user     = $actingUser ?? $this->userSession->getUser();
        $userId   = $user !== null ? $user->getUID() : 'system';
        $userName = $user !== null ? $user->getDisplayName() : 'System';

        $auditTrail = new AuditTrail();
        $auditTrail->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $auditTrail->setAction('entity_relation_decision_updated');
        $auditTrail->setUser($userId);
        $auditTrail->setUserName($userName);
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
}//end class
