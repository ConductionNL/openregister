<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Registry\RegistryOwnedPropertyGuard;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
class RegistrySubscriptionServiceTest extends TestCase {
	private RegistrySubscriptionService $service;
	private RegistrySubscriptionMapper&MockObject $subscriptionMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ObjectService&MockObject $objectService;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private IEventDispatcher&MockObject $eventDispatcher;

	protected function setUp(): void {
		$this->subscriptionMapper = $this->createMock(RegistrySubscriptionMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$this->service = new RegistrySubscriptionService(
			$this->subscriptionMapper,
			$this->schemaMapper,
			$this->objectService,
			$this->auditTrailMapper,
			$this->eventDispatcher,
			new RegistryOwnedPropertyGuard(),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function brpSchema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration([
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => ['address', 'givenNames'],
			],
		]);

		return $schema;
	}

	private function personObject(string $uuid = 'obj-uuid-1', string $bsn = '999990019'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('dossiq');
		$object->setSchema('brpPerson');
		$object->setObject(['bsn' => $bsn, 'givenNames' => 'Stephan']);

		return $object;
	}

	// ── requestSubscription ──

	public function testRequestSubscriptionWithoutAnnotationIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->requestSubscription($this->personObject(), new Schema());
	}

	public function testRequestSubscriptionWithoutIdentityValueIsRejected(): void {
		$object = $this->personObject(bsn: '');
		$this->expectException(InvalidArgumentException::class);
		$this->service->requestSubscription($object, $this->brpSchema());
	}

	public function testRequestSubscriptionDispatchesEventWithIdentityValue(): void {
		$this->subscriptionMapper->method('findForObject')->willReturn(null);
		$this->subscriptionMapper->method('save')->willReturnArgument(0);

		$dispatched = null;
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->callback(function ($event) use (&$dispatched) {
				$dispatched = $event;
				return $event instanceof RegistrySubscriptionRequestedEvent;
			}));

		$row = $this->service->requestSubscription($this->personObject(), $this->brpSchema());

		$this->assertSame(RegistrySubscription::STATE_REQUESTED, $row->getState());
		$this->assertSame('999990019', $row->getIdentityValue());
		$this->assertSame('brp', $row->getRegistry());
		$this->assertInstanceOf(RegistrySubscriptionRequestedEvent::class, $dispatched);
		$this->assertSame('999990019', $dispatched->getIdentityValue());
	}

	public function testRequestSubscriptionWritesAnAuditEntry(): void {
		$this->subscriptionMapper->method('findForObject')->willReturn(null);
		$this->subscriptionMapper->method('save')->willReturnArgument(0);

		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$this->anything(),
				'registry.subscription.requested',
				$this->anything(),
			);

		$this->service->requestSubscription($this->personObject(), $this->brpSchema());
	}

	// ── endSubscription ──

	public function testEndSubscriptionWithNoExistingRowThrows(): void {
		$this->subscriptionMapper->method('findForObject')->willReturn(null);
		$this->expectException(DoesNotExistException::class);
		$this->service->endSubscription($this->personObject());
	}

	public function testEndSubscriptionSetsStateEnded(): void {
		$row = new RegistrySubscription();
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$this->subscriptionMapper->method('findForObject')->willReturn($row);
		$this->subscriptionMapper->method('save')->willReturnArgument(0);

		$result = $this->service->endSubscription($this->personObject());

		$this->assertSame(RegistrySubscription::STATE_ENDED, $result->getState());
	}

	// ── applyInboundUpdate ──

	public function testApplyInboundUpdateWithNoMatchesAppliesNothing(): void {
		$this->subscriptionMapper->method('findActiveByIdentity')->willReturn([]);

		$result = $this->service->applyInboundUpdate('brp', '999990019', ['address' => 'Dam 1'], 'evt-1');

		$this->assertSame(0, $result['matched']);
		$this->assertSame([], $result['applied']);
		$this->assertSame([], $result['rejected']);
	}

	public function testApplyInboundUpdateAppliesOwnedPropertiesThroughSaveObject(): void {
		$row = new RegistrySubscription();
		$row->setObjectUuid('obj-uuid-1');
		$row->setRegister('dossiq');
		$row->setSchema('brpPerson');
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$row->setState(RegistrySubscription::STATE_ACTIVE);

		$this->subscriptionMapper->method('findActiveByIdentity')->willReturn([$row]);
		$this->schemaMapper->method('find')->willReturn($this->brpSchema());

		$saved = $this->personObject();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->equalTo(['address' => 'Dam 1']),
				register: 'dossiq',
				schema: 'brpPerson',
				uuid: 'obj-uuid-1',
			)
			->willReturn($saved);

		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$saved,
				'registry.update',
				$this->anything(),
				'registry:brp',
				$this->anything(),
			);

		$result = $this->service->applyInboundUpdate('brp', '999990019', ['address' => 'Dam 1'], 'evt-1');

		$this->assertSame(['obj-uuid-1'], $result['applied']);
		$this->assertSame([], $result['rejected']);
	}

	public function testApplyInboundUpdateRejectsAPropertyOutsideOwned(): void {
		$row = new RegistrySubscription();
		$row->setObjectUuid('obj-uuid-1');
		$row->setRegister('dossiq');
		$row->setSchema('brpPerson');
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$row->setState(RegistrySubscription::STATE_ACTIVE);

		$this->subscriptionMapper->method('findActiveByIdentity')->willReturn([$row]);
		$this->schemaMapper->method('find')->willReturn($this->brpSchema());

		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service->applyInboundUpdate('brp', '999990019', ['notes' => 'secret'], 'evt-1');

		$this->assertSame([], $result['applied']);
		$this->assertCount(1, $result['rejected']);
		$this->assertSame('obj-uuid-1', $result['rejected'][0]['objectUuid']);
		$this->assertSame(['notes'], $result['rejected'][0]['properties']);
	}

	public function testApplyInboundUpdateWithEmptyPayloadAnnouncesNothing(): void {
		$row = new RegistrySubscription();
		$row->setObjectUuid('obj-uuid-1');
		$row->setRegister('dossiq');
		$row->setSchema('brpPerson');
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$row->setState(RegistrySubscription::STATE_ACTIVE);

		$this->subscriptionMapper->method('findActiveByIdentity')->willReturn([$row]);
		$this->schemaMapper->method('find')->willReturn($this->brpSchema());

		$this->objectService->expects($this->never())->method('saveObject');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$result = $this->service->applyInboundUpdate('brp', '999990019', [], 'evt-1');

		// matched=1 (a subscription DOES exist) but nothing to apply — the
		// controller's 404-vs-200-noop distinction depends on telling this
		// apart from the zero-matches case above.
		$this->assertSame(1, $result['matched']);
		$this->assertSame([], $result['applied']);
		$this->assertSame([], $result['rejected']);
	}
}
