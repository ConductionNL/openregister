<?php

/**
 * Golden test for one portal-task ask: the full task payload and slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskServices;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every field of the created task and of the slot, for a first ask and
 * for a re-ask, with every configurable field set.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskConfig
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskRound
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowSuspension
 * @uses \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowTaskBridge
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 */
class PortalTaskAskGoldenTest extends TestCase {

	/**
	 * A configuration that sets every field the ask reads.
	 *
	 * @var array<string, mixed>
	 */
	private const CONFIG = [
		'title' => 'Send the missing {{ document }}',
		'description' => 'For case {{ @self.uuid }}',
		'dueAt' => '2026-12-01',
		'expiresAt' => '{{ deadline }}',
		'onTimeout' => 'expire',
		'onReject' => 'fail',
		'partyRole' => 'applicant',
		'outcomeKey' => 'answer',
		'uploadRequired' => 'yes',
		'uploadMaxFiles' => 3,
		'uploadAcceptedTypes' => 'application/pdf, image/png',
		'uploadMaxSizeMb' => 2.5,
		'reasonField' => 'review.comment',
		'advance' => 4,
	];

	/**
	 * The recorded payloads and slots.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function golden(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/fixtures/portal-task-ask-golden.json'), true);
	}//end golden()

	/**
	 * Run one firing and return the created payload and the slot.
	 *
	 * @param FlowResumeState $state The run's slots.
	 * @param Task $previous The previous task, read on a re-entry.
	 *
	 * @return array{data: array<string, mixed>, slot: array<string, mixed>}
	 */
	private function fire(FlowResumeState $state, Task $previous): array {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->method('taskOrNull')->willReturn($previous);
		$captured = [];
		$created = new Task();
		$created->setUuid('t-new');
		$bridge->method('createTask')->willReturnCallback(
			static function (array $data) use (&$captured, $created): Task {
				$captured = $data;
				return $created;
			}
		);
		$parties = $this->createMock(PortalPartyResolver::class);
		$parties->method('resolveFromObject')->willReturn('party:kvk-9');
		$delivery = $this->createMock(PortalTaskDeliveryService::class);
		$delivery->method('messageFor')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$node = new PortalTaskNode(new PortalTaskServices($bridge, $parties, $delivery), new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)));

		$items = [
			FlowItems::item(
				json: [
					'document' => 'payslip',
					'deadline' => '2026-12-31',
					'review' => ['comment' => 'The scan is unreadable'],
					'@self' => ['uuid' => 'case-7', 'register' => '3', 'schema' => 9],
				]
			),
		];
		$context = [
			FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
			FlowRunContext::CONTEXT_RUN => 'run-1',
			'runAs' => 'caseworker',
		];
		try {
			$node->execute($items, self::CONFIG, $context);
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt());
		}

		$slot = $state->read(nodeId: 'ask');
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$slot[FlowTaskBridge::SLOT_ASKED_AT]);
		unset($slot[FlowTaskBridge::SLOT_ASKED_AT]);

		return ['data' => $captured, 'slot' => $slot];
	}//end fire()

	/**
	 * The first ask.
	 *
	 * @return void
	 */
	public function testFirstAsk(): void {
		$state = new FlowResumeState();
		$result = $this->fire(state: $state, previous: new Task());
		$this->assertSame(self::golden()['first'], $result);
	}//end testFirstAsk()

	/**
	 * A re-ask after the first task ended.
	 *
	 * @return void
	 */
	public function testReAsk(): void {
		$state = new FlowResumeState();
		$state->write(nodeId: 'ask', values: [FlowTaskBridge::SLOT_TASK_UUID => 't-1', 'cycle' => 2, 'passedAt' => '2026-01-02T00:00:00+00:00']);
		$previous = new Task();
		$previous->setUuid('t-1');
		$previous->setState(Task::STATE_COMPLETED);
		$previous->setIsTerminal(true);
		$result = $this->fire(state: $state, previous: $previous);
		$this->assertSame(self::golden()['reask'], $result);
	}//end testReAsk()
}//end class
