<?php

/**
 * CoreListenerRegistrar: the event listeners of the Core area, one slot per position.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Reference\ObjectTeamResourceProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Subscribes the Core area's listeners, one public slot per position.
 *
 * Listeners on one event run in the order they were registered, and that
 * order is pinned (ListenerRegistrationOrderGoldenTest). So Core does not
 * register its listeners in a block of its own: each slot holds the lines of
 * one position, and the registrar that owns the ordered list calls the slot
 * exactly where those lines stood. The sequence is unchanged; only the file
 * that names the Core listeners is Core's own.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every listener
 *   and every event it subscribes to; that coupling is what it is for.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class CoreListenerRegistrar {

	/**
	 * Access derived from what an identity provider asserted, once per sign-in.
	 *
	 * Costs one app-config read on an instance that declares no rule, and
	 * never fails a sign-in: see the listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function identityClaims(IRegistrationContext $context): void {
		$context->registerEventListener(UserLoggedInEvent::class, IdentityClaimsLoginListener::class);
	}//end identityClaims()

	/**
	 * A service account signs in with its tokens only: any other sign-in is
	 * cancelled (REQ-SAT-004). PostLoginEvent is the one event every sign-in
	 * path dispatches, with whether a token was used.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function serviceAccountLogin(IRegistrationContext $context): void {
		$context->registerEventListener(PostLoginEvent::class, ServiceAccountLoginListener::class);
	}//end serviceAccountLogin()

	/**
	 * A share made from the platform's own sharing surface on an object's folder
	 * passes the schema's opt-in and the permission mapping.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function platformShareRouting(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeShareCreatedEvent::class, PlatformShareRoutingListener::class);
	}//end platformShareRouting()

	/**
	 * A grant to a slot follows whoever holds the role, so the change of
	 * occupant, not the grant, is what goes on the trail (REQ-GSR-001).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function slotOccupants(IRegistrationContext $context): void {
		foreach ([PersonLinkedEvent::class, PersonLinkUpdatedEvent::class, PersonUnlinkedEvent::class] as $linkEvent) {
			$context->registerEventListener($linkEvent, SlotOccupantAuditListener::class);
		}
	}//end slotOccupants()

	/**
	 * The generated identifier: issued on create, frozen on update.
	 *
	 * The value has to be in the object's body BEFORE it is written, or the
	 * object's first version is the one without a number.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function generatedIdentifier(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, GeneratedIdentifierListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, GeneratedIdentifierListener::class);
	}//end generatedIdentifier()

	/**
	 * The survivorship annotation: a declared golden record and its provenance, before persistence.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function survivorship(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, SurvivorshipRecomputeListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, SurvivorshipRecomputeListener::class);
	}//end survivorship()

	/**
	 * Code-list lifecycle: validity window, leaf-only and exclusive groups, refused at write time.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function codedValueValidation(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, CodedValueValidationListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, CodedValueValidationListener::class);
	}//end codedValueValidation()

	/**
	 * A code-list value the product defines, or one objects still hold, refuses its delete.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function conceptDeleteGuard(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectDeletingEvent::class, ConceptDeleteGuardListener::class);
	}//end conceptDeleteGuard()

	/**
	 * An object in a state that forbids it refuses its delete.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function objectStateDeleteGuard(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectDeletingEvent::class, ObjectStateDeleteGuardListener::class);
	}//end objectStateDeleteGuard()

	/**
	 * A move takes the row away without a delete: a code-list value others hold is refused there as well.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function conceptLeavingGuard(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectLeavingEvent::class, ConceptDeleteGuardListener::class);
	}//end conceptLeavingGuard()

	/**
	 * Named uniqueness constraints: a `refuse` constraint stops the write, a `report` one records the breach.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function uniqueConstraints(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatingEvent::class, UniqueConstraintListener::class);
		$context->registerEventListener(ObjectUpdatingEvent::class, UniqueConstraintListener::class);
	}//end uniqueConstraints()

	/**
	 * Objects of schemas declaring x-openregister-team-resources appear on their owning team's page;
	 * the declaration's check runs inside the schema save, in its slot of SchemaDeclarationOrder.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the provider and the listener carry their own spec.
	 */
	public static function teamResources(IRegistrationContext $context): void {
		$context->registerTeamResourceProvider(ObjectTeamResourceProvider::class);
		$context->registerEventListener(SchemaDeclarationChecksEvent::class, TeamResourcesDeclarationCheckListener::class);
	}//end teamResources()

	/**
	 * A schema's identity values do not change once an object exists.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function identityGuard(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectUpdatingEvent::class, IdentityGuardListener::class);
	}//end identityGuard()

	/**
	 * The cached menu of grantable rights is dropped on any schema write.
	 *
	 * Deliberately has no TTL to fall back on: a right removed from a schema
	 * must stop being offered at the moment of the write.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function grantableRights(IRegistrationContext $context): void {
		$context->registerEventListener(SchemaCreatedEvent::class, GrantableRightsInvalidationListener::class);
		$context->registerEventListener(SchemaUpdatedEvent::class, GrantableRightsInvalidationListener::class);
		$context->registerEventListener(SchemaDeletedEvent::class, GrantableRightsInvalidationListener::class);
	}//end grantableRights()

	/**
	 * Facet freshness on every object write (openregister#3560).
	 *
	 * Bumps the counter for the written object's (register, schema) scope so
	 * the next facet read cannot serve a bucket list computed before the write.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function facetCache(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, FacetCacheInvalidationListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, FacetCacheInvalidationListener::class);
	}//end facetCache()

	/**
	 * A transition becomes an interval a history filter can join.
	 *
	 * The listener never fails the move: the projection is derived and
	 * rebuildable, the transition is not.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function stateHistory(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectTransitionedEvent::class, StateHistoryProjectionListener::class);
	}//end stateHistory()

	/**
	 * The semantic provider list the integrations capability advertises follows schemas, registers and apps.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function semanticProviders(IRegistrationContext $context): void {
		foreach (SemanticProviderCacheListener::EVENTS as $semanticProviderEvent) {
			$context->registerEventListener($semanticProviderEvent, SemanticProviderCacheListener::class);
		}
	}//end semanticProviders()

	/**
	 * The notes and tasks of a deleted object are cleaned up.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function objectCleanup(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectDeletedEvent::class, ObjectCleanupListener::class);
	}//end objectCleanup()

	/**
	 * The facet cache follows a draft event the caller names.
	 *
	 * The draft events are chosen in ListenerRegistrar::registerDraftListeners(),
	 * the one place a listener may be registered on one; this slot only holds
	 * Core's line of that list.
	 *
	 * @param IRegistrationContext $context    The registration context.
	 * @param string               $draftEvent The draft event class.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function draftFacetCache(IRegistrationContext $context, string $draftEvent): void {
		$context->registerEventListener($draftEvent, FacetCacheInvalidationListener::class);
	}//end draftFacetCache()

	/**
	 * What hangs on a draft goes with it: its notes, tasks and links, on the draft event the caller names.
	 *
	 * @param IRegistrationContext $context    The registration context.
	 * @param string               $draftEvent The draft event class.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function draftCleanup(IRegistrationContext $context, string $draftEvent): void {
		$context->registerEventListener($draftEvent, ObjectCleanupListener::class);
	}//end draftCleanup()
}//end class
