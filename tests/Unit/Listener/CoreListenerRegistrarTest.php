<?php

/**
 * Core's listener slots register Core's listeners, and the ordered list calls them in place.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Event\DraftDeletedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\ConceptDeleteGuardListener;
use OCA\OpenRegister\Listener\CoreListenerRegistrar;
use OCA\OpenRegister\Listener\FacetCacheInvalidationListener;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Listener\GrantableRightsInvalidationListener;
use OCA\OpenRegister\Listener\IdentityClaimsLoginListener;
use OCA\OpenRegister\Listener\IdentityGuardListener;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Listener\ObjectStateDeleteGuardListener;
use OCA\OpenRegister\Listener\PlatformShareRoutingListener;
use OCA\OpenRegister\Listener\ServiceAccountLoginListener;
use OCA\OpenRegister\Listener\StateHistoryProjectionListener;
use OCA\OpenRegister\Listener\SurvivorshipRecomputeListener;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\CoreListenerRegistrar
 */
final class CoreListenerRegistrarTest extends TestCase {

	/**
	 * Slot => the (event, listener) pairs it registers, in order.
	 *
	 * @return array<string, array{string, list<array{string, string}>}>
	 */
	public static function slots(): array {
		return [
			'identityClaims' => ['identityClaims', [[UserLoggedInEvent::class, IdentityClaimsLoginListener::class]]],
			'serviceAccountLogin' => ['serviceAccountLogin', [[PostLoginEvent::class, ServiceAccountLoginListener::class]]],
			'platformShareRouting' => ['platformShareRouting', [[BeforeShareCreatedEvent::class, PlatformShareRoutingListener::class]]],
			'generatedIdentifier' => ['generatedIdentifier', [[ObjectCreatingEvent::class, GeneratedIdentifierListener::class], [ObjectUpdatingEvent::class, GeneratedIdentifierListener::class]]],
			'survivorship' => ['survivorship', [[ObjectCreatingEvent::class, SurvivorshipRecomputeListener::class], [ObjectUpdatingEvent::class, SurvivorshipRecomputeListener::class]]],
			'codedValueValidation' => ['codedValueValidation', [[ObjectCreatingEvent::class, CodedValueValidationListener::class], [ObjectUpdatingEvent::class, CodedValueValidationListener::class]]],
			'conceptDeleteGuard' => ['conceptDeleteGuard', [[ObjectDeletingEvent::class, ConceptDeleteGuardListener::class]]],
			'objectStateDeleteGuard' => ['objectStateDeleteGuard', [[ObjectDeletingEvent::class, ObjectStateDeleteGuardListener::class]]],
			'conceptLeavingGuard' => ['conceptLeavingGuard', [[ObjectLeavingEvent::class, ConceptDeleteGuardListener::class]]],
			'uniqueConstraints' => ['uniqueConstraints', [[ObjectCreatingEvent::class, UniqueConstraintListener::class], [ObjectUpdatingEvent::class, UniqueConstraintListener::class]]],
			'identityGuard' => ['identityGuard', [[ObjectUpdatingEvent::class, IdentityGuardListener::class]]],
			'grantableRights' => ['grantableRights', [[SchemaCreatedEvent::class, GrantableRightsInvalidationListener::class], [SchemaUpdatedEvent::class, GrantableRightsInvalidationListener::class], [SchemaDeletedEvent::class, GrantableRightsInvalidationListener::class]]],
			'facetCache' => ['facetCache', [[ObjectCreatedEvent::class, FacetCacheInvalidationListener::class], [ObjectUpdatedEvent::class, FacetCacheInvalidationListener::class], [ObjectDeletedEvent::class, FacetCacheInvalidationListener::class], [ObjectTransitionedEvent::class, FacetCacheInvalidationListener::class]]],
			'stateHistory' => ['stateHistory', [[ObjectTransitionedEvent::class, StateHistoryProjectionListener::class]]],
			'objectCleanup' => ['objectCleanup', [[ObjectDeletedEvent::class, ObjectCleanupListener::class]]],
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
		CoreListenerRegistrar::$slot($context);

		$this->assertSame($expected, $this->pairs(context: $context));
	}//end testTheSlotRegistersItsLines()

	/**
	 * The draft slots register on the draft event their caller names, and on nothing else.
	 *
	 * @return void
	 */
	public function testTheDraftSlotsRegisterOnTheNamedEvent(): void {
		$context = new RecordingRegistrationContext();
		CoreListenerRegistrar::draftFacetCache(context: $context, draftEvent: DraftUpdatedEvent::class);
		CoreListenerRegistrar::draftCleanup(context: $context, draftEvent: DraftDeletedEvent::class);

		$this->assertSame(
			[[DraftUpdatedEvent::class, FacetCacheInvalidationListener::class], [DraftDeletedEvent::class, ObjectCleanupListener::class]],
			$this->pairs(context: $context)
		);
	}//end testTheDraftSlotsRegisterOnTheNamedEvent()

	/**
	 * Every listener a slot names exists.
	 *
	 * @return void
	 */
	public function testEverySlotListenerExists(): void {
		foreach (self::slots() as [$slot, $expected]) {
			foreach ($expected as [$event, $listener]) {
				$this->assertTrue(class_exists($listener), $slot . ': ' . $listener);
			}
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
