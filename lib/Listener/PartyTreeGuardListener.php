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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses a party save whose parent would close a cycle or break the depth bound.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyTreeGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PartyServiceInterface $parties The party records and the tree guard.
	 */
	public function __construct(
		private readonly PartyServiceInterface $parties,
	) {
	}//end __construct()

	/**
	 * Check the parent a party save proposes, and veto the save when it is refused.
	 *
	 * @param Event $event The save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function handle(Event $event): void {
		// The two save events are told apart here rather than through one
		// helper returning the object: the veto is a method on the concrete
		// event, and a helper that hands back only the object loses the handle
		// that can refuse the write.
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->veto(event: $event, refusal: $this->refusalFor(object: $event->getObject()));
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->veto(event: $event, refusal: $this->refusalFor(object: $event->getNewObject()));
		}
	}//end handle()

	/**
	 * Veto the save with the refusal, when there is one.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The save event.
	 * @param array<string, mixed>|null               $refusal The error the event carries, or null.
	 *
	 * @return void
	 */
	private function veto(ObjectCreatingEvent|ObjectUpdatingEvent $event, ?array $refusal): void {
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end veto()

	/**
	 * Why this save must be refused, or null when there is no reason.
	 *
	 * Refusing rather than degrading is deliberate. A cycle that is written
	 * reaches every later reader as a parent chain with no end, and nothing
	 * downstream can tell it apart from a deep tree.
	 *
	 * @param ObjectEntity $object The object about to be written.
	 *
	 * @return array<string, mixed>|null The error the event carries, or null.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	private function refusalFor(ObjectEntity $object): ?array {
		$parent = $this->proposedParentOf(object: $object);
		if ($parent === null) {
			return null;
		}

		try {
			$this->parties->assertParentAllowed(
				partyUuid: (string)($object->getUuid() ?? ''),
				parentUuid: $parent
			);
		} catch (Exception $e) {
			return ['message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		return null;
	}//end refusalFor()

	/**
	 * The parent a party save proposes, or null when the object is no party in a tree or names none.
	 *
	 * @param ObjectEntity $object The object about to be written.
	 *
	 * @return string|null The trimmed parent uuid.
	 */
	private function proposedParentOf(ObjectEntity $object): ?string {
		$definition = $this->parties->definitionFor(party: $object);
		if ($definition === null || $definition->parentProperty() === null) {
			return null;
		}

		$parent = trim((string)($object->getObject()[$definition->parentProperty()] ?? ''));
		if ($parent === '') {
			return null;
		}

		return $parent;
	}//end proposedParentOf()
}//end class
