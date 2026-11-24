<?php

/**
 * Compliance's listener slots register Compliance's listeners, in their order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

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
use OCA\OpenRegister\Listener\Registrar\ComplianceListenerRegistrar;
use OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener;
use OCA\OpenRegister\Listener\SchemaAuthorizationAuditListener;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\Registrar\ComplianceListenerRegistrar
 */
final class ComplianceListenerRegistrarTest extends TestCase {

	/**
	 * Slot => the (event, listener) pairs it registers, in order.
	 *
	 * @return array<string, array{string, list<array{string, string}>}>
	 */
	public static function slots(): array {
		return [
			'declarationChecks' => ['declarationChecks', [[SchemaDeclarationChecksEvent::class, ArchivalDeclarationChecksListener::class]]],
			'retentionRecalculation' => ['retentionRecalculation', [[ObjectUpdatedEvent::class, RelatedRetentionRecalculationListener::class]]],
			'qualityScore' => ['qualityScore', [[ObjectCreatingEvent::class, QualityScoreOnSaveListener::class], [ObjectUpdatingEvent::class, QualityScoreOnSaveListener::class]]],
			'contentReportRemoval' => ['contentReportRemoval', [[ObjectDeletedEvent::class, ContentReportRemovalListener::class]]],
			'archivalNomination' => ['archivalNomination', [[ObjectTransitionedEvent::class, ArchivalNominationListener::class]]],
		];
	}//end slots()

	/**
	 * Each slot registers exactly its lines, in their order.
	 *
	 * @param string                       $slot     The slot method.
	 * @param list<array{string, string}> $expected The (event, listener) pairs.
	 *
	 * @return void
	 */
	#[DataProvider('slots')]
	public function testTheSlotRegistersItsLines(string $slot, array $expected): void {
		$context = new RecordingRegistrationContext();
		ComplianceListenerRegistrar::$slot($context);

		$this->assertSame($expected, $this->pairs(context: $context));
	}//end testTheSlotRegistersItsLines()

	/**
	 * The audit slot keeps today's order: authorisation, every record-type action, then the entity edits.
	 *
	 * @return void
	 */
	public function testTheAuditSlotKeepsItsOrder(): void {
		$expected = [[SchemaUpdatedEvent::class, SchemaAuthorizationAuditListener::class]];
		foreach (array_keys(RecordTypeAuditListener::ACTIONS) as $event) {
			$expected[] = [$event, RecordTypeAuditListener::class];
		}

		foreach ([SchemaCreatedEvent::class, SchemaUpdatedEvent::class, SchemaDeletedEvent::class, RegisterCreatedEvent::class, RegisterUpdatedEvent::class, RegisterDeletedEvent::class] as $event) {
			$expected[] = [$event, EntityEditAuditListener::class];
		}

		$context = new RecordingRegistrationContext();
		ComplianceListenerRegistrar::authorizationAudit(context: $context);

		$this->assertGreaterThan(7, count($expected));
		$this->assertSame($expected, $this->pairs(context: $context));
	}//end testTheAuditSlotKeepsItsOrder()

	/**
	 * Every listener a slot names exists.
	 *
	 * @return void
	 */
	public function testEverySlotListenerExists(): void {
		$context = new RecordingRegistrationContext();
		ComplianceListenerRegistrar::authorizationAudit(context: $context);
		foreach (array_keys(self::slots()) as $slot) {
			ComplianceListenerRegistrar::$slot($context);
		}

		foreach ($this->pairs(context: $context) as [$event, $listener]) {
			$this->assertTrue(class_exists($event), $event);
			$this->assertTrue(class_exists($listener), $listener);
		}
	}//end testEverySlotListenerExists()

	/**
	 * The recorded registrations as (event, listener) pairs.
	 *
	 * @param RecordingRegistrationContext $context The recording context.
	 *
	 * @return list<array{string, string}>
	 */
	private function pairs(RecordingRegistrationContext $context): array {
		return array_map(
			static fn (array $registration): array => [$registration['event'], $registration['listener']],
			$context->listeners
		);
	}//end pairs()
}//end class
