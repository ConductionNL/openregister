<?php

/**
 * A recorded transition becomes an interval a list query can join.
 *
 * 🔴 THE LISTENER NEVER FAILS THE TRANSITION. The projection is derived and
 * rebuildable; the move is not. A projection write that throws is logged and
 * swallowed, because the alternative is that a filter's index takes down the
 * act of moving a case forward.
 *
 * 🔴 THE PROPERTY COMES FROM THE SCHEMA, not from the event and not from the
 * object's payload. The event names the action and the two states; it does not
 * name the field, and inferring one from the write would project whatever the
 * pipeline attached. The projector resolves it from
 * `x-openregister-lifecycle.field`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\History\StateHistoryRecorderInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Projects a transition into the state-history table.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class StateHistoryProjectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StateHistoryRecorderInterface $projector    Writes the interval.
	 * @param SchemaMapperInterface          $schemaMapper Resolves the object's schema.
	 * @param LoggerInterface       $logger       Diagnostics.
	 */
	public function __construct(
		private readonly StateHistoryRecorderInterface $projector,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the transition.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent) {
			$this->projectSafely(event: $event);
		}
	}//end handle()

	/**
	 * Project the transition; a failure is logged and never reaches the move.
	 *
	 * @param ObjectTransitionedEvent $event The committed transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function projectSafely(ObjectTransitionedEvent $event): void {
		try {
			$this->project(event: $event);
		} catch (\Throwable $e) {
			// The projection is derived and rebuildable; the transition is
			// not. A failure here must never travel back into the move.
			$this->logger->warning(
				'[StateHistoryProjectionListener] Could not project a transition: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
		}//end try
	}//end projectSafely()

	/**
	 * Record the transition's target state for an object that has a uuid.
	 *
	 * @param ObjectTransitionedEvent $event The committed transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function project(ObjectTransitionedEvent $event): void {
		if ((string)$event->getObject()->getUuid() === '') {
			return;
		}

		$this->projector->record(
			move: $event,
			schema: $this->resolveSchema(event: $event),
			stampedAt: new DateTime()
		);
	}//end project()

	/**
	 * Resolve the schema whose declaration names the projected property.
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 */
	private function resolveSchema(ObjectTransitionedEvent $event): ?Schema {
		try {
			return $this->schemaMapper->find($event->getObject()->getSchema(), _multitenancy: false, _rbac: false);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[StateHistoryProjectionListener] Schema unresolvable, nothing projected: {error}',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveSchema()
}//end class
