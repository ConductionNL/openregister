<?php

/**
 * FlowWiring builds the lifecycle request state and the hand-built task services.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Db\TaskBlockerMapperInterface;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskSubstitution;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCA\OpenRegister\Service\TaskService;
use OCA\OpenRegister\Service\FlowLinkService;
use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowWiring
 */
final class FlowWiringTaskServicesTest extends TestCase {

	/**
	 * Each lifecycle and task factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks
	 * for; a final class it cannot double is handed over unconstructed.
	 *
	 * @return void
	 */
	public function testEachFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$doubles   = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				if (isset($doubles[$id]) === true) {
					return $doubles[$id];
				}

				$class = new ReflectionClass(objectOrClass: $id);
				if ($class->isFinal() === true) {
					$doubles[$id] = $class->newInstanceWithoutConstructor();
				} else {
					$doubles[$id] = $this->createMock(originalClassName: $id);
				}

				return $doubles[$id];
			}
		);

		$names = [LifecycleActionContext::class, AutoTransitionPass::class, TaskInboxService::class, PortalPartyResolver::class, TaskService::class, FlowLinkService::class];
		foreach ($names as $name) {
			$this->assertInstanceOf(expected: $name, actual: $context->factories[$name]($container), message: $name);
		}
	}//end testEachFactoryBuildsItsService()

	/**
	 * The task inbox is built with the substitution service, so inbox rows
	 * carry assigneeAbsent and absentUntil.
	 *
	 * @return void
	 */
	public function testTheTaskInboxIsBuiltWithTheSubstitutionService(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$asked     = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked) {
				$asked[] = $id;
				$class   = new ReflectionClass(objectOrClass: $id);
				if ($class->isFinal() === true) {
					return $class->newInstanceWithoutConstructor();
				}

				return $this->createMock(originalClassName: $id);
			}
		);

		$inbox = $context->factories[TaskInboxService::class]($container);

		$this->assertContains(needle: TaskSubstitution::class, haystack: $asked);
		$property = new \ReflectionProperty(TaskInboxService::class, 'substitution');
		$this->assertInstanceOf(expected: TaskSubstitution::class, actual: $property->getValue($inbox));
	}//end testTheTaskInboxIsBuiltWithTheSubstitutionService()

	/**
	 * The task inbox is built with the blocker lookup, so a row waiting on an
	 * open task carries `blocked: true` (found live: without it every row read
	 * as not blocked).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function testTheTaskInboxIsBuiltWithTheBlockerLookup(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$asked     = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked) {
				$asked[] = $id;
				$class   = new ReflectionClass(objectOrClass: $id);
				if ($class->isFinal() === true) {
					return $class->newInstanceWithoutConstructor();
				}

				return $this->createMock(originalClassName: $id);
			}
		);

		$inbox = $context->factories[TaskInboxService::class]($container);

		$this->assertContains(needle: TaskBlockerMapperInterface::class, haystack: $asked);
		$property = new \ReflectionProperty(TaskInboxService::class, 'blockers');
		$this->assertInstanceOf(expected: TaskBlockerMapperInterface::class, actual: $property->getValue($inbox));
	}//end testTheTaskInboxIsBuiltWithTheBlockerLookup()

	/**
	 * TaskService is still built when the container cannot build its write-back
	 * gate; it then refuses updates of projected VTODOs.
	 *
	 * @return void
	 */
	public function testTaskServiceIsBuiltWithoutTheWriteBackGate(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($logger) {
				if ($id === TaskVtodoWriteBackGate::class) {
					throw new RuntimeException(message: 'not buildable');
				}

				if ($id === 'Psr\Log\LoggerInterface') {
					return $logger;
				}

				return $this->createMock(originalClassName: $id);
			}
		);

		$this->assertInstanceOf(
			expected: TaskService::class,
			actual: $context->factories[TaskService::class]($container)
		);
	}//end testTaskServiceIsBuiltWithoutTheWriteBackGate()

	/**
	 * The flow link service is wired by the flow registrar only, not by the
	 * integration provider table.
	 *
	 * @return void
	 */
	public function testTheFlowLinkServiceIsWiredHereOnly(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$this->assertArrayHasKey(key: FlowLinkService::class, array: $context->factories);
		$this->assertArrayNotHasKey(key: FlowLinkService::class, array: BuiltinIntegrationServices::FACTORIES);
	}//end testTheFlowLinkServiceIsWiredHereOnly()

	/**
	 * The flow link service reads the same container entries, in the same
	 * order, as its entry in the integration provider table did.
	 *
	 * @return void
	 */
	public function testTheFlowLinkServiceReadsItsEntriesInOrder(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$reads     = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$reads) {
				$reads[] = $id;
				return $this->createMock(originalClassName: $id);
			}
		);

		$this->assertInstanceOf(expected: FlowLinkService::class, actual: $context->factories[FlowLinkService::class]($container));
		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Db\FlowLinkMapperInterface',
				'OCP\IDBConnection',
				'OCP\App\IAppManager',
				'OCP\IUserSession',
				'OCP\IGroupManager',
				'Psr\Log\LoggerInterface',
			],
			actual: $reads
		);
	}//end testTheFlowLinkServiceReadsItsEntriesInOrder()
}//end class
