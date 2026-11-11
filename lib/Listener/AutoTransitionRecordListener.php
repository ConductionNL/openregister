<?php

/**
 * OpenRegister AutoTransitionRecordListener
 *
 * Notes that an object was written, so the request-scoped pass can decide its
 * automatic transitions once the write is finished. It records and does
 * nothing else.
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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelectorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Records written objects for the pass. It never evaluates and never writes.
 *
 * THE LISTENER RECORDS AND NOTHING MORE, and that is a load-bearing property
 * rather than tidiness. `ObjectUpdatedEvent` is dispatched in the MIDDLE of a
 * save: the save's own audit row is still to be written, and a save carrying a
 * file property updates the object a second time from an in-memory entity that
 * still holds the old lifecycle value. A transition applied here would be lost
 * to that second write, behind a transition event that had already fired. It
 * would also invert event order for a named transition, whose own
 * `ObjectTransitionedEvent` is dispatched after its save returns.
 *
 * Because there is no write inside the user's save here, there is nothing for
 * the listener-placement gate (ADR-078) to annotate or defer.
 *
 * `ObjectCreatedEvent` is subscribed to as well as `ObjectUpdatedEvent`: a rule
 * is about the state an object is in, not how it got there, and "created
 * complete, so it goes straight to review" is a real use. `previous` is empty
 * on a create. `ObjectTransitionedEvent` is deliberately NOT subscribed to: a
 * named transition's save already dispatched `ObjectUpdatedEvent`, so listening
 * to both would record every named transition twice.
 *
 * @implements IEventListener<Event>
 */
class AutoTransitionRecordListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface           $schemaMapper Resolves the written object's schema.
	 * @param AutoTransitionSelectorInterface $selector     Answers the cheap "does this schema declare any autoWhen" read.
	 * @param AutoTransitionPassInterface     $pass         The request-scoped pass the object is recorded in.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly AutoTransitionSelectorInterface $selector,
		private readonly AutoTransitionPassInterface $pass,
	) {
	}//end __construct()

	/**
	 * Record the written object when its schema declares an `autoWhen`.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		// One instanceof check per branch so static analysis can narrow
		// `$event` to the class that carries getObject().
		if ($event instanceof ObjectUpdatedEvent) {
			$this->recordWrite(object: $event->getObject(), old: $event->getOldObject());
			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->recordWrite(object: $event->getObject(), old: null);
		}
	}//end handle()

	/**
	 * Note a written object whose schema declares `autoWhen` rules.
	 *
	 * @param ObjectEntity      $object The object as written.
	 * @param ObjectEntity|null $old    The object before an update, or null on create.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function recordWrite(ObjectEntity $object, ?ObjectEntity $old): void {
		$schemaRef = (string)$object->getSchema();
		if ($this->schemaDeclaresAutoWhen(schemaRef: $schemaRef) === false) {
			return;
		}

		$this->pass->record(
			ref: new ObjectRef(register: (string)$object->getRegister(), schema: $schemaRef, id: (string)$object->getUuid()),
			previous: $this->dataOf(object: $old)
		);
	}//end recordWrite()

	/**
	 * Whether the schema declares at least one automatic transition.
	 *
	 * Every write to a schema WITHOUT one pays this lookup and nothing else.
	 * A resolution failure records nothing: a schema that cannot be read cannot
	 * be shown to declare a rule, and guessing would move objects on a guess.
	 *
	 * @param string $schemaRef The object's schema reference.
	 *
	 * @return bool True when the schema declares an `autoWhen`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function schemaDeclaresAutoWhen(string $schemaRef): bool {
		if ($schemaRef === '') {
			return false;
		}

		try {
			$schema = $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (Throwable $e) {
			return false;
		}

		$annotation = (($schema->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return false;
		}

		return $this->selector->declaresAutoWhen(annotation: $annotation);
	}//end schemaDeclaresAutoWhen()

	/**
	 * The object data an entity carries, or an empty array when there is none.
	 *
	 * @param ObjectEntity|null $object The entity, when the event carried one.
	 *
	 * @return array<string, mixed> The object's data.
	 */
	private function dataOf(?ObjectEntity $object): array {
		if ($object === null) {
			return [];
		}

		return ($object->getObject() ?? []);
	}//end dataOf()
}//end class
