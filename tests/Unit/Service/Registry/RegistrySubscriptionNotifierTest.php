<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\RegistrySubscriptionEndedEvent;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionNotifier;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
class RegistrySubscriptionNotifierTest extends TestCase {
	private RegistrySubscriptionNotifier $notifier;
	private IEventDispatcher&MockObject $eventDispatcher;
	private AuditTrailMapper&MockObject $auditTrailMapper;

	protected function setUp(): void {
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->notifier = new RegistrySubscriptionNotifier($this->eventDispatcher, $this->auditTrailMapper);
	}

	private function personObject(string $uuid = 'obj-uuid-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}

	public function testRequestedDispatchesEventWithIdentityValue(): void {
		$dispatched = null;
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->callback(function ($event) use (&$dispatched) {
				$dispatched = $event;
				return $event instanceof RegistrySubscriptionRequestedEvent;
			}));

		$this->notifier->requested($this->personObject(), 'dossiq', 'brpPerson', 'brp', '999990019');

		$this->assertInstanceOf(RegistrySubscriptionRequestedEvent::class, $dispatched);
		$this->assertSame('999990019', $dispatched->getIdentityValue());
		$this->assertSame('brp', $dispatched->getRegistry());
	}

	public function testRequestedWritesAnAuditEntry(): void {
		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$this->anything(),
				'registry.subscription.requested',
				$this->anything(),
			);

		$this->notifier->requested($this->personObject(), 'dossiq', 'brpPerson', 'brp', '999990019');
	}

	public function testEndedDispatchesEvent(): void {
		$dispatched = null;
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->callback(function ($event) use (&$dispatched) {
				$dispatched = $event;
				return $event instanceof RegistrySubscriptionEndedEvent;
			}));

		$this->notifier->ended($this->personObject(), 'brp', '999990019');

		$this->assertInstanceOf(RegistrySubscriptionEndedEvent::class, $dispatched);
		$this->assertSame('brp', $dispatched->getRegistry());
	}

	public function testAuditInboundUpdateNamesTheRegistryAsActor(): void {
		$object = $this->personObject();

		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$object,
				'registry.update',
				$this->anything(),
				'registry:brp',
				$this->anything(),
			);

		$this->notifier->auditInboundUpdate($object, 'brp', 'evt-1', ['address']);
	}
}
