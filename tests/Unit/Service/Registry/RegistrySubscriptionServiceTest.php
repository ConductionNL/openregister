<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionNotifier;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Orchestration tests for RegistrySubscriptionService. The event/audit
 * dispatch behavior lives in RegistrySubscriptionNotifierTest, and the
 * owned-property decision behavior lives in RegistryUpdateTargetGuardTest —
 * both are mocked here as opaque collaborators so this file only asserts
 * what THIS class is responsible for: reading the annotation, building and
 * persisting the state row, and deciding matched/applied/rejected.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md
 */
class RegistrySubscriptionServiceTest extends TestCase {
	private RegistrySubscriptionService $service;
	private RegistrySubscriptionMapper&MockObject $subscriptionMapper;
	private ObjectService&MockObject $objectService;
	private RegistrySubscriptionNotifier&MockObject $notifier;
	private RegistryUpdateTargetGuard&MockObject $updateTargetGuard;

	protected function setUp(): void {
		$this->subscriptionMapper = $this->createMock(RegistrySubscriptionMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->notifier = $this->createMock(RegistrySubscriptionNotifier::class);
		$this->updateTargetGuard = $this->createMock(RegistryUpdateTargetGuard::class);

		$this->service = new RegistrySubscriptionService(
			$this->subscriptionMapper,
			$this->objectService,
			$this->notifier,
			$this->updateTargetGuard,
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

	// ── annotationFor ──

	public function testAnnotationForReturnsNullWhenSchemaHasNoAnnotation(): void {
		$this->assertNull($this->service->annotationFor(new Schema()));
	}

	public function testAnnotationForReadsTheDeclaredFields(): void {
		$annotation = $this->service->annotationFor($this->brpSchema());

		$this->assertSame('brp', $annotation['registry']);
		$this->assertSame('bsn', $annotation['identity']);
		$this->assertSame(['address', 'givenNames'], $annotation['owned']);
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

	public function testRequestSubscriptionPersistsARequestedRowAndNotifies(): void {
		$this->subscriptionMapper->method('findForObject')->willReturn(null);
		$this->subscriptionMapper->method('save')->willReturnArgument(0);

		$this->notifier->expects($this->once())
			->method('requested')
			->with(
				$this->anything(),
				'dossiq',
				'brpPerson',
				'brp',
				'999990019',
			);

		$row = $this->service->requestSubscription($this->personObject(), $this->brpSchema());

		$this->assertSame(RegistrySubscription::STATE_REQUESTED, $row->getState());
		$this->assertSame('999990019', $row->getIdentityValue());
		$this->assertSame('brp', $row->getRegistry());
	}

	public function testRequestSubscriptionReusesAnExistingRowRatherThanDuplicating(): void {
		$existing = new RegistrySubscription();
		$existing->setId(42);
		$existing->setObjectUuid('obj-uuid-1');
		$this->subscriptionMapper->method('findForObject')->willReturn($existing);
		$this->subscriptionMapper->expects($this->once())->method('save')->willReturnArgument(0);

		$row = $this->service->requestSubscription($this->personObject(), $this->brpSchema());

		$this->assertSame(42, $row->getId());
	}

	// ── endSubscription ──

	public function testEndSubscriptionWithNoExistingRowThrows(): void {
		$this->subscriptionMapper->method('findForObject')->willReturn(null);
		$this->expectException(DoesNotExistException::class);
		$this->service->endSubscription($this->personObject());
	}

	public function testEndSubscriptionSetsStateEndedAndNotifies(): void {
		$row = new RegistrySubscription();
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$this->subscriptionMapper->method('findForObject')->willReturn($row);
		$this->subscriptionMapper->method('save')->willReturnArgument(0);

		$this->notifier->expects($this->once())->method('ended')->with($this->anything(), 'brp', '999990019');

		$result = $this->service->endSubscription($this->personObject());

		$this->assertSame(RegistrySubscription::STATE_ENDED, $result->getState());
	}

	// ── applyInboundUpdate ──

	public function testApplyInboundUpdateWithNoMatchesAppliesNothing(): void {
		$this->subscriptionMapper->method('findActiveByIdentity')->willReturn([]);
		$this->updateTargetGuard->expects($this->never())->method('evaluate');

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
		$this->updateTargetGuard->method('evaluate')->willReturn(['allowed' => true, 'rejected' => []]);

		// Argument correctness is checked inside these callbacks, reading
		// PHP's own resolved positional values, rather than via with() —
		// with() constraints declared with named-argument labels do not
		// reliably bind to the mocked method's real parameter positions
		// across PHPUnit versions, and a silent positional mismatch (this
		// service calls both methods with named arguments) would make the
		// mock fall through unmatched and this test would then be
		// exercising nothing.
		$saved = $this->personObject();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function ($object, $extend = [], $register = null, $schema = null, $uuid = null) use ($saved) {
				$this->assertSame(['address' => 'Dam 1'], $object);
				$this->assertSame('dossiq', $register);
				$this->assertSame('brpPerson', $schema);
				$this->assertSame('obj-uuid-1', $uuid);
				return $saved;
			});

		$this->notifier->expects($this->once())
			->method('auditInboundUpdate')
			->willReturnCallback(function ($object, $registry, $eventReference, $properties) use ($saved) {
				$this->assertSame($saved, $object);
				$this->assertSame('brp', $registry);
				$this->assertSame('evt-1', $eventReference);
				$this->assertSame(['address'], $properties);
			});

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
		$this->updateTargetGuard->method('evaluate')->willReturn(['allowed' => false, 'rejected' => ['notes']]);

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
		$this->updateTargetGuard->method('evaluate')->willReturn(['allowed' => true, 'rejected' => []]);

		$this->objectService->expects($this->never())->method('saveObject');
		$this->notifier->expects($this->never())->method('auditInboundUpdate');

		$result = $this->service->applyInboundUpdate('brp', '999990019', [], 'evt-1');

		// matched=1 (a subscription DOES exist) but nothing to apply — the
		// controller's 404-vs-200-noop distinction depends on telling this
		// apart from the zero-matches case above.
		$this->assertSame(1, $result['matched']);
		$this->assertSame([], $result['applied']);
		$this->assertSame([], $result['rejected']);
	}
}
