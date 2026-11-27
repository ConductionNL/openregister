<?php

/**
 * EntityEditAuditListener: schema and register edits go on the audit trail.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\OrganisationInterface;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands the schema, register and organisation events to EntityEditAuditor.
 *
 * Listening at the mapper's events covers every door that changes a schema or
 * register: controllers, updateFromArray, tool providers, imports and repair
 * steps. The cause of the write comes from WriteCause::current().
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
 */
class EntityEditAuditListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param EntityEditAuditor $auditor Builds and writes the row.
	 */
	public function __construct(
		private readonly EntityEditAuditor $auditor,
	) {
	}//end __construct()

	/**
	 * Record the edit the event reports.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-schema-and-settings-edits-audited/design.md
	 */
	public function handle(Event $event): void {
		match (true) {
			$event instanceof SchemaCreatedEvent => $this->auditor->recordCreated(entity: $event->getSchema()),
			$event instanceof SchemaUpdatedEvent => $this->auditor->recordUpdated(old: $event->getOldSchema(), new: $event->getNewSchema()),
			$event instanceof SchemaDeletedEvent => $this->auditor->recordDeleted(entity: $event->getSchema()),
			$event instanceof RegisterCreatedEvent => $this->auditor->recordCreated(entity: $event->getRegister()),
			$event instanceof RegisterUpdatedEvent => $this->auditor->recordUpdated(old: $event->getOldRegister(), new: $event->getNewRegister()),
			$event instanceof RegisterDeletedEvent => $this->auditor->recordDeleted(entity: $event->getRegister()),
			$event instanceof OrganisationCreatedEvent,
			$event instanceof OrganisationDeletedEvent => $this->recordOrganisation(event: $event),
			$event instanceof OrganisationUpdatedEvent => $this->recordOrganisationUpdate(event: $event),
			default => 0,
		};
	}//end handle()

	/**
	 * Record a created or deleted organisation; the events carry the Kernel interface, the trail reads its serialised identity.
	 *
	 * @param OrganisationCreatedEvent|OrganisationDeletedEvent $event The event.
	 *
	 * @return int The rows written.
	 */
	private function recordOrganisation(OrganisationCreatedEvent|OrganisationDeletedEvent $event): int {
		$organisation = $event->getOrganisation();
		if (self::isStored(organisation: $organisation) === false) {
			return 0;
		}

		if ($event instanceof OrganisationCreatedEvent) {
			return $this->auditor->recordCreated(entity: $organisation);
		}

		return $this->auditor->recordDeleted(entity: $organisation);
	}//end recordOrganisation()

	/**
	 * Record an updated organisation.
	 *
	 * @param OrganisationUpdatedEvent $event The event.
	 *
	 * @return int The rows written.
	 */
	private function recordOrganisationUpdate(OrganisationUpdatedEvent $event): int {
		$old = $event->getOldOrganisation();
		$new = $event->getNewOrganisation();
		if (self::isStored(organisation: $old) === false || self::isStored(organisation: $new) === false) {
			return 0;
		}

		return $this->auditor->recordUpdated(old: $old, new: $new);
	}//end recordOrganisationUpdate()

	/**
	 * Whether an organisation is a stored one: it serialises with a uuid.
	 *
	 * An implementation that is not the stored entity has no audit identity,
	 * so it writes nothing rather than a row that names no organisation.
	 *
	 * @param OrganisationInterface $organisation The organisation.
	 *
	 * @return bool
	 */
	private static function isStored(OrganisationInterface $organisation): bool {
		$json = $organisation->jsonSerialize();
		if (is_array($json) === false) {
			return false;
		}

		return is_string($json['uuid'] ?? null) === true && $json['uuid'] !== '';
	}//end isStored()
}//end class
