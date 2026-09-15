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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
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
	 * @param ArchivalNominationService $nominations  Derives and writes the nomination.
	 * @param SchemaMapper              $schemaMapper Loads the transitioned object's schema.
	 * @param MagicMapper               $objectMapper Persists the nominated record.
	 * @param LoggerInterface           $logger       Where a failure is reported.
	 */
	public function __construct(
		private readonly ArchivalNominationService $nominations,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
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

		try {
			$schema = $this->schemaMapper->find(id: (string)$object->getSchema());
		} catch (Throwable $e) {
			return;
		}

		if ($this->nominations->isTerminalState(schema: $schema, state: $event->getTo()) === false) {
			return;
		}

		try {
			$nomination = $this->nominations->nominate(
				object: $object,
				schema: $schema,
				trigger: 'closure'
			);

			if (($nomination['status'] ?? null) === ArchivalNominationService::STATUS_NOT_APPLICABLE) {
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
	}//end handle()
}//end class
