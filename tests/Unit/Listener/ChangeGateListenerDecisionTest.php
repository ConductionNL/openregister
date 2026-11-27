<?php

/**
 * ChangeGateListener — a held change starts its chain's decision, which the submitter may not take.
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
use OCA\OpenRegister\Db\TaskSequenceMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\TaskSeparationOfDutiesException;
use OCA\OpenRegister\Listener\ChangeGateListener;
use OCA\OpenRegister\Listener\Support\ApprovalChainSequences;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Task\ResolvedChain;
use OCA\OpenRegister\Service\Task\SequenceAnchor;
use OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Changing a supplier's IBAN as `clerk` holds the change and opens the
 * `bank-details` sequence with `clerk` as its requester; `clerk`'s own
 * decision on it is refused for separation of duties.
 *
 * @covers \OCA\OpenRegister\Listener\ChangeGateListener
 * @uses \OCA\OpenRegister\Listener\Support\ApprovalChainSequences
 * @uses \OCA\OpenRegister\Service\Object\DraftService
 * @uses \OCA\OpenRegister\Service\Task\ChangeGateDeclaration
 * @uses \OCA\OpenRegister\Service\Task\ResolvedChain
 * @uses \OCA\OpenRegister\Service\Task\SequenceAnchor
 * @uses \OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskSequence
 * @uses \OCA\OpenRegister\Db\DraftVersion
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Exception\TaskSeparationOfDutiesException
 *
 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.1
 */
class ChangeGateListenerDecisionTest extends TestCase {

	/** @var DraftVersionMapper&MockObject */
	private DraftVersionMapper $draftVersions;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var ApprovalChainAnnotationInstaller&MockObject */
	private ApprovalChainAnnotationInstaller $installer;

	/** @var TaskSequenceServiceInterface&MockObject */
	private TaskSequenceServiceInterface $sequenceService;

	/** @var array<int, DraftVersion> */
	private array $inserted = [];

	/** @var array<int, array{chain: ResolvedChain, anchor: SequenceAnchor}> */
	private array $provisioned = [];

	protected function setUp(): void {
		$this->draftVersions = $this->createMock(DraftVersionMapper::class);
		$this->draftVersions->method('findForObject')->willReturn([]);
		$this->draftVersions->method('findByKey')->willReturn(null);
		$this->draftVersions->method('insert')->willReturnCallback(function (DraftVersion $draft): DraftVersion {
			$this->inserted[] = $draft;
			return $draft;
		});

		$schema = new Schema();
		$schema->setId(12);
		$schema->setConfiguration(['x-openregister-approval-chains' => [
			'bank-details' => ['gate' => ['change' => ['properties' => ['iban']]], 'approvers' => [['role' => 'financieel-beheer']]],
		]]);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->schemas->method('find')->willReturn($schema);

		$this->installer = $this->createMock(ApprovalChainAnnotationInstaller::class);
		$this->sequenceService = $this->createMock(TaskSequenceServiceInterface::class);
		$this->sequenceService->method('chain')->willReturnCallback(fn (array $template, ?array $tierPositions = null): ResolvedChain => new ResolvedChain(template: $template, tierPositions: $tierPositions));
		$this->sequenceService->method('anchorOf')->willReturnCallback(fn (string $objectUuid, ?string $requesterId, ?int $registerId = null): SequenceAnchor => new SequenceAnchor(objectUuid: $objectUuid, requesterId: $requesterId, registerId: $registerId));
		$this->sequenceService->method('provision')->willReturnCallback(function (ResolvedChain $chain, SequenceAnchor $anchor): TaskSequence {
			$this->provisioned[] = ['chain' => $chain, 'anchor' => $anchor];
			return new TaskSequence();
		});
	}

	private function listener(): ChangeGateListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['clerks']);

		return new ChangeGateListener(
			$this->schemas,
			new DraftService($this->draftVersions, $this->createMock(PermissionCheckInterface::class)),
			$this->draftVersions,
			$session,
			$groups,
			$this->createMock(LoggerInterface::class),
			new ApprovalChainSequences(
				sequenceMapper: $this->createMock(TaskSequenceMapperInterface::class),
				sequenceService: $this->sequenceService,
				installer: $this->installer
			)
		);
	}

	private function supplier(array $data, string $uuid = 'sup-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setObject($data);
		return $object;
	}

	public function testAHeldChangeStartsTheChainsSequenceWithTheSubmitterAsRequester(): void {
		$template = ['templateId' => 'schema-12-bank-details', 'positions' => [['role' => 'financieel-beheer']]];
		$this->installer->expects($this->once())->method('compile')
			->with($this->isInstanceOf(Schema::class), 'bank-details')
			->willReturn($template);
		$event = new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL02NEW']), oldObject: $this->supplier(['iban' => 'NL01OLD']));

		$this->listener()->handle($event);

		$this->assertSame(202, $event->getErrors()['status']);
		$this->assertCount(1, $this->inserted);
		$this->assertCount(1, $this->provisioned);
		$this->assertSame($template, $this->provisioned[0]['chain']->template);
		$this->assertSame('sup-1', $this->provisioned[0]['anchor']->objectUuid);
		$this->assertSame('clerk', $this->provisioned[0]['anchor']->requesterId);
		$this->assertSame(7, $this->provisioned[0]['anchor']->registerId);
	}

	public function testAHeldCreateAnchorsTheSequenceOnTheReservedUuid(): void {
		$this->installer->method('compile')->willReturn(['templateId' => 't', 'positions' => [['role' => 'r']]]);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$schema = new Schema();
		$schema->setId(12);
		$schema->setConfiguration(['x-openregister-approval-chains' => [
			'new-supplier' => ['gate' => ['change' => ['properties' => [], 'on' => ['create']]], 'approvers' => [['role' => 'r']]],
		]]);
		$this->schemas->method('find')->willReturn($schema);
		$event = new ObjectCreatingEvent(object: $this->supplier(['name' => 'Acme'], 'new-1'));

		$this->listener()->handle($event);

		$this->assertSame(202, $event->getErrors()['status']);
		$this->assertSame('new-1', $this->provisioned[0]['anchor']->objectUuid);
	}

	public function testAChainThatCannotStartItsDecisionHoldsNothingAndRefuses(): void {
		$this->installer->method('compile')->willReturn(null);
		$event = new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL02NEW']), oldObject: $this->supplier(['iban' => 'NL01OLD']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(ChangeGateListener::CODE_MISCONFIGURED, $event->getErrors()['code']);
		$this->assertSame([], $this->inserted, 'no pending change waits for a decision nobody can take');
		$this->assertSame([], $this->provisioned);
	}

	public function testAnUngatedChangeStartsNothing(): void {
		$this->installer->expects($this->never())->method('compile');
		$event = new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL01', 'phone' => '020']), oldObject: $this->supplier(['iban' => 'NL01', 'phone' => '010']));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $this->provisioned);
	}

	public function testTheSubmittersOwnDecisionIsRefusedForSeparationOfDuties(): void {
		$this->installer->method('compile')->willReturn(['templateId' => 't', 'positions' => [['role' => 'financieel-beheer']]]);
		$this->listener()->handle(new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL02']), oldObject: $this->supplier(['iban' => 'NL01'])));

		// The sequence as provisioned for the held change: its requester is the submitter.
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setRequesterId($this->provisioned[0]['anchor']->requesterId);
		$sequenceMapper = $this->createMock(TaskSequenceMapperInterface::class);
		$sequenceMapper->method('findByUuid')->willReturn($sequence);
		$task = new Task();
		$task->setSequenceUuid('seq-1');
		$guard = new TaskSequenceDecisionGuard($sequenceMapper);

		$guard->assertDecidable(task: $task, actor: 'approver');

		$this->expectException(TaskSeparationOfDutiesException::class);
		$this->expectExceptionMessage("Separation of duties: 'clerk' requested this approval and may not decide it.");
		$guard->assertDecidable(task: $task, actor: 'clerk');
	}
}
