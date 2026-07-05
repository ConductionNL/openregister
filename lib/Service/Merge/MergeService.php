<?php

/**
 * OpenRegister MergeService
 *
 * Entity-type-agnostic, reversible merge engine (ADR-045 follow-on #B).
 * Generalises pipelinq's `OCA\Pipelinq\Service\Mdm\MergeService`, dropping its
 * hardcoded `MasterEntityService`/`SyncQueueService` in favour of the OR-owned
 * `SurvivorshipResolver` (golden-record recompute) and an `ObjectsMergedEvent`
 * (propagation). Config is read from the schema's `x-openregister-merge`
 * annotation. Every object read/write goes through `ObjectService` (RBAC +
 * tenant scoped, audited).
 *
 * `previewMerge` is side-effect-free. `executeMerge` is one server-authoritative
 * unit of work: snapshot -> relink -> recompute -> status flip -> persist
 * mergeOperation -> dispatch event. `reverseMerge` restores the snapshot
 * within `reversalWindowDays`, best-effort per source-record link (an
 * unresolvable record is skipped, not aborted — mirrors pipelinq).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use DateInterval;
use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Server-authoritative, reversible merge engine driven by
 * `x-openregister-merge` and reusing `SurvivorshipResolver`.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class owns the full
 *   reversible-merge lifecycle (preview / execute / reverse / snapshot /
 *   recompute) as one server-authoritative unit of work per the spec; splitting
 *   it across services would scatter a single transactional contract and make
 *   the atomicity guarantee harder to audit, mirroring pipelinq's precedent.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Reuses the existing
 *   survivorship collaborators (ObjectService, SchemaMapper,
 *   SurvivorshipResolver, TrustTierResolver) plus the event dispatcher and
 *   logger, per ADR-011 reuse — introducing a facade purely to hide the
 *   dependency count would add indirection without reducing coupling.
 */
class MergeService
{

    /**
     * Slug of the OR-owned merge-operation register schema.
     *
     * @var string
     */
    public const MERGE_SCHEMA = 'mergeOperation';

    /**
     * Slug of the OR-owned merge-operation register.
     *
     * @var string
     */
    private const MERGE_REGISTER = 'merge-operation';

    /**
     * Slug of the OR-owned trust-configuration register schema.
     *
     * @var string
     */
    private const TRUST_CONFIGURATION_SCHEMA = 'trustConfiguration';

    /**
     * Fallback reversal window (days) when the annotation omits `reversalWindowDays`.
     *
     * @var int
     */
    public const DEFAULT_REVERSAL_WINDOW_DAYS = 30;

    /**
     * Default field the merge status is read from when the annotation omits `statusField`.
     *
     * @var string
     */
    private const DEFAULT_STATUS_FIELD = 'status';

    /**
     * Default value marking the surviving object.
     *
     * @var string
     */
    private const DEFAULT_SURVIVOR_STATUS = 'active';

    /**
     * Default value marking a merged-away object.
     *
     * @var string
     */
    private const DEFAULT_MERGED_STATUS = 'merged-into-other';

    /**
     * Wire collaborators.
     *
     * @param ObjectService        $objectService        Object read/write path (RBAC + tenant scoped).
     * @param SchemaMapper         $schemaMapper         Schema lookup for the merge + survivorship annotations.
     * @param SurvivorshipResolver $resolver             Pure golden-record resolver, reused for recompute.
     * @param TrustTierResolver    $trustResolver        Pure trust-tier lookup + decay engine.
     * @param SourceRecordResolver $sourceRecordResolver Mode-aware source-record resolver (embedded | reverseFk).
     * @param IEventDispatcher     $eventDispatcher      Dispatcher used to fire `ObjectsMergedEvent`.
     * @param LoggerInterface      $logger               PSR logger.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#3.1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly SurvivorshipResolver $resolver,
        private readonly TrustTierResolver $trustResolver,
        private readonly SourceRecordResolver $sourceRecordResolver,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Preview a merge with NO side effects: no object, mergeOperation, or
     * event is written. Returns the projected survivor golden record +
     * provenance (via `SurvivorshipResolver` over the union of both objects'
     * linked source records) and the reversal deadline.
     *
     * @param string $from Uuid of the object that would be merged away.
     * @param string $into Uuid of the object that would survive.
     *
     * @return array<string, mixed> Preview payload.
     *
     * @throws RuntimeException When the uuids are equal or either object is unreadable.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    public function previewMerge(string $from, string $into): array
    {
        if ($from === $into) {
            throw new RuntimeException('Cannot merge an object into itself.');
        }

        $fromObject = $this->loadReadable(uuid: $from);
        $intoObject = $this->loadReadable(uuid: $into);

        $schema = $this->loadSchema(object: $intoObject);
        $config = $this->getMergeConfig(schema: $schema);

        $resolution = $this->recomputeSurvivor(
            fromObject: $fromObject,
            intoObject: $intoObject,
            schema: $schema,
            config: $config
        );

        $now = new DateTimeImmutable();

        return [
            'from'                  => $from,
            'into'                  => $into,
            'postMergeGoldenRecord' => $resolution['goldenRecord'],
            'attributeProvenance'   => $resolution['attributeProvenance'],
            'reversalDeadline'      => $this->reversalDeadline(mergedAt: $now->format(DATE_ATOM), config: $config),
        ];
    }//end previewMerge()

    /**
     * Execute a merge atomically (server-authoritative): snapshot both
     * objects, relink the losing object's source records onto the survivor,
     * recompute the survivor via `SurvivorshipResolver`, flip statuses,
     * persist a `mergeOperation` row, and dispatch `ObjectsMergedEvent`.
     *
     * @param string $from     Uuid of the object being merged away.
     * @param string $into     Uuid of the surviving object.
     * @param string $reason   Steward-supplied merge reason.
     * @param string $mergedBy Acting user uid.
     *
     * @return array<string, mixed> The persisted `mergeOperation` row.
     *
     * @throws RuntimeException When self-merge, already-merged, or the survivor is not active.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One atomic unit of work
     *   (snapshot -> relink -> recompute -> status flip -> persist -> event);
     *   splitting it would scatter a single server-authoritative transaction.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
     */
    public function executeMerge(string $from, string $into, string $reason, string $mergedBy): array
    {
        if ($from === $into) {
            throw new RuntimeException('Cannot merge an object into itself.');
        }

        $fromObject = $this->loadReadable(uuid: $from);
        $intoObject = $this->loadReadable(uuid: $into);

        $schema = $this->loadSchema(object: $intoObject);
        $config = $this->getMergeConfig(schema: $schema);

        $statusField    = (string) ($config['statusField'] ?? self::DEFAULT_STATUS_FIELD);
        $survivorStatus = (string) ($config['survivorStatus'] ?? self::DEFAULT_SURVIVOR_STATUS);
        $mergedStatus   = (string) ($config['mergedStatus'] ?? self::DEFAULT_MERGED_STATUS);

        $fromData = ($fromObject->getObject() ?? []);
        $intoData = ($intoObject->getObject() ?? []);

        if ((string) ($fromData[$statusField] ?? '') === $mergedStatus) {
            throw new RuntimeException('Source object has already been merged into another object.');
        }

        if ((string) ($intoData[$statusField] ?? $survivorStatus) !== $survivorStatus) {
            throw new RuntimeException('Target object is not active and cannot receive a merge.');
        }

        $snapshot           = $this->buildSnapshot(from: $fromObject, into: $intoObject);
        $survivorshipConfig = $this->getSurvivorshipConfig(schema: $schema);

        // 1. Relink the losing object's source records onto the survivor.
        // Reverse-FK: rewrite each losing source object's back-reference to the
        // survivor uuid (persisted), recording the moves for reversal. Embedded:
        // merge the loser's `sourceLinkField` array onto the survivor payload.
        $isReverseFk   = $this->sourceRecordResolver->isReverseFk(config: $survivorshipConfig);
        $relinkedCount = 0;
        if ($isReverseFk === true) {
            $reverseFkMoves = $this->relinkReverseFk(
                fromUuid: $from,
                intoUuid: $into,
                config: $survivorshipConfig,
                register: (string) $fromObject->getRegister()
            );
            $snapshot['reverseFkMoves'] = $reverseFkMoves;
            $relinkedCount = count($reverseFkMoves);
        }

        if ($isReverseFk === false) {
            $sourceLinkField = (string) ($config['sourceLinkField'] ?? '');
            $relinked        = $this->relinkSourceRecords(
                fromData: $fromData,
                intoData: $intoData,
                sourceLinkField: $sourceLinkField
            );
            $intoData        = $relinked['intoData'];
            $relinkedCount   = $relinked['count'];
        }

        // 2. Mark the losing object as merged.
        $fromData[$statusField] = $mergedStatus;
        $fromObject->setObject($fromData);
        $this->objectService->saveObject(
            object: $fromObject,
            register: $fromObject->getRegister(),
            schema: $fromObject->getSchema(),
            uuid: $fromObject->getUuid()
        );

        // 3. Recompute the survivor over the combined source set, mark it active, persist.
        $resolution = $this->recomputeSurvivor(
            fromObject: $fromObject,
            intoObject: $intoObject,
            schema: $schema,
            config: $config,
            intoDataOverride: $intoData
        );

        $goldenField     = (string) ($survivorshipConfig['goldenRecordField'] ?? 'goldenRecord');
        $provenanceField = (string) ($survivorshipConfig['provenanceField'] ?? 'attributeProvenance');

        $intoData[$goldenField]     = $resolution['goldenRecord'];
        $intoData[$provenanceField] = $resolution['attributeProvenance'];
        $intoData[$statusField]     = $survivorStatus;
        $intoObject->setObject($intoData);
        $savedInto = $this->objectService->saveObject(
            object: $intoObject,
            register: $intoObject->getRegister(),
            schema: $intoObject->getSchema(),
            uuid: $intoObject->getUuid()
        );

        // 4. Persist the merge-operation with the pre-merge snapshot.
        $now            = new DateTimeImmutable();
        $mergeOperation = [
            'mergedIntoUuid'   => $into,
            'mergedFromUuids'  => [$from],
            'reason'           => $reason,
            'preMergeSnapshot' => $snapshot,
            'reversible'       => true,
            'mergedAt'         => $now->format(DATE_ATOM),
        ];
        $savedOperation = $this->objectService->saveObject(
            object: $mergeOperation,
            register: self::MERGE_REGISTER,
            schema: self::MERGE_SCHEMA
        );
        $operationData  = ($savedOperation->getObject() ?? []);
        $operationData['id'] = $savedOperation->getUuid();

        $this->logger->info(
            'OpenRegister MDM: merge executed',
            ['from' => $from, 'into' => $into, 'relinked' => $relinkedCount, 'by' => $mergedBy]
        );

        // 5. Dispatch the propagation event (no app-specific sync queue).
        $this->eventDispatcher->dispatchTyped(
            new ObjectsMergedEvent(
                survivorUuid: (string) $savedInto->getUuid(),
                mergedFromUuids: [$from],
                mergeOperationId: (string) $savedOperation->getUuid(),
                isReversal: false
            )
        );

        return $operationData;
    }//end executeMerge()

    /**
     * Reverse a merge within the reversal window: restore both objects from
     * `preMergeSnapshot` (golden record, provenance, status, source links),
     * mark the operation reversed, and dispatch a reversal `ObjectsMergedEvent`.
     * Rejected outside the window with no mutation.
     *
     * @param string $mergeOperationId Uuid of the `mergeOperation` row.
     * @param string $reversedBy       Acting user uid.
     *
     * @return array<string, mixed> The updated `mergeOperation` row.
     *
     * @throws RuntimeException When the operation is missing or no longer reversible.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.4
     */
    public function reverseMerge(string $mergeOperationId, string $reversedBy): array
    {
        $operationEntity = $this->objectService->find(id: $mergeOperationId, schema: self::MERGE_SCHEMA);
        if ($operationEntity === null) {
            throw new RuntimeException('Merge operation not found.');
        }

        $operation = ($operationEntity->getObject() ?? []);
        if ($this->isReversible(operation: $operation) === false) {
            throw new RuntimeException('Reversal window has expired.');
        }

        $snapshot = ($operation['preMergeSnapshot'] ?? []);
        if (is_array($snapshot) === false || empty($snapshot['objects']) === true) {
            throw new RuntimeException('Merge operation has no restorable snapshot.');
        }

        // 1. Reverse-FK: restore each moved source object's back-reference to
        // its pre-merge master FIRST, so restoring the master payloads below
        // recomputes each golden record over the correct (restored) source set.
        foreach (($snapshot['reverseFkMoves'] ?? []) as $move) {
            if (is_array($move) === true) {
                $this->restoreReverseFkMove(move: $move);
            }
        }

        // 2. Restore each object's golden record, provenance and status (best-effort).
        foreach ($snapshot['objects'] as $uuid => $state) {
            $this->restoreObjectState(uuid: (string) $uuid, state: $state);
        }

        // 3. Restore embedded source-record linkages (best-effort — skip unresolvable records).
        foreach (($snapshot['sourceLinks'] ?? []) as $sourceUuid => $link) {
            $this->restoreSourceLink(sourceUuid: (string) $sourceUuid, link: $link);
        }

        // 3. Mark the operation reversed.
        $now = new DateTimeImmutable();
        $operation['reversedAt'] = $now->format(DATE_ATOM);
        $operation['reversedBy'] = $reversedBy;
        $operation['reversible'] = false;
        $operationEntity->setObject($operation);
        $savedOperation = $this->objectService->saveObject(
            object: $operationEntity,
            register: self::MERGE_REGISTER,
            schema: self::MERGE_SCHEMA,
            uuid: $operationEntity->getUuid()
        );

        $this->logger->info(
            'OpenRegister MDM: merge reversed',
            ['operation' => $mergeOperationId, 'by' => $reversedBy]
        );

        $this->eventDispatcher->dispatchTyped(
            new ObjectsMergedEvent(
                survivorUuid: (string) ($operation['mergedIntoUuid'] ?? ''),
                mergedFromUuids: (array) ($operation['mergedFromUuids'] ?? []),
                mergeOperationId: $mergeOperationId,
                isReversal: true
            )
        );

        $result       = ($savedOperation->getObject() ?? []);
        $result['id'] = $savedOperation->getUuid();

        return $result;
    }//end reverseMerge()

    /**
     * Determine whether a `mergeOperation` is still reversible: `reversible`
     * is true, `reversedAt` is unset, and the elapsed time since `mergedAt`
     * does not exceed `reversalWindowDays`.
     *
     * @param array<string, mixed> $operation The mergeOperation row.
     * @param string|null          $asOf      As-of timestamp (null = now).
     *
     * @return bool True when reversible and within the window.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.4
     */
    public function isReversible(array $operation, ?string $asOf=null): bool
    {
        if (($operation['reversible'] ?? false) !== true) {
            return false;
        }

        if (($operation['reversedAt'] ?? '') !== '' && ($operation['reversedAt'] ?? null) !== null) {
            return false;
        }

        $mergedAt = (string) ($operation['mergedAt'] ?? '');
        if ($mergedAt === '') {
            return false;
        }

        $windowDays = self::DEFAULT_REVERSAL_WINDOW_DAYS;
        if (is_numeric($operation['reversalWindowDays'] ?? null) === true) {
            $windowDays = (int) $operation['reversalWindowDays'];
        }

        $now = ($asOf ?? (new DateTimeImmutable())->format(DATE_ATOM));

        try {
            $merged   = new DateTimeImmutable($mergedAt);
            $current  = new DateTimeImmutable($now);
            $deadline = $merged->add(new DateInterval('P'.$windowDays.'D'));
        } catch (Exception $e) {
            return false;
        }

        return $current <= $deadline;
    }//end isReversible()

    /**
     * Build the pre-merge snapshot for both objects (pure — no I/O beyond the
     * data already resolved on the passed-in entities): golden record,
     * provenance, status, and the losing object's linked source-record owner.
     *
     * @param ObjectEntity $from The object being merged away.
     * @param ObjectEntity $into The surviving object.
     *
     * @return array<string, mixed> The snapshot.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
     */
    public function buildSnapshot(ObjectEntity $from, ObjectEntity $into): array
    {
        $fromUuid = (string) $from->getUuid();
        $intoUuid = (string) $into->getUuid();

        $objects = [];
        foreach ([$fromUuid => $from, $intoUuid => $into] as $uuid => $entity) {
            $data           = ($entity->getObject() ?? []);
            $objects[$uuid] = $data;
        }

        $schema          = $this->loadSchema(object: $from);
        $config          = $this->getMergeConfig(schema: $schema);
        $sourceLinkField = (string) ($config['sourceLinkField'] ?? '');

        $sourceLinks = [];
        if ($sourceLinkField !== '') {
            $fromData = ($from->getObject() ?? []);
            $raw      = ($fromData[$sourceLinkField] ?? []);
            if (is_array($raw) === true) {
                foreach ($raw as $entry) {
                    $sourceUuid = $this->sourceUuidOf(entry: $entry);
                    if ($sourceUuid !== '') {
                        $sourceLinks[$sourceUuid] = $fromUuid;
                    }
                }
            }
        }

        return ['objects' => $objects, 'sourceLinks' => $sourceLinks];
    }//end buildSnapshot()

    /**
     * Compute the reversal deadline from a merge timestamp using the
     * annotation's `reversalWindowDays` (falling back to the service
     * constant), returned as an ISO date string.
     *
     * @param string               $mergedAt Merge timestamp.
     * @param array<string, mixed> $config   `x-openregister-merge` annotation.
     *
     * @return string Reversal deadline (ISO date), or empty on parse error.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
     */
    public function reversalDeadline(string $mergedAt, array $config=[]): string
    {
        $windowDays = self::DEFAULT_REVERSAL_WINDOW_DAYS;
        if (is_numeric($config['reversalWindowDays'] ?? null) === true) {
            $windowDays = (int) $config['reversalWindowDays'];
        }

        try {
            $merged = new DateTimeImmutable($mergedAt);
        } catch (Exception $e) {
            return '';
        }

        return $merged->add(new DateInterval('P'.$windowDays.'D'))->format('Y-m-d');
    }//end reversalDeadline()

    /**
     * Relink the losing object's source records onto the survivor's
     * `sourceLinkField`, returning the updated survivor payload + count.
     *
     * @param array<string, mixed> $fromData        Losing object's payload.
     * @param array<string, mixed> $intoData        Surviving object's payload.
     * @param string               $sourceLinkField Field holding linked source records.
     *
     * @return array{intoData: array<string, mixed>, count: int}
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
     */
    private function relinkSourceRecords(array $fromData, array $intoData, string $sourceLinkField): array
    {
        if ($sourceLinkField === '') {
            return ['intoData' => $intoData, 'count' => 0];
        }

        $fromSources = ($fromData[$sourceLinkField] ?? []);
        if (is_array($fromSources) === false) {
            return ['intoData' => $intoData, 'count' => 0];
        }

        $intoSources = ($intoData[$sourceLinkField] ?? []);
        if (is_array($intoSources) === false) {
            $intoSources = [];
        }

        $merged = array_values(array_unique(array_merge($intoSources, $fromSources), SORT_REGULAR));
        $intoData[$sourceLinkField] = $merged;

        return ['intoData' => $intoData, 'count' => count($fromSources)];
    }//end relinkSourceRecords()

    /**
     * Reverse-FK relink: rewrite each of the losing master's source objects'
     * back-reference field to the surviving master's uuid (a persisted write
     * per source object), returning the list of moves for the reversal
     * snapshot. Unresolvable/failed writes are logged and skipped.
     *
     * @param string               $fromUuid Losing master uuid.
     * @param string               $intoUuid Surviving master uuid.
     * @param array<string, mixed> $config   Survivorship config (carrying the reverse-FK `sourceLink`).
     * @param string               $register Master's register ref — the source register when the
     *                                       annotation omits one (the magic-table query needs it).
     *
     * @return array<int, array{sourceUuid: string, referenceField: string, prior: string}>
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#3.2
     */
    private function relinkReverseFk(string $fromUuid, string $intoUuid, array $config, string $register): array
    {
        $descriptor = $this->sourceRecordResolver->reverseFkDescriptor(config: $config);
        if ($descriptor === null) {
            return [];
        }

        $referenceField = $descriptor['referenceField'];
        $sourceRegister = ($descriptor['sourceRegister'] !== '' ? $descriptor['sourceRegister'] : $register);
        $filters        = [
            'schema'        => $this->sourceRecordResolver->schemaQueryFilter(ref: $descriptor['sourceSchema']),
            $referenceField => $fromUuid,
        ];
        if ($sourceRegister !== '') {
            $filters['register'] = $sourceRegister;
        }

        try {
            $objects = $this->objectService->findAll(['filters' => $filters], _rbac: true, _multitenancy: true);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Reverse-FK relink could not query sources for master "%s": %s', $fromUuid, $e->getMessage())
            );
            return [];
        }

        $moves = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = ($object->getObject() ?? []);
            if ((string) ($data[$referenceField] ?? '') !== $fromUuid) {
                continue;
            }

            $data                  = self::normaliseRoundTripDates(data: $data);
            $data[$referenceField] = $intoUuid;
            $object->setObject($data);

            try {
                $this->objectService->saveObject(
                    object: $object,
                    register: $object->getRegister(),
                    schema: $object->getSchema(),
                    uuid: $object->getUuid()
                );
                $moves[] = [
                    'sourceUuid'     => (string) $object->getUuid(),
                    'referenceField' => $referenceField,
                    'prior'          => $fromUuid,
                ];
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Reverse-FK relink could not move source "%s": %s', (string) $object->getUuid(), $e->getMessage())
                );
            }
        }//end foreach

        return $moves;
    }//end relinkReverseFk()

    /**
     * Normalise OpenRegister's stored `YYYY-MM-DD HH:MM:SS` date-time strings
     * back to ISO-8601 (`...T...+00:00`) before an object is re-saved. OR
     * stores date-time values in a space-separated form that its OWN schema
     * validation later rejects against the `date-time` format on a
     * getObject → mutate → saveObject round-trip; a reverse-FK relink / recompute
     * only mutates one field but re-persists the whole object, so it must
     * normalise the untouched date fields or the save fails. Recurses into
     * nested arrays; leaves all non-date strings untouched.
     *
     * @param array<string, mixed> $data Object payload.
     *
     * @return array<string, mixed> Payload with space-format dates converted to ISO.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#3.2
     */
    public static function normaliseRoundTripDates(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value) === true) {
                $data[$key] = self::normaliseRoundTripDates(data: $value);
                continue;
            }

            if (is_string($value) === true
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
            ) {
                $data[$key] = str_replace(' ', 'T', $value).'+00:00';
            }
        }

        return $data;
    }//end normaliseRoundTripDates()

    /**
     * Recompute the survivor's golden record over the union of both objects'
     * linked source records, via the pure `SurvivorshipResolver`.
     *
     * @param ObjectEntity              $fromObject       Losing object.
     * @param ObjectEntity              $intoObject       Surviving object.
     * @param Schema|null               $schema           Resolved schema (for survivorship config + trust rows).
     * @param array<string, mixed>      $config           `x-openregister-merge` annotation.
     * @param array<string, mixed>|null $intoDataOverride Already-relinked survivor payload, when available.
     *
     * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>}
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    private function recomputeSurvivor(
        ObjectEntity $fromObject,
        ObjectEntity $intoObject,
        ?Schema $schema,
        array $config,
        ?array $intoDataOverride=null
    ): array {
        $survivorshipConfig = $this->getSurvivorshipConfig(schema: $schema);

        $intoData = ($intoDataOverride ?? ($intoObject->getObject() ?? []));
        $fromData = ($fromObject->getObject() ?? []);

        // Resolve the union of both masters' source records via the mode-aware
        // resolver. Embedded: reads each payload's `sourceLinkField`. Reverse-FK:
        // queries each master's uuid — this is correct both for preview (the
        // loser's sources still point at the loser) and after relink at execute
        // time (they now point at the survivor; the loser query then yields
        // none). A single-valued back-reference means no source is double-counted.
        $sources = array_merge(
            $this->sourceRecordResolver->resolveSources(
                masterData: $intoData,
                masterUuid: (string) $intoObject->getUuid(),
                config: $survivorshipConfig,
                masterRegister: (string) $intoObject->getRegister()
            ),
            $this->sourceRecordResolver->resolveSources(
                masterData: $fromData,
                masterUuid: (string) $fromObject->getUuid(),
                config: $survivorshipConfig,
                masterRegister: (string) $fromObject->getRegister()
            )
        );

        $entityType = (string) (
            $config['entityType'] ?? $survivorshipConfig['entityType'] ?? ($schema?->getSlug() ?? '')
        );

        $trustRows = $this->loadTrustRows(entityType: $entityType);

        return $this->resolver->resolveGoldenRecord(
            entityType: $entityType,
            sourceRecords: $sources,
            config: $survivorshipConfig,
            trustRows: $trustRows,
            trustResolver: $this->trustResolver,
            asOf: new DateTimeImmutable()
        );
    }//end recomputeSurvivor()

    /**
     * Best-effort restoration of one object's snapshot state. Unresolvable
     * objects are skipped rather than aborting the whole reversal.
     *
     * @param string               $uuid  Object uuid to restore.
     * @param array<string, mixed> $state Snapshot state (full pre-merge payload).
     *
     * @return void
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.4
     */
    private function restoreObjectState(string $uuid, array $state): void
    {
        try {
            $entity = $this->objectService->find(id: $uuid, _rbac: true, _multitenancy: true);
            if ($entity === null) {
                return;
            }

            $entity->setObject($state);
            $this->objectService->saveObject(
                object: $entity,
                register: $entity->getRegister(),
                schema: $entity->getSchema(),
                uuid: $entity->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Merge reversal could not restore object "%s": %s', $uuid, $e->getMessage())
            );
        }
    }//end restoreObjectState()

    /**
     * Best-effort restoration of one source-record's owner link. Unresolvable
     * or moved records are skipped rather than aborting the reversal.
     *
     * @param string $sourceUuid Source-record uuid.
     * @param mixed  $link       Snapshot owner reference (the prior owner uuid).
     *
     * @return void
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.4
     */
    private function restoreSourceLink(string $sourceUuid, $link): void
    {
        try {
            $entity = $this->objectService->find(id: $sourceUuid, _rbac: true, _multitenancy: true);
            if ($entity === null) {
                return;
            }

            $this->logger->debug(
                sprintf('Merge reversal: source "%s" prior owner "%s" (best-effort log only).', $sourceUuid, (string) $link)
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Merge reversal could not resolve source record "%s": %s', $sourceUuid, $e->getMessage())
            );
        }
    }//end restoreSourceLink()

    /**
     * Reverse-FK reversal: rewrite one moved source object's back-reference
     * field back to its pre-merge master uuid. Best-effort — an unresolvable
     * source or a failed write is logged and skipped rather than aborting the
     * whole reversal.
     *
     * @param array{sourceUuid?: string, referenceField?: string, prior?: string} $move Recorded move.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#3.3
     */
    private function restoreReverseFkMove(array $move): void
    {
        $sourceUuid     = (string) ($move['sourceUuid'] ?? '');
        $referenceField = (string) ($move['referenceField'] ?? '');
        $prior          = (string) ($move['prior'] ?? '');
        if ($sourceUuid === '' || $referenceField === '') {
            return;
        }

        try {
            $entity = $this->objectService->find(id: $sourceUuid, _rbac: true, _multitenancy: true);
            if ($entity === null) {
                return;
            }

            $data = self::normaliseRoundTripDates(data: ($entity->getObject() ?? []));
            $data[$referenceField] = $prior;
            $entity->setObject($data);
            $this->objectService->saveObject(
                object: $entity,
                register: $entity->getRegister(),
                schema: $entity->getSchema(),
                uuid: $entity->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Merge reversal could not restore source back-reference "%s": %s', $sourceUuid, $e->getMessage())
            );
        }
    }//end restoreReverseFkMove()

    /**
     * Resolve a source-record entry's own uuid (for the snapshot's
     * `sourceLinks` map), from either an embedded record or a plain
     * uuid/id reference string.
     *
     * @param mixed $entry Source-record entry.
     *
     * @return string Resolved uuid, or empty when unresolvable.
     */
    private function sourceUuidOf($entry): string
    {
        if (is_string($entry) === true) {
            return $entry;
        }

        if (is_array($entry) === true) {
            return (string) ($entry['id'] ?? ($entry['uuid'] ?? ''));
        }

        return '';
    }//end sourceUuidOf()

    /**
     * Load a readable object by uuid, RBAC + tenant scoped.
     *
     * @param string $uuid Object uuid.
     *
     * @return ObjectEntity The resolved object.
     *
     * @throws RuntimeException When the object cannot be found/read.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    private function loadReadable(string $uuid): ObjectEntity
    {
        try {
            $object = $this->objectService->find(id: $uuid, _rbac: true, _multitenancy: true);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('Object "%s" is not readable: %s', $uuid, $e->getMessage()));
        }

        if ($object === null) {
            throw new RuntimeException(sprintf('Object "%s" was not found.', $uuid));
        }

        return $object;
    }//end loadReadable()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (Throwable) {
            return null;
        }
    }//end loadSchema()

    /**
     * Read the `x-openregister-merge` configuration block.
     *
     * @param Schema|null $schema Schema to inspect.
     *
     * @return array<string, mixed> Merge config (empty array when absent — callers fall back to defaults).
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
     */
    private function getMergeConfig(?Schema $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-merge'] ?? null);
        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end getMergeConfig()

    /**
     * Read the `x-openregister-survivorship` configuration block.
     *
     * @param Schema|null $schema Schema to inspect.
     *
     * @return array<string, mixed> Survivorship config (empty array when absent).
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    private function getSurvivorshipConfig(?Schema $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-survivorship'] ?? null);
        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end getSurvivorshipConfig()

    /**
     * Load the candidate trust-configuration rows for an entity type via the
     * OR-owned `trustConfiguration` register (RBAC + tenant scoped).
     *
     * @param string $entityType Entity type to scope the lookup.
     *
     * @return array<int, array<string, mixed>> Trust-configuration rows.
     *
     * @spec openspec/changes/mdm-merge-engine/tasks.md#4.2
     */
    private function loadTrustRows(string $entityType): array
    {
        if ($entityType === '') {
            return [];
        }

        try {
            $objects = $this->objectService->findAll(
                [
                    'filters' => [
                        'schema'     => self::TRUST_CONFIGURATION_SCHEMA,
                        'entityType' => $entityType,
                    ],
                ],
                _rbac: true,
                _multitenancy: true
            );
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $rows[] = ($object->getObject() ?? []);
            }
        }

        return $rows;
    }//end loadTrustRows()
}//end class
