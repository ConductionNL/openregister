<?php

/**
 * Unit tests for inline steps: lowering, save-time refusals and the stored flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNode;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ObjectActionStepsFlowTest extends TestCase {

	private FlowNodeRegistry&MockObject $registry;

	private FlowMapper&MockObject $flows;

	private FlowVersionService&MockObject $versions;

	private ObjectActionStepsFlow $steps;

	protected function setUp(): void {
		parent::setUp();

		$this->registry = $this->createMock(FlowNodeRegistry::class);
		$this->flows = $this->createMock(FlowMapper::class);
		$this->versions = $this->createMock(FlowVersionService::class);
		$this->steps = new ObjectActionStepsFlow($this->registry, new FlowDefinitionBuilder(), $this->flows, $this->versions);
	}//end setUp()

	/**
	 * A configuration declaring `publish` with these steps.
	 *
	 * @param array $steps The steps.
	 *
	 * @return array The configuration.
	 */
	private function configuration(array $steps): array {
		return ['x-openregister-action' => ['publish' => ['name' => 'Publish', 'description' => 'x', 'steps' => $steps]]];
	}//end configuration()

	/**
	 * The `publish` steps action.
	 *
	 * @return ObjectActionDefinition
	 */
	private function definition(): ObjectActionDefinition {
		return ObjectActionDefinition::fromDeclaration(
			'publish',
			[
				'next' => 'list',
				'steps' => [['id' => 'date', 'type' => 'openregister.set-fields', 'config' => ['set' => ['publicatiedatum' => '{{inputs.from}}']]]],
			]
		);
	}//end definition()

	/**
	 * The schema declaring it.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');
		return $schema;
	}//end schema()

	public function testStepsLowerToAStraightLineThatBuilds(): void {
		$builder = new FlowDefinitionBuilder();
		$document = $builder->lowerSteps([['id' => 'a', 'type' => 'openregister.set-fields', 'label' => 'Datum gezet']], 'next');

		$types = array_column($document['nodes'], 'type');
		$this->assertSame([FlowNextHint::MANUAL_TRIGGER, ActionInputsNode::TYPE, 'openregister.set-fields', FlowNextHint::END_NODE], $types);
		$this->assertSame('Datum gezet', $document['nodes'][2]['label']);
		$this->assertSame(['next' => 'next'], $document['nodes'][3]['config']);
		$this->assertCount(3, $document['edges']);
		$this->assertSame('trigger', $document['edges'][0]['from']);
		$this->assertSame('end', $document['edges'][2]['to']);

		$builder->build($document);
	}//end testStepsLowerToAStraightLineThatBuilds()

	public function testABranchingStepIsRefusedWithTheWayOut(): void {
		$refusals = $this->steps->refusals($this->configuration([['type' => 'openregister.set-fields'], ['type' => 'openregister.user-task']]));

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"publish"', $refusals[0]);
		$this->assertStringContainsString('step 2', $refusals[0]);
		$this->assertStringContainsString('bind it with "flow"', $refusals[0]);
	}//end testABranchingStepIsRefusedWithTheWayOut()

	public function testATriggerOrEndStepIsRefused(): void {
		$this->registry->method('isTrigger')->willReturnCallback(static fn (string $type): bool => $type === 'openregister.trigger-object');

		$this->assertCount(1, $this->steps->refusals($this->configuration([['type' => 'openregister.trigger-object']])));
	}//end testATriggerOrEndStepIsRefused()

	public function testAnUnknownNodeIsRefused(): void {
		$this->registry->method('get')->willThrowException(new UnexpectedValueException('unknown'));

		$refusals = $this->steps->refusals($this->configuration([['type' => 'hermiq.agent-step']]));

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('no installed app provides', $refusals[0]);
	}//end testAnUnknownNodeIsRefused()

	public function testAConfigTheNodeRefusesIsRefused(): void {
		$node = $this->createMock(IFlowNode::class);
		$node->method('validateConfig')->willThrowException(new UnexpectedValueException('Choose at least one field.'));
		$this->registry->method('get')->willReturn($node);

		$refusals = $this->steps->refusals($this->configuration([['type' => 'openregister.set-fields', 'config' => []]]));

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('Choose at least one field.', $refusals[0]);
	}//end testAConfigTheNodeRefusesIsRefused()

	public function testSoundStepsAreAccepted(): void {
		$this->registry->method('get')->willReturn($this->createMock(IFlowNode::class));

		$this->assertSame([], $this->steps->refusals($this->configuration([['type' => 'openregister.set-fields', 'config' => ['set' => ['a' => 1]]]])));
	}//end testSoundStepsAreAccepted()

	public function testTheFirstRunStoresAndPublishesAnInertSystemFlow(): void {
		$this->flows->method('findDeclared')->willReturn(null);
		$this->flows->expects($this->once())->method('insert')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->assertFalse($flow->getEnabled());
				$this->assertNull($flow->getOwner());
				$this->assertSame('besluit', $flow->getTriggerSchema());
				$this->assertStringStartsWith('action:publish:', $flow->getName());
				return $flow;
			}
		);
		$this->versions->expects($this->once())->method('publish');

		$uuid = $this->steps->flowFor($this->schema(), $this->definition());

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
	}//end testTheFirstRunStoresAndPublishesAnInertSystemFlow()

	public function testTheSameStepsReuseTheStoredFlow(): void {
		$names = [];
		$this->flows->method('findDeclared')->willReturnCallback(
			function (string $name) use (&$names): Flow {
				$names[] = $name;
				$flow = new Flow();
				$flow->setUuid('flow-7');
				$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
				return $flow;
			}
		);
		$this->flows->expects($this->never())->method('insert');
		$this->versions->expects($this->never())->method('publish');

		$this->assertSame('flow-7', $this->steps->flowFor($this->schema(), $this->definition()));
		$this->assertSame('flow-7', $this->steps->flowFor($this->schema(), $this->definition()));
		$this->assertSame($names[0], $names[1]);
	}//end testTheSameStepsReuseTheStoredFlow()

	public function testAFlowThatCannotBePublishedIsAnError(): void {
		$this->flows->method('findDeclared')->willReturn(null);
		$this->flows->method('insert')->willReturnArgument(0);
		$this->versions->method('publish')->willThrowException(new \RuntimeException('dead end'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('could not be stored as a flow: dead end');

		$this->steps->flowFor($this->schema(), $this->definition());
	}//end testAFlowThatCannotBePublishedIsAnError()

	public function testTheInputsStepPutsContextAndHeldInputsOnEveryItem(): void {
		$node = new ActionInputsNode($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class));
		$ref = ObjectActionSecrets::hold(['pin' => '1234']);

		$out = $node->execute(
			[FlowItems::item(json: ['title' => 'Besluit'])],
			[],
			['inputs' => ['from' => '2026-11-01'], ObjectActionSecrets::CONTEXT_KEY => $ref]
		);
		ObjectActionSecrets::release($ref);

		$this->assertSame(['from' => '2026-11-01', 'pin' => '1234'], $out[0][FlowItems::JSON]['inputs']);
		$this->assertSame('Besluit', $out[0][FlowItems::JSON]['title']);
		$this->assertSame([], ObjectActionSecrets::read($ref));
	}//end testTheInputsStepPutsContextAndHeldInputsOnEveryItem()
}//end class
