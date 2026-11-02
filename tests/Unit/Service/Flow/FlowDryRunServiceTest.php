<?php

/**
 * A dry-run walks a flow through the real engine against an object: the
 * branch is decided as in a run, a step with side effects is described and
 * not performed, and the object is read without an audit entry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowDryRunService;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/PreviewStepDispatcherTest.php';

/**
 * Tests for FlowDryRunService.
 */
class FlowDryRunServiceTest extends TestCase {
	private DryRunCountingNode $mail;
	private DryRunPureNode $switch;
	private ObjectService $objects;
	private FlowDryRunService $service;

	protected function setUp(): void {
		$this->mail = new DryRunCountingNode('mail');
		$this->switch = new DryRunPureNode('switch');
		$set = new DryRunPureNode('set');
		$nodes = ['mail' => $this->mail, 'switch' => $this->switch, 'set' => $set];

		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturnCallback(static fn (string $type): IFlowNode => $nodes[$type]);

		$flows = $this->createMock(FlowService::class);
		$flows->method('find')->willReturnCallback(function (string $uuid): Flow {
			if ($uuid !== 'f1') {
				throw new DoesNotExistException('No such flow');
			}

			return new Flow();
		});

		$locator = $this->createMock(FlowLocator::class);
		$locator->method('resolveFlow')->willReturn([
			'id' => 'f1',
			'nodes' => [
				[
					'id' => 'check',
					'type' => 'switch',
					'label' => 'Large amount?',
					'exits' => [
						['id' => 'large', 'condition' => ['>' => [['var' => 'json.amount'], 1000]]],
						['id' => 'small'],
					],
				],
				['id' => 'tell', 'type' => 'mail', 'label' => 'Tell the controller'],
				['id' => 'mark', 'type' => 'set', 'label' => 'Mark as small'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'check', 'fromExit' => 'large', 'to' => 'tell'],
				['id' => 'e2', 'from' => 'check', 'fromExit' => 'small', 'to' => 'mark'],
			],
		]);

		$this->objects = $this->createMock(ObjectService::class);

		$this->service = new FlowDryRunService(
			flows: $flows,
			locator: $locator,
			objects: $this->objects,
			engine: new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class)),
			registry: $registry
		);
	}

	/**
	 * An object found as the caller may read it, without an audit entry.
	 *
	 * @param array $data The object data.
	 *
	 * @return void
	 */
	private function objectIs(array $data): void {
		$object = new ObjectEntity();
		$object->setUuid('o1');
		$object->setObject($data);
		$this->objects->expects($this->once())->method('find')
			->with('o1', [], false, 'dossiq', 'case', true, true, true, false)
			->willReturn($object);
	}

	public function testTakesTheBranchTheObjectLeadsToAndPerformsNoSideEffect(): void {
		$this->objectIs(['amount' => 5000]);

		$result = $this->service->dryRun(flowId: 'f1', register: 'dossiq', schema: 'case', objectId: 'o1');

		self::assertSame(['check', 'tell'], array_column($result['steps'], 'nodeId'));
		self::assertSame(['Large amount?', 'Tell the controller'], array_column($result['steps'], 'label'));
		self::assertSame(['performed' => false, 'summary' => 'Counting mail would run'], $result['steps'][1]['preview']);
		self::assertSame(0, $this->mail->runs);
		self::assertSame('completed', $result['status']);
	}

	public function testTakesTheOtherBranchForAnotherObject(): void {
		$this->objectIs(['amount' => 20]);

		$result = $this->service->dryRun(flowId: 'f1', register: 'dossiq', schema: 'case', objectId: 'o1');

		self::assertSame(['check', 'mark'], array_column($result['steps'], 'nodeId'));
		self::assertTrue($result['steps'][1]['preview']['performed']);
	}

	public function testRefusesAFlowTheCallerCannotSee(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service->dryRun(flowId: 'other', register: 'dossiq', schema: 'case', objectId: 'o1');
	}

	public function testRefusesAnObjectTheCallerCannotRead(): void {
		$this->objects->method('find')->willReturn(null);

		$this->expectException(DoesNotExistException::class);
		$this->service->dryRun(flowId: 'f1', register: 'dossiq', schema: 'case', objectId: 'o1');
	}
}
