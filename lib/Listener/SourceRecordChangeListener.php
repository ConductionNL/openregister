<?php

/**
 * OpenRegister SourceRecordChangeListener
 *
 * Keeps a reverse-FK master's golden record current when one of its source
 * objects changes. In a reverse-FK relationship the master's survivorship
 * annotation declares `sourceLink.reverseFk` with a `sourceSchema` +
 * `referenceField`; source objects of that schema carry the master's uuid in
 * `referenceField`. Saving or deleting such a source object must recompute the
 * referenced master's golden record — the master's own recompute listener only
 * fires on a master save, so without this trigger a source edit would leave the
 * golden record stale.
 *
 * On update the listener recomputes both the new and the prior referenced
 * master (so reassigning a source to a different master refreshes both). Each
 * master recompute is best-effort: a failure is logged and never aborts the
 * source object's own save/delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recompute a reverse-FK master when one of its source objects changes.
 *
 * Also subscribed to schema lifecycle events so the cross-request reverse-FK
 * index cache is invalidated the moment a schema (and therefore any reverse-FK
 * sourceLink declaration) changes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|SchemaCreatedEvent|SchemaUpdatedEvent|SchemaDeletedEvent>
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Handles both object and schema lifecycle events plus the index cache
 */
class SourceRecordChangeListener implements IEventListener
{

    /**
     * Distributed-cache key holding the serialized reverse index.
     *
     * @var string
     */
    private const REVERSE_INDEX_CACHE_KEY = 'reverse_fk_index';

    /**
     * Time-to-live for the cached reverse index, in seconds. The index is
     * additionally invalidated eagerly on every schema create/update/delete
     * event, so the TTL is only a safety net against missed invalidations
     * (e.g. schema changes applied through raw SQL or another instance
     * without a distributed cache).
     *
     * @var int
     */
    private const REVERSE_INDEX_CACHE_TTL = 3600;

    /**
     * Memoised reverse index: source-schema identifier (slug or id, as
     * strings) => list of reference-field names that carry the master uuid.
     * Built lazily from the schema registry, cached per listener instance
     * and shared across requests through the distributed cache — building
     * it requires loading EVERY schema, which is far too expensive to do
     * on the first object write of every request.
     *
     * @var array<string, array<int, string>>|null
     */
    private ?array $reverseIndex = null;

    /**
     * Distributed cache holding the reverse index across requests.
     *
     * @var ICache|null
     */
    private ?ICache $indexCache = null;

    /**
     * Wire collaborators.
     *
     * @param SchemaMapper    $schemaMapper  Schema registry (to build the reverse index).
     * @param ObjectService   $objectService Object read/write path (RBAC + tenant scoped).
     * @param LoggerInterface $logger        PSR logger for warnings.
     * @param ICacheFactory   $cacheFactory  Distributed-cache factory for the reverse index.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory
    ) {
        try {
            $this->indexCache = $cacheFactory->createDistributed('openregister_reverse_fk');
        } catch (Throwable $e) {
            $this->indexCache = null;
        }
    }//end __construct()

    /**
     * Dispatch: recompute the referenced master(s) after a source object is
     * created, updated, or deleted, and invalidate the cached reverse index
     * when a schema changes.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    public function handle(Event $event): void
    {
        try {
            if ($event instanceof SchemaCreatedEvent
                || $event instanceof SchemaUpdatedEvent
                || $event instanceof SchemaDeletedEvent
            ) {
                // A schema change can add/remove reverse-FK sourceLink
                // declarations — drop the cached index so it is rebuilt.
                $this->reverseIndex = null;
                $this->indexCache?->remove(self::REVERSE_INDEX_CACHE_KEY);
                return;
            }

            if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectDeletedEvent) {
                $this->processSource(object: $event->getObject(), oldObject: null);
                return;
            }

            if ($event instanceof ObjectUpdatedEvent) {
                $this->processSource(object: $event->getNewObject(), oldObject: $event->getOldObject());
                return;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Source-record change recompute failed: '.$e->getMessage());
        }//end try
    }//end handle()

    /**
     * Recompute the master(s) referenced by a changed source object.
     *
     * @param ObjectEntity      $object    The changed source object.
     * @param ObjectEntity|null $oldObject The pre-change source object (updates only).
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    private function processSource(ObjectEntity $object, ?ObjectEntity $oldObject): void
    {
        $referenceFields = $this->referenceFieldsFor(object: $object);
        if (empty($referenceFields) === true) {
            return;
        }

        $data    = ($object->getObject() ?? []);
        $oldData = [];
        if ($oldObject !== null) {
            $oldData = ($oldObject->getObject() ?? []);
        }

        $masterUuids = [];
        foreach ($referenceFields as $referenceField) {
            $masterUuids[] = (string) ($data[$referenceField] ?? '');
            $masterUuids[] = (string) ($oldData[$referenceField] ?? '');
        }

        foreach (array_unique(array_filter($masterUuids)) as $masterUuid) {
            $this->recomputeMaster(masterUuid: (string) $masterUuid);
        }
    }//end processSource()

    /**
     * The reference-field names that make the given object a reverse-FK source
     * of some master schema, or an empty array when it is not a source object.
     *
     * @param ObjectEntity $object The saved object.
     *
     * @return array<int, string> Reference-field names (may be empty).
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    private function referenceFieldsFor(ObjectEntity $object): array
    {
        $schema = $this->resolveSchema(ref: (string) $object->getSchema());
        if ($schema === null) {
            return [];
        }

        $index      = $this->reverseIndex();
        $candidates = [(string) $schema->getId(), (string) $schema->getSlug()];

        $fields = [];
        foreach ($candidates as $key) {
            if ($key !== '' && isset($index[$key]) === true) {
                $fields = array_merge($fields, $index[$key]);
            }
        }

        return array_values(array_unique($fields));
    }//end referenceFieldsFor()

    /**
     * Build (once) the reverse index of source-schema identifier => reference
     * fields, from every schema whose survivorship annotation declares a
     * reverse-FK `sourceLink`.
     *
     * @return array<string, array<int, string>>
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    private function reverseIndex(): array
    {
        if ($this->reverseIndex !== null) {
            return $this->reverseIndex;
        }

        // Cross-request cache: building the index loads EVERY schema, which
        // would otherwise run on the first object write of every request.
        // Invalidated eagerly on Schema created/updated/deleted events.
        $cached = $this->indexCache?->get(self::REVERSE_INDEX_CACHE_KEY);
        if (is_array($cached) === true) {
            $this->reverseIndex = $cached;
            return $this->reverseIndex;
        }

        $index = [];
        try {
            $schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('Source-record change: could not load schemas for reverse index: '.$e->getMessage());
            $this->reverseIndex = [];
            return $this->reverseIndex;
        }

        foreach ($schemas as $schema) {
            foreach ($this->reverseLinksFor(schema: $schema) as $link) {
                $index[$link['sourceSchema']][] = $link['referenceField'];
            }
        }

        // De-duplicate reference fields per source schema.
        foreach ($index as $key => $fields) {
            $index[$key] = array_values(array_unique($fields));
        }

        $this->reverseIndex = $index;
        $this->indexCache?->set(self::REVERSE_INDEX_CACHE_KEY, $index, self::REVERSE_INDEX_CACHE_TTL);

        return $this->reverseIndex;
    }//end reverseIndex()

    /**
     * Extract the reverse-FK `sourceLink` declarations from a single schema's
     * survivorship + merge annotations.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<int, array{sourceSchema: string, referenceField: string}>
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    private function reverseLinksFor(Schema $schema): array
    {
        $config = ($schema->getConfiguration() ?? []);

        $links = [];
        foreach (['x-openregister-survivorship', 'x-openregister-merge'] as $annotationKey) {
            $sourceLink = ($config[$annotationKey]['sourceLink'] ?? null);
            if (is_array($sourceLink) === false
                || (string) ($sourceLink['mode'] ?? 'embedded') !== 'reverseFk'
            ) {
                continue;
            }

            $sourceSchema   = (string) ($sourceLink['sourceSchema'] ?? '');
            $referenceField = (string) ($sourceLink['referenceField'] ?? '');
            if ($sourceSchema === '' || $referenceField === '') {
                continue;
            }

            $links[] = [
                'sourceSchema'   => $sourceSchema,
                'referenceField' => $referenceField,
            ];
        }//end foreach

        return $links;
    }//end reverseLinksFor()

    /**
     * Recompute a master's golden record by re-persisting it, which re-runs
     * the survivorship recompute listener over the master's (reverse-FK)
     * source set. Best-effort — a failure is logged and swallowed.
     *
     * @param string $masterUuid Referenced master uuid.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `MergeService::normaliseRoundTripDates`
     *   is a pure stateless date-format utility shared with the merge relink;
     *   a static call is the lightest way to reuse it without injecting the
     *   whole MergeService (which would raise coupling for one helper).
     */
    private function recomputeMaster(string $masterUuid): void
    {
        if ($masterUuid === '') {
            return;
        }

        try {
            $master = $this->objectService->find(id: $masterUuid, _rbac: true, _multitenancy: true);
            if ($master === null) {
                return;
            }

            // Re-persist: the survivorship recompute listener fires on the
            // resulting update event and rematerialises the golden record. Date
            // fields are normalised back to ISO first — OR stores them in a
            // space-separated form its own validation rejects on a round-trip
            // save (see MergeService::normaliseRoundTripDates).
            $master->setObject(MergeService::normaliseRoundTripDates(data: ($master->getObject() ?? [])));
            $this->objectService->saveObject(
                object: $master,
                register: $master->getRegister(),
                schema: $master->getSchema(),
                uuid: $master->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Source-record change: could not recompute master "%s": %s', $masterUuid, $e->getMessage())
            );
        }//end try
    }//end recomputeMaster()

    /**
     * Resolve a schema by reference (id/uuid/slug), or null on failure.
     *
     * @param string $ref Schema reference.
     *
     * @return Schema|null Resolved schema.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#4.1
     */
    private function resolveSchema(string $ref): ?Schema
    {
        if ($ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (Throwable) {
            return null;
        }
    }//end resolveSchema()
}//end class
