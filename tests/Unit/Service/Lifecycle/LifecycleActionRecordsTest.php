<?php

/**
 * Unit tests for the lifecycle action run records: executor, recorder, writer and the save listener together.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Listener\LifecycleActionRecordListener;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRecorder;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use OCA\OpenRegister\Service\Lifecycle\TransitionPayload;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Succeeded, skipped, failed and rolled back: one record per action reached,
 * written only once the save resolved, never `succeeded` for a transition that failed.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionRecorder
 * @covers \OCA\OpenRegister\Service\AutomationRecordWriter
 * @covers \OCA\OpenRegister\Listener\LifecycleActionRecordListener
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor
 * @covers \OCA\OpenRegister\Service\Lifecycle\TransitionPayload
 * @uses   \OCA\OpenRegister\Db\AutomationRecord
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Lifecycle\Action\SetFieldsAction
 * @uses   \OCA\OpenRegister\Lifecycle\LifecycleActionContext
 */
final class LifecycleActionRecordsTest extends TestCase {

	/**
	 * The records the store received.
	 *
	 * @var array<int, AutomationRecord>
	 */
	private array $written = [];

	private LifecycleActionRegistry&MockObject $registry;

	private LifecycleActionRecorder $recorder;

	private LifecycleActionExecutor $executor;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$mapper = $this->createMock(AutomationRecordMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (Entity $record): Entity {
				$this->written[] = $record;
				return $record;
			}
		);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->registry = $this->createMock(LifecycleActionRegistry::class);
		$this->recorder = new LifecycleActionRecorder(new AutomationRecordWriter($mapper, $this->logger));
		$this->executor = new LifecycleActionExecutor($this->registry, $this->createMock(LoggerInterface::class), $this->recorder);
	}

	/**
	 * The object making the transition.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('12');
		$object->setRegister('3');

		return $object;
	}

	/**
	 * A transition `indienen` of the object.
	 */
	private function payload(ObjectEntity $object): TransitionPayload {
		return new TransitionPayload(transition: 'indienen', objectData: ['status' => 'ingediend'], previousData: ['status' => 'concept'], object: $object);
	}

	/**
	 * The outcomes written, as name => outcome|message.
	 *
	 * @return array<int, string>
	 */
	private function outcomes(): array {
		return array_map(
			static fn (AutomationRecord $r): string => $r->getName().'='.$r->getOutcome().'|'.(string)$r->getMessage(),
			$this->written
		);
	}

	public function testASucceededActionIsWrittenOnlyOnceTheSaveResolved(): void {
		$this->registry->method('resolve')->with('set-fields')->willReturn(new SetFieldsAction());
		$object = $this->object();

		$this->executor->run(actions: [['action' => 'set-fields', 'key' => 'aut-91c0', 'actionParameters' => ['x' => 1]]], payload: $this->payload($object));
		$this->assertSame([], $this->written, 'nothing is written before the save resolves');

		(new LifecycleActionRecordListener($this->recorder))->handle(new ObjectUpdatedEvent($object));

		$this->assertCount(1, $this->written);
		$record = $this->written[0];
		$this->assertSame(
			['lifecycle-action', 'aut-91c0', 'set-fields', 'indienen', 'succeeded', '12', '3', 'obj-1'],
			[$record->getKind(), $record->getAutomationKey(), $record->getName(), $record->getTransition(), $record->getOutcome(), $record->getSchemaId(), $record->getRegisterId(), $record->getObjectUuid()]
		);
		$this->assertSame('UTC', $record->getRecordedAt()?->getTimezone()->getName());
		$this->assertSame(0, $this->recorder->flush(uuid: 'obj-1'), 'a flush writes each record once');
	}

	public function testAFalseConditionIsRecordedAsSkipped(): void {
		$this->registry->expects($this->never())->method('resolve');
		$object = $this->object();

		$this->executor->run(actions: [['action' => 'notify', 'condition' => "@self.status == 'afgewezen'"]], payload: $this->payload($object));
		$this->recorder->flush(uuid: 'obj-1');

		$this->assertSame(['notify=skipped|condition is false'], $this->outcomes());
		$this->assertSame('', $this->written[0]->getAutomationKey());
	}

	public function testAFailingActionIsRecordedAndTheEarlierOneIsRolledBack(): void {
		$failing = $this->createMock(LifecycleActionInterface::class);
		$failing->method('execute')->willThrowException(new RuntimeException('zaaktype ontbreekt'));
		$this->registry->method('resolve')->willReturnCallback(
			static fn (string $name): LifecycleActionInterface => $name === 'set-fields' ? new SetFieldsAction() : $failing
		);

		try {
			$this->executor->run(
				actions: [['action' => 'set-fields', 'actionParameters' => ['x' => 1]], ['action' => 'create-case', 'key' => 'aut-2']],
				payload: $this->payload($this->object())
			);
			$this->fail('The transition must still fail');
		} catch (RuntimeException $e) {
			$this->assertSame('zaaktype ontbreekt', $e->getMessage());
		}

		$this->assertSame(
			['set-fields=failed|transition rolled back', 'create-case=failed|zaaktype ontbreekt'],
			$this->outcomes()
		);
		$this->assertSame('aut-2', $this->written[1]->getAutomationKey());
		$this->assertSame(0, $this->recorder->flush(uuid: 'obj-1'), 'nothing stays held');
	}

	public function testAMalformedActionIsRecordedAsFailed(): void {
		try {
			$this->executor->run(actions: ['not an object'], payload: $this->payload($this->object()));
			$this->fail('A malformed action fails loud');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('malformed action', $e->getMessage());
		}

		$this->assertCount(1, $this->written);
		$this->assertSame('failed', $this->written[0]->getOutcome());
		$this->assertSame('', $this->written[0]->getName());
	}

	public function testASaveThatNeverResolvesLeavesNoSucceededRecord(): void {
		$this->registry->method('resolve')->willReturn(new SetFieldsAction());

		$this->executor->run(actions: [['action' => 'set-fields', 'actionParameters' => ['x' => 1]]], payload: $this->payload($this->object()));
		$this->recorder->discard(uuid: 'obj-1');
		(new LifecycleActionRecordListener($this->recorder))->handle(new ObjectUpdatedEvent($this->object()));

		$this->assertSame([], $this->written);
	}

	public function testAnActionOnNoStoredObjectIsWrittenAtOnce(): void {
		$this->registry->method('resolve')->willReturn(new SetFieldsAction());

		$this->executor->run(
			actions: [['action' => 'set-fields', 'key' => 42, 'actionParameters' => ['x' => 1]]],
			payload: new TransitionPayload(transition: 't', objectData: [], previousData: [])
		);

		$this->assertSame(['set-fields=succeeded|'], $this->outcomes());
		$this->assertSame('', $this->written[0]->getAutomationKey(), 'a key that is not text counts as none');
		$this->assertNull($this->written[0]->getObjectUuid());
	}

	public function testTheListenerIgnoresOtherEventsAndObjectsWithoutUuid(): void {
		$this->registry->method('resolve')->willReturn(new SetFieldsAction());
		$this->executor->run(actions: [['action' => 'set-fields', 'actionParameters' => ['x' => 1]]], payload: $this->payload($this->object()));
		$listener = new LifecycleActionRecordListener($this->recorder);

		$listener->handle(new ObjectCreatedEvent($this->object()));
		$listener->handle(new ObjectUpdatedEvent(new ObjectEntity()));

		$this->assertSame([], $this->written);
	}

	public function testAStoreThatRefusesIsLoggedAndNeverThrown(): void {
		$mapper = $this->createMock(AutomationRecordMapper::class);
		$mapper->method('insert')->willThrowException(new RuntimeException('table missing'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[AutomationRecordWriter] could not record lifecycle-action "x" for object obj-1: table missing'
		);
		$record = AutomationRecordWriter::draft(kind: AutomationRecord::KIND_LIFECYCLE_ACTION, key: 'k', name: 'x', object: $this->object());

		(new AutomationRecordWriter($mapper, $this->logger))->write($record);

		$this->assertNotNull($record->getRecordedAt());
	}

	public function testThePayloadKeepsItsObjectThroughAMutation(): void {
		$object  = $this->object();
		$payload = $this->payload($object)->withObjectData(objectData: ['status' => 'x']);

		$this->assertSame($object, $payload->object);
		$this->assertSame(['status' => 'x'], $payload->objectData);
	}
}
