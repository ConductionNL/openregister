<?php

/**
 * OpenRegister Archival Nomination Listener
 *
 * Nominates an object for archiving the moment its business use ends, which is
 * the moment it reaches a lifecycle state its schema declares as final.
 *
 * 🔴 THE TRANSITION HAS ALREADY HAPPENED BY THE TIME THIS RUNS, so a failure
 * here must not undo it. A record that could not be nominated is reported on
 * itself and in the log, and the closure stands.
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
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Archival\ArchivalNominationServiceInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationTriggerInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derive and write the archival nomination when an object closes.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 */
class ArchivalNominationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ArchivalNominationTriggerInterface $nominations  Derives and writes the nomination.
	 * @param SchemaMapperInterface              $schemaMapper Loads the transitioned object's schema.
	 * @param MagicMapperInterface               $objectMapper Persists the nominated record.
	 * @param LoggerInterface           $logger       Where a failure is reported.
	 */
	public function __construct(
		private readonly ArchivalNominationTriggerInterface $nominations,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Nominate the object when the state it reached is an end.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$schema = $this->schemaOf(object: $object);
		if ($schema === null || $this->nominations->isTerminalState(schema: $schema, state: $event->getTo()) === false) {
			return;
		}

		$this->nominateAndPersist(object: $object, schema: $schema);
	}//end handle()

	/**
	 * The object's schema, or null when it cannot be loaded.
	 *
	 * @param ObjectEntity $object The transitioned object.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		try {
			return $this->schemaMapper->find(id: (string)$object->getSchema());
		} catch (Throwable $e) {
			return null;
		}
	}//end schemaOf()

	/**
	 * Nominate the closed object for archival and persist the nomination.
	 *
	 * @param ObjectEntity $object The object that reached a final state.
	 * @param Schema       $schema Its schema.
	 *
	 * @return void
	 */
	private function nominateAndPersist(ObjectEntity $object, Schema $schema): void {
		try {
			$nomination = $this->nominations->nominate(object: $object, schema: $schema, trigger: 'closure');
			if (($nomination['status'] ?? null) === ArchivalNominationServiceInterface::STATUS_NOT_APPLICABLE) {
				return;
			}

			$this->objectMapper->update($object);
		} catch (Throwable $e) {
			// The transition already happened. Losing the nomination is bad and
			// is said so out loud; undoing a closure the user asked for would
			// be worse, and nothing here can undo it anyway.
			$this->logger->error(
				message: '[ArchivalNominationListener] Could not nominate '
					. (string)$object->getUuid() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}//end try
	}//end nominateAndPersist()
}//end class
