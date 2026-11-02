<?php

/**
 * Unit tests for an object's action history: action.run and action.macro as one kind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectActionHistoryTest extends TestCase {

	private AuditTrailMapper&MockObject $audit;

	private ObjectActionGate&MockObject $gate;

	protected function setUp(): void {
		parent::setUp();

		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->gate = $this->createMock(ObjectActionGate::class);
	}//end setUp()

	/**
	 * An audit row.
	 *
	 * @param string $action  The audit action.
	 * @param array  $changed The context.
	 *
	 * @return AuditTrail
	 */
	private function row(string $action, array $changed): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid('a-' . $action);
		$row->setAction($action);
		$row->setChanged($changed);
		$row->setUser('anna');
		$row->setUserName('Anna');
		$row->setCreated(new DateTime('2026-10-21T10:00:00+00:00'));
		return $row;
	}//end row()

	/**
	 * A case.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		return $object;
	}//end object()

	public function testTheHistoryNamesWhatTheLastActionDidAndKeepsOldMacroEntries(): void {
		$this->gate->method('may')->willReturn(true);
		$this->audit->expects($this->once())->method('findAll')
			->with(100, null, ['object_uuid' => 'case-1', 'action' => 'action.run,action.macro'], ['created' => 'DESC', 'id' => 'DESC'])
			->willReturn(
				[
					$this->row(
						'action.run',
						[
							'declaredAction' => 'hold',
							'kind' => 'steps',
							'run' => 'run-2',
							'inputsDigest' => 'sha256:x',
							'changes' => [['step' => 'Status gezet', 'kind' => 'write'], ['step' => 'Indiener geïnformeerd', 'kind' => 'message']],
							'outcome' => 'completed',
						]
					),
					$this->row('action.macro', ['declaredAction' => 'close-and-notify', 'flow' => 'f', 'run' => 'run-1', 'changes' => []]),
				]
			);

		$entries = (new ObjectActionHistory($this->audit, $this->gate))->forObject($this->object(), new Schema());

		$this->assertSame('hold', $entries[0]['action']);
		$this->assertSame('run-2', $entries[0]['run']);
		$this->assertSame(['write', 'message'], array_column($entries[0]['changes'], 'kind'));
		$this->assertSame('/api/flow-runs/run-2', $entries[0]['links']['run']);
		$this->assertSame('close-and-notify', $entries[1]['action']);
		$this->assertSame('flow', $entries[1]['kind']);
		$this->assertSame('2026-10-21T10:00:00+00:00', $entries[1]['created']);
	}//end testTheHistoryNamesWhatTheLastActionDidAndKeepsOldMacroEntries()

	public function testTheHistoryNeedsRead(): void {
		$this->gate->method('may')->with($this->anything(), 'read', $this->anything())->willReturn(false);
		$this->audit->expects($this->never())->method('findAll');

		$this->expectException(NotAuthorizedException::class);

		(new ObjectActionHistory($this->audit, $this->gate))->forObject($this->object(), new Schema());
	}//end testTheHistoryNeedsRead()
}//end class
