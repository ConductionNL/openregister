<?php

/**
 * Data-Subject Access Request (DSAR) service.
 *
 * Composes the existing GdprEntity index + entity_relations join +
 * MagicMapper object lookup into the four AVG / GDPR data-subject
 * rights flows:
 *
 *   - Art 15 (inzage)        — locate every object referencing a subject.
 *   - Art 17 (vergetelheid)  — soft-delete every object referencing a subject.
 *   - Art 20 (portabiliteit) — same locate, rendered for export.
 *   - Art 16 (rectificatie)  — single-object update wrapper.
 *
 * All write paths set the transient
 * `ObjectEntity::setProcessingActivityId()` before persisting so the
 * existing AuditTrailMapper hook (Phase 1) tags the audit row with
 * the operator-configured DSAR processing-activity uuid. The configured
 * activity is read from app-config key `dsar_processing_activity` and
 * resolved through `VerwerkingsactiviteitMapper::resolveReference`
 * (accepts both `code` and `uuid`). When unset, the audit row falls
 * back to the schema/register annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Data-Subject Access Request orchestrator.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DsarService
{

    /**
     * App-config key for the DSAR processing-activity reference.
     *
     * Operators register one Verwerkingsactiviteit (typically with
     * code `dsar`) describing the legal basis + purpose of DSAR
     * handling itself, then point this app-config key at it. All audit
     * rows produced by `eraseObjectsForSubject` /
     * `rectifyObjectForSubject` will be attributed to that activity.
     *
     * @var string
     */
    public const APP_CONFIG_DSAR_ACTIVITY = 'dsar_processing_activity';

    /**
     * Default reference used when the app-config key is unset. Maps to
     * the operator-registered activity that has `code='dsar'`.
     *
     * @var string
     */
    public const DEFAULT_DSAR_ACTIVITY_CODE = 'dsar';

    /**
     * Constructor.
     *
     * @param IDBConnection               $db           Database connection
     *                                                  used for the
     *                                                  GdprEntity +
     *                                                  entity_relations
     *                                                  lookup.
     * @param MagicMapper                 $objectMapper Object-routing
     *                                                  mapper used to
     *                                                  load found
     *                                                  objects.
     * @param VerwerkingsactiviteitMapper $vrwMapper    Resolves the
     *                                                  configured DSAR
     *                                                  activity reference.
     * @param IAppConfig                  $appConfig    App-config reader.
     * @param IUserSession                $userSession  Current user (for
     *                                                  soft-delete
     *                                                  metadata).
     * @param LoggerInterface             $logger       Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly MagicMapper $objectMapper,
        private readonly VerwerkingsactiviteitMapper $vrwMapper,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Find every object that contains personal data for the given subject.
     *
     * Walks `oc_openregister_entities` for rows whose `value` matches
     * the subject (case-insensitive exact match by default; pass
     * `mode='ilike'` for substring), then joins
     * `oc_openregister_entity_relations.object_id` to dedupe and load
     * the owning ObjectEntity rows.
     *
     * Returns a list of envelopes:
     *
     *   [
     *     [
     *       'object'        => <ObjectEntity::jsonSerialize() output>,
     *       'gdprEntities'  => [{type, value, category, detectedAt}, ...]
     *     ],
     *     ...
     *   ]
     *
     * @param string      $subject Subject identifier value (email, BSN, …).
     * @param string|null $type    Optional GdprEntity type filter (e.g. `email`).
     * @param string      $mode    `exact` (default) or `ilike`.
     *
     * @return array<int, array{object: array, gdprEntities: array}>
     */
    public function findObjectsForSubject(string $subject, ?string $type=null, string $mode='exact'): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            return [];
        }

        $hits = $this->matchEntities(subject: $subject, type: $type, mode: $mode);
        if ($hits === []) {
            return [];
        }

        // Group entity hits by the canonical object key. We prefer
        // `object_uuid` (globally unique across magic-tables) when
        // present, falling back to the legacy int `object_id` for rows
        // predating the disambiguation migration.
        $groupedByObject = [];
        foreach ($hits as $hit) {
            $key = $this->buildObjectKey(hit: $hit);
            if ($key === null) {
                continue;
            }

            $groupedByObject[$key] ??= [
                'object_id'    => (int) ($hit['object_id'] ?? 0),
                'object_uuid'  => (string) ($hit['object_uuid'] ?? ''),
                'gdprEntities' => [],
            ];

            $groupedByObject[$key]['gdprEntities'][] = [
                'type'       => (string) ($hit['type'] ?? ''),
                'value'      => (string) ($hit['value'] ?? ''),
                'category'   => (string) ($hit['category'] ?? ''),
                'detectedAt' => (string) ($hit['detected_at'] ?? ''),
            ];
        }

        $envelopes = [];
        foreach ($groupedByObject as $entry) {
            $object = $this->loadObjectByEntry(entry: $entry);
            if ($object === null) {
                continue;
            }

            $envelopes[] = [
                'object'       => $object->jsonSerialize(),
                'gdprEntities' => $entry['gdprEntities'],
            ];
        }//end foreach

        return $envelopes;

    }//end findObjectsForSubject()

    /**
     * Soft-delete every object matching a subject (Art 17 vergetelheid).
     *
     * The deletion itself is audit-logged for legal defence (the
     * verwerkingsactiviteit configured under `dsar_processing_activity`
     * provides the legal basis for keeping a deletion record); the
     * object's personal data is removed from active records by setting
     * the `deleted` metadata column.
     *
     * Returns a summary envelope:
     *
     *   [
     *     'subject'      => '<echo>',
     *     'type'         => '<echo|null>',
     *     'dryRun'       => bool,
     *     'matchedCount' => int,
     *     'erased'       => [
     *       ['uuid' => '<object-uuid>', 'register' => '<...>', 'schema' => '<...>'],
     *       ...
     *     ],
     *   ]
     *
     * @param string      $subject Subject identifier.
     * @param string|null $type    Optional GdprEntity type filter.
     * @param bool        $dryRun  When true, returns matches without erasing.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function eraseObjectsForSubject(string $subject, ?string $type=null, bool $dryRun=false): array
    {
        $hits = $this->matchEntities(subject: $subject, type: $type, mode: 'exact');

        // Dedupe by canonical key — uuid (preferred) or int id (legacy).
        $entries = [];
        foreach ($hits as $hit) {
            $key = $this->buildObjectKey(hit: $hit);
            if ($key === null) {
                continue;
            }

            $entries[$key] ??= [
                'object_id'   => (int) ($hit['object_id'] ?? 0),
                'object_uuid' => (string) ($hit['object_uuid'] ?? ''),
            ];
        }

        $summary = [
            'subject'      => $subject,
            'type'         => $type,
            'dryRun'       => $dryRun,
            'matchedCount' => count($entries),
            'erased'       => [],
        ];

        if ($dryRun === true || $entries === []) {
            return $summary;
        }

        $dsarActivityUuid = $this->getDsarProcessingActivityUuid();
        $userId           = ($this->userSession->getUser()?->getUID() ?? 'system');
        $deletionData     = [
            'deletedBy' => $userId,
            'deletedAt' => (new DateTime())->format(DateTime::ATOM),
            'reason'    => 'avg-vergetelheid',
            'subject'   => $subject,
        ];

        foreach ($entries as $entry) {
            $object = $this->loadObjectByEntry(entry: $entry);
            if ($object === null) {
                continue;
            }

            $object->setDeleted($deletionData);
            if ($dsarActivityUuid !== null) {
                $object->setProcessingActivityId($dsarActivityUuid);
            }

            try {
                $this->objectMapper->update(entity: $object);
                $summary['erased'][] = [
                    'uuid'     => $object->getUuid(),
                    'register' => $object->getRegister(),
                    'schema'   => $object->getSchema(),
                ];
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[DSAR] Soft-delete failed during vergetelheid',
                    context: ['object' => $entry, 'error' => $e->getMessage()]
                );
            }
        }//end foreach

        return $summary;

    }//end eraseObjectsForSubject()

    /**
     * Build a dedup key for a relation hit row.
     *
     * Prefers `object_uuid` when present (post-disambiguation migration);
     * falls back to `object_id` for legacy rows. Returns null when the
     * row carries neither — the hit is dropped.
     *
     * @param array<string, mixed> $hit Single SQL row from matchEntities.
     *
     * @return string|null Dedup key, or null when the row is unusable.
     */
    private function buildObjectKey(array $hit): ?string
    {
        $uuid = (string) ($hit['object_uuid'] ?? '');
        if ($uuid !== '') {
            return 'uuid:'.$uuid;
        }

        $id = (int) ($hit['object_id'] ?? 0);
        if ($id > 0) {
            return 'id:'.$id;
        }

        return null;

    }//end buildObjectKey()

    /**
     * Load the owning ObjectEntity for a deduplicated hit entry.
     *
     * Uses uuid lookup when the relation row carries one (deterministic
     * across magic-tables), otherwise falls back to the int id (best
     * effort, may collide on the legacy schema).
     *
     * @param array{object_id: int, object_uuid: string} $entry Dedup entry.
     *
     * @return ObjectEntity|null
     */
    private function loadObjectByEntry(array $entry): ?ObjectEntity
    {
        $identifier = ($entry['object_uuid'] !== '') ? $entry['object_uuid'] : $entry['object_id'];
        if ($identifier === 0 || $identifier === '') {
            return null;
        }

        try {
            return $this->objectMapper->find(
                $identifier,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: '[DSAR] Failed to load object',
                context: ['identifier' => $identifier, 'error' => $e->getMessage()]
            );
            return null;
        }

    }//end loadObjectByEntry()

    /**
     * Update a single object with DSAR attribution (Art 16 rectificatie).
     *
     * Thin wrapper around the existing object update path that pins
     * the audit row to the configured DSAR activity. Use this when a
     * data subject requests correction of specific fields rather than
     * a wholesale erasure.
     *
     * @param int                  $objectId Internal id of the object.
     * @param array<string, mixed> $changes  Property → new value map.
     *
     * @return array<string, mixed>|null Updated object envelope or null on miss.
     */
    public function rectifyObjectForSubject(int $objectId, array $changes): ?array
    {
        try {
            $object = $this->objectMapper->find(
                $objectId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return null;
        }

        $current = $object->getObject() ?? [];
        $merged  = array_merge($current, $changes);
        $object->setObject($merged);

        $dsarActivityUuid = $this->getDsarProcessingActivityUuid();
        if ($dsarActivityUuid !== null) {
            $object->setProcessingActivityId($dsarActivityUuid);
        }

        try {
            $this->objectMapper->update(entity: $object);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[DSAR] Rectificatie update failed',
                context: ['objectId' => $objectId, 'error' => $e->getMessage()]
            );
            return null;
        }

        return $object->jsonSerialize();

    }//end rectifyObjectForSubject()

    /**
     * Get the configured DSAR Verwerkingsactiviteit uuid.
     *
     * Reads the app-config key, falling back to the
     * `DEFAULT_DSAR_ACTIVITY_CODE` literal. Resolves either form
     * through the existing reference resolver. Returns null when
     * neither resolves — the caller falls through to schema/register
     * defaults in that case.
     *
     * @return string|null Resolved activity uuid, or null.
     */
    public function getDsarProcessingActivityUuid(): ?string
    {
        try {
            $reference = (string) $this->appConfig->getValueString(
                app: 'openregister',
                key: self::APP_CONFIG_DSAR_ACTIVITY,
                default: self::DEFAULT_DSAR_ACTIVITY_CODE
            );
        } catch (\Throwable $e) {
            $reference = self::DEFAULT_DSAR_ACTIVITY_CODE;
        }

        if ($reference === '') {
            return null;
        }

        $activity = $this->vrwMapper->resolveReference(reference: $reference);
        return $activity?->getUuid();

    }//end getDsarProcessingActivityUuid()

    /**
     * Run the SQL join used by inzage / vergetelheid / portabiliteit.
     *
     * Returns one row per (entity, object) pair so callers can render
     * which PII attributes triggered the match alongside the owning
     * object — useful for the inzage envelope.
     *
     * @param string      $subject Subject value.
     * @param string|null $type    Optional GdprEntity type filter.
     * @param string      $mode    `exact` (default, case-insensitive
     *                             literal match) or `ilike`
     *                             (case-insensitive substring).
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchEntities(string $subject, ?string $type, string $mode): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct(
                [
                    'e.id',
                    'e.type',
                    'e.value',
                    'e.category',
                    'e.detected_at',
                    'r.object_id',
                    'r.object_uuid',
                    'r.register_id',
                    'r.schema_id',
                ]
            )
                ->from('openregister_entities', 'e')
                ->innerJoin(
                    'e',
                    'openregister_entity_relations',
                    'r',
                    $qb->expr()->eq('r.entity_id', 'e.id')
                )
                ->where(
                    $qb->expr()->isNotNull('r.object_id')
                );

            // SECURITY: escape LIKE wildcards in user-supplied subject before
            // wrapping with `%`-anchors. Without this, an admin (the
            // `vergetelheid` endpoint is admin-gated) could pass
            // `subject=%@%` or `subject=_` to match every PII row in
            // `openregister_entities.value` — combined with the downstream
            // `eraseObjectsForSubject()` chain, that's a one-call wildcard
            // erase well beyond legitimate DSAR semantics.
            $escapedSubject = $this->db->escapeLikeParameter($subject);

            if ($mode === 'ilike') {
                $qb->andWhere(
                    $qb->expr()->iLike('e.value', $qb->createNamedParameter('%'.$escapedSubject.'%'))
                );
            }

            if ($mode !== 'ilike') {
                $qb->andWhere(
                    $qb->expr()->iLike('e.value', $qb->createNamedParameter($escapedSubject))
                );
            }

            if ($type !== null && $type !== '') {
                $qb->andWhere(
                    $qb->expr()->eq('e.type', $qb->createNamedParameter($type))
                );
            }

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[DSAR] GdprEntity lookup failed',
                context: ['subject' => $subject, 'type' => $type, 'error' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end matchEntities()
}//end class
