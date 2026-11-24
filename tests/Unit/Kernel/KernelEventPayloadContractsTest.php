<?php

/**
 * The organisation, entity-relation and deep-link events name Kernel types only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\EntityRelationDecisionUpdatedEvent;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\DeepLinkCollectorInterface;
use OCA\OpenRegister\Kernel\Contracts\EntityRelationInterface;
use OCA\OpenRegister\Kernel\Contracts\OrganisationInterface;
use OCA\OpenRegister\Listener\ListenerClassesInterface;
use OCA\OpenRegister\Listener\ObjectEventProxyListener;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The events hand listeners the same objects as before, typed as Kernel contracts.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class KernelEventPayloadContractsTest extends TestCase {

	public function testTheEntitiesImplementTheirContracts(): void {
		self::assertInstanceOf(OrganisationInterface::class, new Organisation());
		self::assertInstanceOf(EntityRelationInterface::class, new EntityRelation());
	}

	public function testTheOrganisationEventsCarryTheEntityTheyWereGiven(): void {
		$new = new Organisation();
		$new->setName('new');
		$new->setStatus('active');
		$old = new Organisation();
		$old->setName('old');
		$old->setStatus('suspended');

		self::assertSame($new, (new OrganisationCreatedEvent(organisation: $new))->getOrganisation());
		self::assertSame($new, (new OrganisationDeletedEvent(organisation: $new))->getOrganisation());
		$updated = new OrganisationUpdatedEvent(newOrganisation: $new, oldOrganisation: $old);
		self::assertSame($new, $updated->getOrganisation());
		self::assertSame($new, $updated->getNewOrganisation());
		self::assertSame($old, $updated->getOldOrganisation());
		self::assertSame('suspended', $updated->getOldOrganisation()->getStatus());
		self::assertSame('new', $updated->getNewOrganisation()->getName());
	}

	public function testTheRelationDecisionEventCarriesTheRowItWasGiven(): void {
		$relation = new EntityRelation();
		$relation->setEntityId(9);
		$relation->setFileId(4);
		$event = new EntityRelationDecisionUpdatedEvent(
			relation: $relation,
			changedFields: ['skipAnonymization' => ['previous' => false, 'new' => true]],
			actingUser: null
		);

		self::assertSame($relation, $event->getRelation());
		self::assertSame(9, $event->getRelation()->getEntityId());
		self::assertSame(4, $event->getRelation()->getFileId());
		self::assertTrue($event->isSkipAnonymizationActivated());
	}

	public function testTheRegistryIsTheCollectorTheRegistrationEventCarries(): void {
		self::assertTrue(is_subclass_of(DeepLinkRegistryServiceInterface::class, DeepLinkCollectorInterface::class));
		self::assertTrue(is_subclass_of(DeepLinkRegistryService::class, DeepLinkCollectorInterface::class));

		$registry = $this->createMock(DeepLinkRegistryServiceInterface::class);
		self::assertSame($registry, (new DeepLinkRegistrationEvent(registry: $registry))->getRegistry());
	}

	public function testTheSubscriptionRegistersTheSameProxyListenerAsBefore(): void {
		self::assertSame(ObjectEventProxyListener::class, ObjectEventSubscription::PROXY_LISTENER);
		self::assertSame(ObjectEventProxyListener::class, ListenerClassesInterface::OBJECT_EVENT_PROXY);
	}
}//end class
