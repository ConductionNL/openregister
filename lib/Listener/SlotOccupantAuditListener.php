<?php

/**
 * Replacing the occupant of a slot moves access, so it goes on the trail.
 *
 * A grant to a party role (a slot) is resolved against whoever holds the role
 * at the moment access is evaluated. That is what lets the occupant change
 * with no grant edited, and it is also why the grant itself never records the
 * change: nothing about the grant moved. The record of who gained and who
 * lost the access therefore has to be written where the occupant changes,
 * which is a person's link on the object being added, changed or removed.
 *
 * Only a change that can move access is recorded: a link naming a Nextcloud
 * user (a party without an account and a CardDAV contact cannot sign in, so
 * they never hold a slot's verbs), on an object whose own block names a slot.
 * When the object cannot be read, the change is recorded anyway against the
 * link's object: a missing entry is the failure an access reviewer cannot
 * recover from, and a superfluous one costs a row.
 *
 * The entry is an ordinary object event on the existing hash-chained trail
 * (`AuditTrailMapper::recordObjectEvent`); the trail's structure is untouched.
 * A failure to record is logged and never undoes the link change, which has
 * already been committed by the time the event is dispatched.
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
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\AbstractPersonLinkEvent;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one audit entry when the occupant of a slot on an object changes.
 *
 * @template-implements IEventListener<AbstractPersonLinkEvent>
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
 */
class SlotOccupantAuditListener implements IEventListener {

	/**
	 * The audit action written when the occupant of a slot changes.
	 *
	 * @var string
	 */
	public const ACTION = 'party.slot-occupant-changed';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object layer, to read the object's own block.
	 * @param AuditTrailMapperInterface $audit   The hash-chained audit trail.
	 * @param LoggerInterface           $logger  Says why an entry could not be written.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly AuditTrailMapperInterface $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a change to a user's role on an object when it can move slot access.
	 *
	 * @param Event $event A person-link event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof AbstractPersonLinkEvent) === false) {
			return;
		}

		$link = $event->getLink();
		$objectUuid = (string)($link->getObjectUuid() ?? '');
		if ($link->isUserLink() === false || $objectUuid === '') {
			return;
		}

		try {
			$this->record(event: $event, link: $link, objectUuid: $objectUuid);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[SlotOccupantAuditListener] Could not record a slot occupant change',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectUuid' => $objectUuid, 'error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Write the entry, unless the object is readable and names no slot.
	 *
	 * @param AbstractPersonLinkEvent $event      The link event.
	 * @param ContactLink             $link       The link as stored (or as it was, on removal).
	 * @param string                  $objectUuid The object the link is on.
	 *
	 * @return void
	 */
	private function record(AbstractPersonLinkEvent $event, ContactLink $link, string $objectUuid): void {
		$object = $this->readObject(objectUuid: $objectUuid);
		$slots = [];
		if ($object !== null) {
			$slots = PartyRoleSlotResolver::slotsNamedIn(block: $object->getAuthorization());
			if ($slots === []) {
				// No slot on this object: the role grants nobody anything, so
				// changing its occupant moved no access.
				return;
			}
		}

		$this->audit->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: ($object ?? $this->standIn(link: $link, objectUuid: $objectUuid)),
				action: self::ACTION,
				context: [
					'change' => self::changeOf(event: $event),
					'role' => $link->getRole(),
					'user' => $link->getUserId(),
					'validFrom' => $link->getValidFrom()?->format('Y-m-d'),
					'validUntil' => $link->getValidUntil()?->format('Y-m-d'),
					'slots' => $slots,
					'objectRead' => ($object !== null),
				]
			)
		);
	}//end record()

	/**
	 * The object with its own block, read without RBAC and without a read audit, or null.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function readObject(string $objectUuid): ?ObjectEntity {
		try {
			return $this->objects->findSilent($objectUuid, null, false, false);
		} catch (Throwable $e) {
			return null;
		}
	}//end readObject()

	/**
	 * An entity naming the link's object, for an entry when the object could not be read.
	 *
	 * @param ContactLink $link       The link.
	 * @param string      $objectUuid The object's uuid.
	 *
	 * @return ObjectEntity The stand-in.
	 */
	private function standIn(ContactLink $link, string $objectUuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($objectUuid);
		$object->setRegister(self::idOrNull(id: $link->getRegisterId()));
		$object->setSchema(self::idOrNull(id: $link->getSchemaId()));

		return $object;
	}//end standIn()

	/**
	 * A stored id as the entity's string form, or null when there is none.
	 *
	 * @param mixed $id The id.
	 *
	 * @return string|null The id.
	 */
	private static function idOrNull(mixed $id): ?string {
		if (is_int($id) === false && is_string($id) === false) {
			return null;
		}

		return (string)$id;
	}//end idOrNull()

	/**
	 * What happened to the link.
	 *
	 * @param AbstractPersonLinkEvent $event The event.
	 *
	 * @return string `linked`, `unlinked` or `updated`.
	 */
	private static function changeOf(AbstractPersonLinkEvent $event): string {
		if ($event instanceof PersonLinkedEvent) {
			return 'linked';
		}

		if ($event instanceof PersonUnlinkedEvent) {
			return 'unlinked';
		}

		return 'updated';
	}//end changeOf()
}//end class
