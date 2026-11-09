<?php

/**
 * Which container services the Nextcloud Flow registration hands to the engine.
 *
 * Nextcloud Flow stores an operation's class name with every rule and asks the
 * container for that id when the rule fires, so the id resolved here must
 * stay the operation's class name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\FlowEngineRegistrationListener;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\FlowEngineRegistrationListener
 */
final class FlowEngineRegistrationListenerTest extends TestCase {

	/**
	 * The operation is resolved under its class name and registered.
	 *
	 * Runs in its own process: the listener queues a script through the
	 * static \OCP\Util::addScript(), and that state would leak into later
	 * tests that queue scripts.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testTheOperationIsResolvedByItsClassName(): void {
		$operation = $this->createMock(IOperation::class);
		$asked = [];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$asked, $operation) {
				$asked[] = $id;

				return $operation;
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->expects($this->once())->method('registerOperation')->with($operation);

		(new FlowEngineRegistrationListener($container))->handle(new RegisterOperationsEvent($manager));

		$this->assertSame(['OCA\OpenRegister\WorkflowEngine\RunFlowOperation'], $asked);
	}//end testTheOperationIsResolvedByItsClassName()

	/**
	 * Any other event registers nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		(new FlowEngineRegistrationListener($container))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
