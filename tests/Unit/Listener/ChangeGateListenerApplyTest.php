<?php

/**
 * ChangeGateListener — an approved held change is applied, a rejected one discarded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceRejectedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Listener\ChangeGateListener;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A clerk's IBAN change waits as `pending-1` beside supplier `sup-1`; the
 * `bank-details` sequence decides it.
 *
 * @covers \OCA\OpenRegister\Listener\ChangeGateListener
 * @uses \OCA\OpenRegister\Service\Object\DraftService
 * @uses \OCA\OpenRegister\Service\Task\ChangeGateDeclaration
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskSequence
 * @uses \OCA\OpenRegister\Db\DraftVersion
 * @uses \OCA\OpenRegister\Event\AbstractTaskSequenceEvent
 * @uses \OCA\OpenRegister\Event\TaskSequenceCompletedEvent
 * @uses \OCA\OpenRegister\Event\TaskSequenceRejectedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Contract\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Contract\ObjectFindTarget
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-approval-applies-the-change-and-rejection-discards-it
 */
class ChangeGateListenerApplyTest extends TestCase {

	/** @var DraftVersionMapper&MockObject */
	private DraftVersionMapper $draftVersions;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var list<DraftVersion> */
	private array $deleted = [];

	/** @var list<array{data: array, options: ObjectSaveOptions, rbac: bool}> */
	private array $saved = [];

	private DraftVersion $pending;

	private ChangeGateListener $listener;

	protected function setUp(): void {
		$this->pending = new DraftVersion();
		$this->pending->setObjectUuid('sup-1');
		$this->pending->setObjectRegister('7');
		$this->pending->setObjectSchema('12');
		$this->pending->setDraftKey('pending-1');
		$this->pending->setName('bank-details');
		$this->pending->setDelta(['iban' => 'NL02NEW']);

		$this->draftVersions = $this->createMock(DraftVersionMapper::class);
		$this->draftVersions->method('findForObject')->willReturnCallback(fn (string $objectUuid): array => ($objectUuid === 'sup-1' ? [$this->pending] : []));
		$this->draftVersions->method('delete')->willReturnCallback(function (DraftVersion $draft): DraftVersion {
			$this->deleted[] = $draft;
			return $draft;
		});

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->listener = $this->listener(onApprove: 'applyChange');
	}

	private function listener(?string $onApprove): ChangeGateListener {
		$chain = ['gate' => ['change' => ['properties' => ['iban']]], 'approvers' => [['role' => 'financieel-beheer']]];
		if ($onApprove !== null) {
			$chain['onApprove'] = $onApprove;
		}

		$schema = new Schema();
		$schema->setId(12);
		$schema->setConfiguration(['x-openregister-approval-chains' => ['bank-details' => $chain]]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('approver');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ChangeGateListener(
			$schemas,
			new DraftService($this->draftVersions, $this->createMock(PermissionCheckInterface::class)),
			$this->draftVersions,
			$session,
			$this->createMock(IGroupManager::class),
			$this->logger,
			null,
			$this->objects
		);
	}

	private function sequence(string $chainKey = 'bank-details'): TaskSequence {
		$sequence = new TaskSequence();
		$sequence->setAnchorObjectUuid('sup-1');
		$sequence->setSchemaId(12);
		$sequence->setChainKey($chainKey);
		return $sequence;
	}

	private function approved(string $chainKey = 'bank-details'): TaskSequenceCompletedEvent {
		return new TaskSequenceCompletedEvent(sequence: $this->sequence(chainKey: $chainKey), finalTask: new Task(), decider: 'approver', statusOnApprove: 'approved');
	}

	private function supplier(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('sup-1');
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setObject($data);
		return $object;
	}

	private function recordSaves(?\Throwable $failure = null): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options, bool $_rbac = true) use ($failure): ObjectEntity {
				$this->saved[] = ['data' => $object, 'options' => $options, 'rbac' => $_rbac];
				if ($failure !== null) {
					throw $failure;
				}

				return $this->supplier(is_array($object) ? $object : []);
			}
		);
	}

	public function testAnApprovalAppliesTheChangeThroughTheSavePathAndRemovesIt(): void {
		$this->objects->method('find')->willReturn($this->supplier(['name' => 'Acme', 'iban' => 'NL01OLD', '@self' => ['id' => 'sup-1']]));
		$this->recordSaves();

		$this->listener->handle($this->approved());

		$this->assertCount(1, $this->saved);
		// The stored record with the pending delta over it, as DraftService merges a draft.
		$this->assertSame('Acme', $this->saved[0]['data']['name']);
		$this->assertSame('NL02NEW', $this->saved[0]['data']['iban']);
		$this->assertArrayNotHasKey('@self', $this->saved[0]['data']);
		$this->assertSame('sup-1', $this->saved[0]['options']->uuid);
		$this->assertSame('7', $this->saved[0]['options']->target->register);
		$this->assertSame('12', $this->saved[0]['options']->target->schema);
		$this->assertFalse($this->saved[0]['rbac'], 'the approval authorizes the write');
		$this->assertSame([$this->pending], $this->deleted);
	}

	public function testTheAppliedWriteIsNotHeldAgainByItsOwnGate(): void {
		$this->objects->method('find')->willReturn($this->supplier(['iban' => 'NL01OLD']));
		$gated = null;
		$this->objects->method('saveObject')->willReturnCallback(function (array $data) use (&$gated): ObjectEntity {
			// The save path dispatches the pre-write event to the same gate.
			$gated = new ObjectUpdatingEvent(newObject: $this->supplier($data), oldObject: $this->supplier(['iban' => 'NL01OLD']));
			$this->listener->handle($gated);
			return $this->supplier($data);
		});

		$this->listener->handle($this->approved());

		$this->assertNotNull($gated);
		$this->assertFalse($gated->isPropagationStopped());
		$this->assertSame([], $gated->getErrors());

		// Once applied, a new gated write is held again.
		$later = new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL03']), oldObject: $this->supplier(['iban' => 'NL02NEW']));
		$this->deleted = [];
		$this->listener->handle($later);
		$this->assertTrue($later->isPropagationStopped());
	}

	public function testAHeldCreateIsCreatedUnderItsReservedUuid(): void {
		$this->objects->method('find')->willReturn(null);
		$this->recordSaves();

		$this->listener->handle($this->approved());

		$this->assertSame(['iban' => 'NL02NEW'], $this->saved[0]['data']);
		$this->assertSame('sup-1', $this->saved[0]['options']->uuid);
	}

	public function testAChangeRefusedOnApplyIsLoggedNamingTheApproverAndDiscarded(): void {
		$this->objects->method('find')->willReturn($this->supplier(['iban' => 'NL01OLD']));
		$this->recordSaves(failure: new \RuntimeException('iban: does not match the pattern'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('refused on apply'),
			$this->callback(static fn (array $context): bool => $context['approver'] === 'approver' && $context['reason'] === 'iban: does not match the pattern')
		);

		$this->listener->handle($this->approved());

		$this->assertSame([$this->pending], $this->deleted);
	}

	public function testARejectionDiscardsThePendingChangeAndWritesNothing(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->listener->handle(new TaskSequenceRejectedEvent(sequence: $this->sequence(), task: new Task()));

		$this->assertSame([$this->pending], $this->deleted);
	}

	public function testAnApprovalWithoutApplyChangeLeavesThePendingChange(): void {
		$listener = $this->listener(onApprove: null);
		$this->objects->expects($this->never())->method('saveObject');

		$listener->handle($this->approved());

		$this->assertSame([], $this->deleted);
	}

	public function testASequenceOfAnotherChainOrRecordIsNotActedOn(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->listener->handle($this->approved(chainKey: 'other-chain'));
		$this->listener->handle(new TaskSequenceRejectedEvent(sequence: $this->sequence(chainKey: 'other-chain'), task: new Task()));

		$this->assertSame([], $this->deleted);
	}
}
