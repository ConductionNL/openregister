<?php

/**
 * FlowRegistrar registers the flow run guards, run context, step dispatcher, lifecycle state and task services in a fixed order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\ProcessesServiceBindings;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;

class FlowRegistrarTest extends TestCase {

	/**
	 * register() registers the flow run guards, run context, step dispatcher, lifecycle state and task services, in this order.
	 *
	 * @return void
	 */
	public function testRegistersTheServicesInOrder(): void {
		$context = new RecordingRegistrationContext();
		FlowRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Service\Flow\FlowRunAuthorization',
				'OCA\OpenRegister\Service\Flow\FlowRunnableGuard',
				'OCA\OpenRegister\Service\Flow\FlowRunContext',
				'OCA\OpenRegister\Service\Flow\FlowStepDispatcher',
				'OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext',
				'OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass',
				'OCA\OpenRegister\Service\Task\TaskInboxService',
				'OCA\OpenRegister\Service\Portal\PortalPartyResolver',
				'OCA\OpenRegister\Service\TaskService',
				'OCA\OpenRegister\Service\FlowLinkService',
				'OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore',
				'OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects',
			],
			actual: $context->services
		);
	}//end testRegistersTheServicesInOrder()

	/**
	 * register() also aliases every Processes service contract to its class, after the flow wiring's own aliases.
	 *
	 * @return void
	 */
	public function testBindsEveryProcessesServiceContract(): void {
		$context = new RecordingRegistrationContext();
		FlowRegistrar::register(context: $context);

		$expected = [];
		foreach (ProcessesServiceBindings::BINDINGS as $contract => $class) {
			$expected[] = ['alias' => $contract, 'target' => $class];
		}

		$this->assertNotSame([], $expected);
		$this->assertSame($expected, array_slice($context->aliases, -count($expected)));
	}//end testBindsEveryProcessesServiceContract()

	/**
	 * Every factory builds an instance of the name it is registered under.
	 *
	 * The container hands each factory a test double for every service it asks for.
	 *
	 * @return void
	 */
	public function testEveryFactoryBuildsItsService(): void {
		$context = new RecordingRegistrationContext();
		FlowRegistrar::register(context: $context);

		$doubles = [];
		$container = $this->createMock(originalClassName: IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$doubles) {
				$doubles[$id] ??= $this->createMock(originalClassName: $id);
				return $doubles[$id];
			}
		);

		foreach ($context->factories as $name => $factory) {
			$this->assertInstanceOf(expected: $name, actual: $factory($container), message: $name);
		}
	}//end testEveryFactoryBuildsItsService()
}//end class
