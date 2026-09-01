<?php

/**
 * OpenRegister TranslationProjectionListener
 *
 * Subscribes to ObjectCreated/Updated/Deleted/Transitioned events
 * and keeps the `openregister_translations` sidecar in sync with the
 * JSONB property data on the object. Same pattern as the realtime
 * event listener — derived-projection-by-event.
 *
 * Actor-forwarded deferral (openregister#408): for schemas with
 * translatable properties the heavy reconciliation runs in
 * TranslationProjectionJob under the captured actor (translator
 * attribution needs the session user). What stays inline: the cheap
 * stale-row prune for schemas WITHOUT translatable properties (parity
 * with the pre-deferral behaviour), delete-time purge (bounded DELETE;
 * the entity is not re-fetchable post-delete), and the whole projection
 * when the `listenerDeferral` kill switch is set to `inline`.
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

use OCA\OpenRegister\BackgroundJob\TranslationProjectionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that projects object changes into the translations sidecar.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 *
 * @spec openspec/changes/actor-forwarded-listener-jobs/tasks.md#task-2.1
 */
class TranslationProjectionListener implements IEventListener {

	/**
	 * Entries per enqueued projection job.
	 *
	 * @var integer
	 */
	private const CHUNK_SIZE = 100;

	/**
	 * Wire the translation-projection service and the deferral contract.
	 *
	 * @param TranslationProjectionService $projection Projection service.
	 * @param SchemaMapper $schemaMapper Schema lookup (request-cached) for the enqueue gate.
	 * @param TranslationHandler $translationHandler Translatable-property gate.
	 * @param ListenerDeferralService $deferral Actor-forwarding deferral service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly TranslationProjectionService $projection,
		private readonly SchemaMapper $schemaMapper,
		private readonly TranslationHandler $translationHandler,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Project (or purge) translation rows for the inbound event.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletedEvent) {
			// Delete-time purge stays inline: bounded sidecar DELETE and
			// the object row is not re-fetchable once the delete lands.
			$this->projection->purge($event->getObject());
			return;
		}

		$object = $this->extractObject(event: $event);
		if ($object === null) {
			return;
		}

		$this->projectOrDefer(object: $object);
	}//end handle()

	/**
	 * Route a live object either to the deferred job or the inline path.
	 *
	 * @param ObjectEntity $object Object that changed.
	 *
	 * @return void
	 */
	private function projectOrDefer(ObjectEntity $object): void {
		if ($this->deferral->isDeferralEnabled() === false || $this->hasTranslatableProperties(object: $object) === false) {
			// Kill switch → full pre-deferral behaviour. No translatable
			// properties → project() only performs the cheap stale-row
			// prune (parity with pre-deferral behaviour, and enqueueing a
			// job for every schema-less save would defeat the gate).
			$this->projection->project($object);
			return;
		}

		$this->deferral->defer(
			jobClass: TranslationProjectionJob::class,
			entry: [
				'uuid' => (string)$object->getUuid(),
				'register' => (string)$object->getRegister(),
				'schema' => (string)$object->getSchema(),
				'version' => $object->getVersion(),
			],
			chunkSize: self::CHUNK_SIZE,
			dedupeKey: (string)$object->getUuid()
		);
	}//end projectOrDefer()

	/**
	 * Whether the object's schema declares at least one translatable property.
	 *
	 * Schema resolution failures return false so the inline path (which
	 * re-resolves and bails safely) handles the event.
	 *
	 * @param ObjectEntity $object Object whose schema to inspect.
	 *
	 * @return bool True when the projection has real work to defer.
	 */
	private function hasTranslatableProperties(ObjectEntity $object): bool {
		$schemaRef = (string)$object->getSchema();
		if ($schemaRef === '') {
			return false;
		}

		try {
			$schema = $this->schemaMapper->find($schemaRef);
		} catch (\Throwable $e) {
			return false;
		}

		return count($this->translationHandler->getTranslatableProperties($schema)) > 0;
	}//end hasTranslatableProperties()

	/**
	 * Different Object*Event classes expose the entity under different
	 * accessors. Normalise to one.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return ObjectEntity|null Object instance, or null when none could be derived.
	 */
	private function extractObject(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectTransitionedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end extractObject()
}//end class
