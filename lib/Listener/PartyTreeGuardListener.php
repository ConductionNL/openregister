<?php

/**
 * An organisation party names a parent, and the write is refused when it
 * would close a cycle or push the tree past its declared bound.
 *
 * The guard runs on the save itself, not in whatever wrote the object. A
 * check that lives in one caller is a check the next caller does not have,
 * and a cycle reaches the reader as an infinite parent chain rather than as
 * an error.
 *
 * Cheap for every other save on the instance: a schema that declares no
 * party model, or a party model with no parent property, returns before any
 * object is read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a party save whose parent would close a cycle or break the depth bound.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyTreeGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PartyService $parties The party records and the tree guard.
	 */
	public function __construct(
		private readonly PartyService $parties,
	) {
	}//end __construct()

	/**
	 * Check the parent a party save proposes, and veto the save when it is refused.
	 *
	 * @param Event $event The save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function handle(Event $event): void {
		$object = $this->objectOf(event: $event);
		if ($object === null) {
			return;
		}

		$definition = $this->parties->definitionFor(party: $object);
		if ($definition === null || $definition->parentProperty() === null) {
			return;
		}

		$parent = trim((string)($object->getObject()[$definition->parentProperty()] ?? ''));
		if ($parent === '') {
			return;
		}

		try {
			$this->parties->assertParentAllowed(
				partyUuid: (string)($object->getUuid() ?? ''),
				parentUuid: $parent
			);
		} catch (Exception $e) {
			// Refuse the save rather than degrade. A cycle that is written
			// reaches every later reader as a parent chain with no end, and
			// nothing downstream can tell it apart from a deep tree.
			$event->setErrors(['message' => $e->getMessage(), 'code' => $e->getCode()]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * The object a save event carries, or null when the event is neither save.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntity|null The object about to be written.
	 */
	private function objectOf(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatingEvent === true) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return null;
	}//end objectOf()
}//end class
