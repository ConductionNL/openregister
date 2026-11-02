<?php

/**
 * Unit tests for what a macro run's audit entry says it changed.
 *
 * The board line "2 wijzigingen: termijn opgeschort, bericht verstuurd" (dossiq
 * DqZaakAssistent) is drawn from this list, so a step that read, routed or
 * failed must not count, and a write, a message and a task must.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Flow\MacroRunChanges;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MacroRunChangesTest extends TestCase {

	/**
	 * A run log as FlowEngine writes it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function log(): array {
		return [
			['transition' => 'Start', 'type' => 'openregister.trigger-manual', 'status' => 'completed'],
			['transition' => 'Read the case', 'type' => 'openregister.object-read', 'status' => 'completed'],
			['transition' => 'Termijn opgeschort', 'type' => 'openregister.object-write', 'status' => 'completed'],
			['transition' => 'Bericht verstuurd', 'type' => 'openregister.send-email', 'status' => 'completed'],
			['transition' => 'Taak Publiceren', 'type' => 'openregister.user-task', 'status' => 'failed'],
			'not an entry',
		];
	}//end log()

	public function testCountsCompletedWritesMessagesAndTasksInOrder(): void {
		$this->assertSame(
			[
				['step' => 'Termijn opgeschort', 'kind' => 'write'],
				['step' => 'Bericht verstuurd', 'kind' => 'message'],
			],
			(new MacroRunChanges())->fromLog(log: $this->log())
		);
	}//end testCountsCompletedWritesMessagesAndTasksInOrder()

	public function testATaskStepIsAChange(): void {
		$changes = (new MacroRunChanges())->fromLog(log: [
			['transition' => 'Taak Publiceren', 'type' => 'openregister.portal-task', 'status' => 'completed'],
			['transition' => 'Melding', 'type' => 'openregister.send-notification', 'status' => 'completed'],
		]);
		$this->assertSame(['task', 'message'], array_column($changes, 'kind'));
	}//end testATaskStepIsAChange()

	public function testAStepIsNamedByItsNodesLabelWhenItHasOne(): void {
		$changes = (new MacroRunChanges())->fromLog(
			log: [
				['transition' => 'set-term', 'type' => 'openregister.object-write', 'status' => 'completed'],
				['transition' => 'send-mail', 'type' => 'openregister.send-email', 'status' => 'completed'],
			],
			nodes: [
				['id' => 'set-term', 'type' => 'openregister.object-write', 'label' => 'termijn opgeschort'],
				['id' => 'send-mail', 'type' => 'openregister.send-email'],
			]
		);
		$this->assertSame(['termijn opgeschort', 'send-mail'], array_column($changes, 'step'));
	}//end testAStepIsNamedByItsNodesLabelWhenItHasOne()

	public function testAnAppsLabelledStepCounts(): void {
		$changes = (new MacroRunChanges())->fromLog(
			log: [
				['transition' => 'suspend', 'type' => 'dossiq.suspendTerm', 'status' => 'completed'],
				['transition' => 'route', 'type' => 'dossiq.other', 'status' => 'completed'],
			],
			nodes: [
				['id' => 'suspend', 'type' => 'dossiq.suspendTerm', 'label' => 'termijn opgeschort'],
				['id' => 'route', 'type' => 'dossiq.other'],
			]
		);
		$this->assertSame([['step' => 'termijn opgeschort', 'kind' => 'step']], $changes);
	}//end testAnAppsLabelledStepCounts()

	public function testAnEmptyLogChangedNothing(): void {
		$this->assertSame([], (new MacroRunChanges())->fromLog(log: []));
	}//end testAnEmptyLogChangedNothing()

	public function testTheAuditEntryCarriesTheChanges(): void {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects($this->once())
			->method('createAuditTrailEntry')
			->with($this->anything(), 'action.macro', $this->callback(function (array $context): bool {
				return $context['declaredAction'] === 'suspend'
					&& $context['run'] === 'r-1'
					&& $context['changes'] === [['step' => 'Termijn opgeschort', 'kind' => 'write']];
			}));

		$resolver = new MacroActionResolver(
			$this->createMock(SchemaMapper::class),
			$this->createMock(FlowService::class),
			$audit,
			$this->createMock(LoggerInterface::class)
		);

		$resolver->recordRun(
			object: new ObjectEntity(),
			action: 'suspend',
			flow: 'f-1',
			run: 'r-1',
			userId: 'pieter',
			changes: [['step' => 'Termijn opgeschort', 'kind' => 'write']]
		);
	}//end testTheAuditEntryCarriesTheChanges()
}//end class
