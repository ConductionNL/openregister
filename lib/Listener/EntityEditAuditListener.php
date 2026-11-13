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

use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands the six schema and register events to EntityEditAuditor.
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
			default => 0,
		};
	}//end handle()
}//end class
