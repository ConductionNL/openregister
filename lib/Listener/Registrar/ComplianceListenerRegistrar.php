<?php

/**
 * ComplianceListenerRegistrar: the event listeners of the Compliance area, one slot per position.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Registrar;

use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener;
use OCA\OpenRegister\Listener\ArchivalNominationListener;
use OCA\OpenRegister\Listener\ContentReportRemovalListener;
use OCA\OpenRegister\Listener\EntityEditAuditListener;
use OCA\OpenRegister\Listener\QualityScoreOnSaveListener;
use OCA\OpenRegister\Listener\RecordTypeAuditListener;
use OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener;
use OCA\OpenRegister\Listener\SchemaAuthorizationAuditListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Subscribes the Compliance area's listeners, one public slot per position.
 *
 * Listeners on one event run in the order they were registered, and that
 * order is pinned (ListenerRegistrationOrderGoldenTest). So Compliance does
 * not register its listeners in a block of its own: each slot holds the lines
 * of one position, and the registrar that owns the ordered list calls the slot
 * exactly where those lines stood.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every listener
 *   and every event it subscribes to; that coupling is what it is for.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ComplianceListenerRegistrar {

	/**
	 * The listeners that put administrator changes on the audit trail.
	 *
	 * Authorisation changes on a schema, record-type changes, and every schema
	 * and register create, update and delete, whichever door it came through
	 * (history-schema-and-settings-edits-audited).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
	 */
	public static function authorizationAudit(IRegistrationContext $context): void {
		$context->registerEventListener(SchemaUpdatedEvent::class, SchemaAuthorizationAuditListener::class);
		foreach (array_keys(RecordTypeAuditListener::ACTIONS) as $event) {
			$context->registerEventListener($event, RecordTypeAuditListener::class);
		}

		$entityEvents = [
			SchemaCreatedEvent::class,
			SchemaUpdatedEvent::class,
			SchemaDeletedEvent::class,
			RegisterCreatedEvent::class,
			RegisterUpdatedEvent::class,
			RegisterDeletedEvent::class,
		];
		foreach ($entityEvents as $entityEvent) {
			$context->registerEventListener($entityEvent, EntityEditAuditListener::class);
		}
	}//end authorizationAudit()

	/**
	 * Compliance's checks on the schema save, in their own slot of SchemaDeclarationOrder.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public static function declarationChecks(IRegistrationContext $context): void {
		$context->registerEventListener(SchemaDeclarationChecksEvent::class, ArchivalDeclarationChecksListener::class);
	}//end declarationChecks()

	/**
	 * A changed date other records' destruction date reads through a relation queues their recalculation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function retentionRecalculation(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectUpdatedEvent::class, RelatedRetentionRecalculationListener::class);
	}//end retentionRecalculation()

	/**
	 * The quality annotation: a per-object data-quality score, materialised before persistence.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function qualityScore(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, QualityScoreOnSaveListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, QualityScoreOnSaveListener::class);
	}//end qualityScore()

	/**
	 * Reported content: a removal is noted on every report filed against the content.
	 *
	 * Fail-soft: never blocks the removal it observes.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function contentReportRemoval(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectDeletedEvent::class, ContentReportRemovalListener::class);
	}//end contentReportRemoval()

	/**
	 * Nomination at closure: a record reaching a final state gets its archival nomination and date.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function archivalNomination(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectTransitionedEvent::class, ArchivalNominationListener::class);
	}//end archivalNomination()
}//end class
