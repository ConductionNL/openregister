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
 * The recompute itself is DEFERRED to `SourceRecordRecomputeJob` (ADR-078,
 * openregister#2420). It used to run synchronously here — a full
 * `ObjectService::saveObject()` nested inside the write that triggered it,
 * twice on a reassigning update — which is the write-inside-a-write shape
 * that serialised every object write on a live instance on 2026-08-11.
 * Deferring also coalesces: entries are deduped on the master uuid, so N
 * sources of one master enqueue ONE recompute instead of N. Setting
 * `openregister.listener_deferral` to `inline` restores the old synchronous
 * behaviour for debugging.
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

use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralInterface;
use OCA\OpenRegister\Service\Merge\MasterRecomputeService;
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
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Handles both object and schema lifecycle events plus the index cache
 */
class SourceRecordChangeListener implements IEventListener {

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
	 * Maximum master uuids carried by one deferred recompute job.
	 *
	 * Entries are deduped on the master uuid, so this is a bound on DISTINCT
	 * masters touched by one request, not on the number of source writes.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 50;

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
	 * @param SchemaMapperInterface $schemaMapper Schema registry (to build the reverse index).
	 * @param LoggerInterface $logger PSR logger for warnings.
	 * @param ICacheFactory $cacheFactory Distributed-cache factory for the reverse index.
	 * @param ListenerDeferralInterface $deferral Actor-forwarding deferral service (ADR-078).
	 * @param MasterRecomputeService $recompute Golden-record recompute, used on the inline fallback only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		private readonly ListenerDeferralInterface $deferral,
		private readonly MasterRecomputeService $recompute,
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
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	public function handle(Event $event): void {
		try {
			$this->dispatch(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('Source-record change recompute failed: ' . $e->getMessage());
		}
	}//end handle()

	/**
	 * Route one event: a schema change drops the reverse index, an object change recomputes its masters.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function dispatch(Event $event): void {
		if (self::isSchemaEvent(event: $event) === true) {
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
		}
	}//end dispatch()

	/**
	 * Whether an event is a schema lifecycle event.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return bool True for a schema created, updated or deleted event.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private static function isSchemaEvent(Event $event): bool {
		return $event instanceof SchemaCreatedEvent
			|| $event instanceof SchemaUpdatedEvent
			|| $event instanceof SchemaDeletedEvent;
	}//end isSchemaEvent()

	/**
	 * Recompute the master(s) referenced by a changed source object.
	 *
	 * ADR-078 / openregister#2420: the recompute is a full
	 * ObjectService::saveObject(), so it MUST NOT run inside the write
	 * that triggered it. Deferring also lets the buffer coalesce on the
	 * master uuid — N sources of one master become ONE recompute, which
	 * is the ordinary shape of a reverse-FK relationship. The inline
	 * branch exists only for the `inline` kill switch.
	 *
	 * @param ObjectEntity $object The changed source object.
	 * @param ObjectEntity|null $oldObject The pre-change source object (updates only).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function processSource(ObjectEntity $object, ?ObjectEntity $oldObject): void {
		$referenceFields = $this->referenceFieldsFor(object: $object);
		if (empty($referenceFields) === true) {
			return;
		}

		$masterUuids = self::masterUuidsOf(referenceFields: $referenceFields, object: $object, oldObject: $oldObject);
		$deferralEnabled = $this->deferral->isDeferralEnabled();
		foreach ($masterUuids as $masterUuid) {
			$this->queueRecompute(masterUuid: $masterUuid, deferralEnabled: $deferralEnabled);
		}
	}//end processSource()

	/**
	 * The distinct, non-empty master uuids a source names in its reference
	 * fields, now and (for an update) before the change.
	 *
	 * @param array<int, string> $referenceFields The reference-field names.
	 * @param ObjectEntity $object The changed source object.
	 * @param ObjectEntity|null $oldObject The pre-change source object (updates only).
	 *
	 * @return array<int|string, string> The master uuids.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private static function masterUuidsOf(array $referenceFields, ObjectEntity $object, ?ObjectEntity $oldObject): array {
		$data = ($object->getObject() ?? []);
		$oldData = ($oldObject?->getObject() ?? []);

		$masterUuids = [];
		foreach ($referenceFields as $referenceField) {
			$masterUuids[] = (string)($data[$referenceField] ?? '');
			$masterUuids[] = (string)($oldData[$referenceField] ?? '');
		}

		return array_unique(array_filter($masterUuids));
	}//end masterUuidsOf()

	/**
	 * Recompute one master inline (kill switch) or defer it, coalesced on its uuid.
	 *
	 * @param string $masterUuid The master to recompute.
	 * @param bool $deferralEnabled Whether deferral is on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function queueRecompute(string $masterUuid, bool $deferralEnabled): void {
		if ($deferralEnabled === false) {
			$this->recompute->recompute(masterUuid: $masterUuid);
			return;
		}

		$this->deferral->defer(
			jobClass: SourceRecordRecomputeJob::class,
			entry: ['masterUuid' => $masterUuid],
			chunkSize: self::CHUNK_SIZE,
			dedupeKey: $masterUuid
		);
	}//end queueRecompute()

	/**
	 * The reference-field names that make the given object a reverse-FK source
	 * of some master schema, or an empty array when it is not a source object.
	 *
	 * @param ObjectEntity $object The saved object.
	 *
	 * @return array<int, string> Reference-field names (may be empty).
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function referenceFieldsFor(ObjectEntity $object): array {
		$schema = $this->resolveSchema(ref: (string)$object->getSchema());
		if ($schema === null) {
			return [];
		}

		$index = $this->reverseIndex();
		$candidates = [(string)$schema->getId(), (string)$schema->getSlug()];

		$fields = [];
		foreach ($candidates as $key) {
			if ($key !== '' && isset($index[$key]) === true) {
				$fields = array_merge($fields, $index[$key]);
			}
		}

		return array_values(array_unique($fields));
	}//end referenceFieldsFor()

	/**
	 * The reverse index of source-schema identifier => reference fields:
	 * memoised on the instance, then the cross-request cache, then built.
	 *
	 * Cross-request cache: building the index loads EVERY schema, which
	 * would otherwise run on the first object write of every request.
	 * Invalidated eagerly on Schema created/updated/deleted events.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function reverseIndex(): array {
		if ($this->reverseIndex === null) {
			$cached = $this->indexCache?->get(self::REVERSE_INDEX_CACHE_KEY);
			if (is_array($cached) === true) {
				$this->reverseIndex = $cached;
				return $this->reverseIndex;
			}

			$this->reverseIndex = $this->buildReverseIndex();
		}

		return $this->reverseIndex;
	}//end reverseIndex()

	/**
	 * Build the reverse index from every schema whose survivorship or merge
	 * annotation declares a reverse-FK `sourceLink`, and cache it. When the
	 * schemas cannot be loaded the index is empty and NOT cached.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function buildReverseIndex(): array {
		try {
			$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Source-record change: could not load schemas for reverse index: ' . $e->getMessage());
			return [];
		}

		$index = $this->indexOf(schemas: $schemas);
		$this->indexCache?->set(self::REVERSE_INDEX_CACHE_KEY, $index, self::REVERSE_INDEX_CACHE_TTL);

		return $index;
	}//end buildReverseIndex()

	/**
	 * Group the reverse-FK links of the given schemas by source schema, each
	 * source's reference fields de-duplicated.
	 *
	 * @param array<int, Schema> $schemas The schemas.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function indexOf(array $schemas): array {
		$index = [];
		foreach ($schemas as $schema) {
			foreach ($this->reverseLinksFor(schema: $schema) as $link) {
				$index[$link['sourceSchema']][] = $link['referenceField'];
			}
		}

		return array_map(static fn (array $fields): array => array_values(array_unique($fields)), $index);
	}//end indexOf()

	/**
	 * Extract the reverse-FK `sourceLink` declarations from a single schema's
	 * survivorship + merge annotations.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<int, array{sourceSchema: string, referenceField: string}>
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function reverseLinksFor(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);

		$links = [];
		foreach (['x-openregister-survivorship', 'x-openregister-merge'] as $annotationKey) {
			$link = self::reverseLinkOf(sourceLink: ($config[$annotationKey]['sourceLink'] ?? null));
			if ($link !== null) {
				$links[] = $link;
			}
		}

		return $links;
	}//end reverseLinksFor()

	/**
	 * One annotation's `sourceLink` as a reverse-FK link, or null when it is
	 * not a complete reverse-FK declaration.
	 *
	 * @param mixed $sourceLink The annotation's `sourceLink` value.
	 *
	 * @return array{sourceSchema: string, referenceField: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private static function reverseLinkOf(mixed $sourceLink): ?array {
		if (self::declaresReverseFk(sourceLink: $sourceLink) === false) {
			return null;
		}

		$link = [
			'sourceSchema' => (string)($sourceLink['sourceSchema'] ?? ''),
			'referenceField' => (string)($sourceLink['referenceField'] ?? ''),
		];
		if (in_array('', $link, true) === true) {
			return null;
		}

		return $link;
	}//end reverseLinkOf()

	/**
	 * Whether a `sourceLink` value is a block in reverse-FK mode (the mode defaults to embedded).
	 *
	 * @param mixed $sourceLink The annotation's `sourceLink` value.
	 *
	 * @return bool True for a reverse-FK block.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private static function declaresReverseFk(mixed $sourceLink): bool {
		return is_array($sourceLink) === true && (string)($sourceLink['mode'] ?? 'embedded') === 'reverseFk';
	}//end declaresReverseFk()

	/**
	 * Resolve a schema by reference (id/uuid/slug), or null on failure.
	 *
	 * @param string $ref Schema reference.
	 *
	 * @return Schema|null Resolved schema.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#4.1
	 */
	private function resolveSchema(string $ref): ?Schema {
		$schema = null;
		if ($ref !== '') {
			try {
				$schema = $this->schemaMapper->find($ref, _multitenancy: false);
			} catch (Throwable) {
				$schema = null;
			}
		}

		return $schema;
	}//end resolveSchema()
}//end class
