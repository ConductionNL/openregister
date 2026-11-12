<?php

/**
 * What the Nextcloud Flow operation shows and which runs it queues.
 *
 * Characterisation of the operation before its collaborators are bundled:
 * the rule-builder labels, the validation refusal, and the three outcomes of
 * resolving a flow by name (queued, refused as ambiguous, refused as not
 * dispatchable).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\WorkflowEngine
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\WorkflowEngine;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\WorkflowEngine\RunFlowOperation;
use OCA\OpenRegister\WorkflowEngine\RunFlowOperationFlows;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * @covers \OCA\OpenRegister\WorkflowEngine\RunFlowOperation
 */
final class RunFlowOperationTest extends TestCase {

	/**
	 * The stored flows.
	 *
	 * @var array<int, Flow>
	 */
	private array $flows = [];

	/**
	 * Every queue() call, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	/**
	 * Every warning logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * Build the operation over the fixture.
	 *
	 * @return RunFlowOperation The operation.
	 */
	private function operation(): RunFlowOperation {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/' . $app . '/' . $file);

		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willReturnCallback(fn (): array => $this->flows);

		$runner = $this->createMock(FlowRunServiceInterface::class);
		$runner->method('queueRun')->willReturnCallback(
			function (string $flowId, FlowRunQueueOptions $options) {
				$this->queued[] = ['flowId' => $flowId, 'subject' => $options->subject, 'trigger' => $options->trigger, 'user' => $options->user];

				return new \OCA\OpenRegister\Db\FlowRun();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return new RunFlowOperation(new FlowNodePresentation($l10n, $urls), new RunFlowOperationFlows($mapper, $runner), $logger);
	}//end operation()

	/**
	 * A stored flow.
	 *
	 * @param string      $uuid    The uuid.
	 * @param string      $name    The name.
	 * @param bool        $enabled Whether it is enabled.
	 * @param string|null $owner   The owner.
	 *
	 * @return Flow The flow.
	 */
	private function flow(string $uuid, string $name, bool $enabled = true, ?string $owner = 'alice'): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName($name);
		$flow->setEnabled($enabled);
		$flow->setOwner($owner);

		return $flow;
	}//end flow()

	/**
	 * Fire the operation for an object-created event and these rule operations.
	 *
	 * @param array<int, string> $operations The configured flow names.
	 *
	 * @return void
	 */
	private function fire(array $operations): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('5');
		$object->setSchema('7');

		/** @var IRuleMatcher&MockObject $matcher */
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->method('getFlows')->willReturn(
			array_map(static fn (string $op): array => ['operation' => $op], $operations)
		);

		$this->operation()->onEvent(ObjectCreatedEvent::class, new ObjectCreatedEvent($object), $matcher);
	}//end fire()

	/**
	 * The rule builder labels come from the translator and the URL generator.
	 *
	 * @return void
	 */
	public function testLabelsAndScope(): void {
		$operation = $this->operation();

		$this->assertSame('T:Run an OpenRegister flow', $operation->getDisplayName());
		$this->assertStringStartsWith('T:Run a flow declared', $operation->getDescription());
		$this->assertSame('/core/actions/play.svg', $operation->getIcon());
		$this->assertTrue($operation->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($operation->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($operation->isAvailableForScope(99));
	}//end testLabelsAndScope()

	/**
	 * An empty flow name is refused with a translated message.
	 *
	 * @return void
	 */
	public function testAnEmptyFlowNameIsRefused(): void {
		$this->operation()->validateOperation('rule', [], 'flow-a');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('T:Please provide the name of the OpenRegister flow to run.');
		$this->operation()->validateOperation('rule', [], '  ');
	}//end testAnEmptyFlowNameIsRefused()

	/**
	 * One dispatchable flow of that name: queued as its owner, about the object.
	 *
	 * @return void
	 */
	public function testAUniqueFlowIsQueuedAsItsOwner(): void {
		$this->flows = [$this->flow('u-1', 'Intake'), $this->flow('u-2', 'Other')];
		$this->fire(['Intake', '']);

		$this->assertSame(
			[['flowId' => 'u-1', 'subject' => ['uuid' => 'obj-1', 'register' => '5', 'schema' => '7'], 'trigger' => 'nextcloud-flow', 'user' => 'alice']],
			$this->queued
		);
	}//end testAUniqueFlowIsQueuedAsItsOwner()

	/**
	 * Two flows sharing the name: nothing is queued, and the refusal is logged.
	 *
	 * @return void
	 */
	public function testAnAmbiguousNameIsRefused(): void {
		$this->flows = [$this->flow('u-1', 'Intake'), $this->flow('u-2', 'Intake')];
		$this->fire(['Intake']);

		$this->assertSame([], $this->queued);
		$this->assertSame(['RunFlowOperation: flow "Intake" resolved to 2 flows; refusing to guess which one the rule meant.'], $this->warnings);
	}//end testAnAmbiguousNameIsRefused()

	/**
	 * A disabled or ownerless flow is not dispatched.
	 *
	 * @return void
	 */
	public function testANonDispatchableFlowIsRefused(): void {
		$this->flows = [$this->flow('u-1', 'Off', false), $this->flow('u-2', 'Orphan', true, null)];
		$this->fire(['Off', 'Orphan']);

		$this->assertSame([], $this->queued);
		$this->assertSame(
			[
				'RunFlowOperation: flow "Off" is disabled or has no owner, so it was not dispatched.',
				'RunFlowOperation: flow "Orphan" is disabled or has no owner, so it was not dispatched.',
			],
			$this->warnings
		);
	}//end testANonDispatchableFlowIsRefused()

	/**
	 * An event that carries no object queues nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventDoesNothing(): void {
		$this->flows = [$this->flow('u-1', 'Intake')];
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects($this->never())->method('getFlows');

		$this->operation()->onEvent('x', new Event(), $matcher);

		$this->assertSame([], $this->queued);
	}//end testAnUnrelatedEventDoesNothing()

	/**
	 * A matcher that cannot list its flows queues nothing and logs no warning.
	 *
	 * @return void
	 */
	public function testAFailingMatcherQueuesNothing(): void {
		$this->flows = [$this->flow('u-1', 'Intake')];
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->method('getFlows')->willThrowException(new \RuntimeException('no rules'));

		$this->operation()->onEvent(ObjectCreatedEvent::class, new ObjectCreatedEvent($object), $matcher);

		$this->assertSame([], $this->queued);
		$this->assertSame([], $this->warnings);
	}//end testAFailingMatcherQueuesNothing()
}//end class
