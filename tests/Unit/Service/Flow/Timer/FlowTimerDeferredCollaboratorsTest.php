<?php

/**
 * The timer store and the timer subjects take their object-reaching collaborators deferred.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSubjects;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The object service reaches both through its save and move paths; building their
 * object-reaching collaborators eagerly is a constructor cycle that stops the app
 * from enabling, so both accept a Closure and FlowWiring hands them one.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */
class FlowTimerDeferredCollaboratorsTest extends TestCase {

	private function appManager(): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(realpath(__DIR__ . '/../../../../..'));
		return $appManager;
	}

	public function testADeferredObjectReaderIsBuiltOnlyWhenTheStoreReads(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn(['slug' => 'custom', 'hoursPerWorkingDay' => 6]);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([$entity]);

		$built = 0;
		$store = new FlowTimerDefinitionStore(
			objects: static function () use ($objects, &$built): ObjectOperationsInterface {
				$built++;
				return $objects;
			},
			appManager: $this->appManager(),
			logger: new NullLogger()
		);
		$this->assertSame(0, $built, 'constructing the store must not build the object reader');

		$calendars = $store->calendars();

		$this->assertGreaterThanOrEqual(1, $built);
		$this->assertSame(6, $calendars['custom']['hoursPerWorkingDay']);
	}

	public function testADeferredTaskServiceIsBuiltOnlyWhenAsked(): void {
		$taskService = $this->createMock(TaskServiceInterface::class);
		$built = 0;
		$subjects = new FlowTimerSubjects(
			tasks: $this->createMock(TaskMapper::class),
			taskService: static function () use ($taskService, &$built): TaskServiceInterface {
				$built++;
				return $taskService;
			},
			dispatcher: $this->createMock(IEventDispatcher::class)
		);
		$this->assertSame(0, $built);

		$this->assertSame($taskService, $subjects->taskService());
		$this->assertSame(1, $built);
	}

	public function testAGivenTaskServiceIsReturnedAsIs(): void {
		$taskService = $this->createMock(TaskServiceInterface::class);
		$subjects = new FlowTimerSubjects(tasks: $this->createMock(TaskMapper::class), taskService: $taskService, dispatcher: $this->createMock(IEventDispatcher::class));

		$this->assertSame($taskService, $subjects->taskService());
	}

	public function testTheWiringBuildsBothWithoutAskingForTheObjectOrTaskService(): void {
		$taskService = $this->createMock(TaskServiceInterface::class);
		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $taskService) {
				$asked[] = $id;
				return match ($id) {
					IAppManager::class => $this->appManager(),
					LoggerInterface::class => new NullLogger(),
					TaskMapper::class => $this->createMock(TaskMapper::class),
					IEventDispatcher::class => $this->createMock(IEventDispatcher::class),
					TaskServiceInterface::class => $taskService,
					default => throw new \RuntimeException('unexpected ' . $id),
				};
			}
		);

		$store = (new ReflectionMethod(FlowWiring::class, 'buildFlowTimerDefinitionStore'))->invoke(null, $container);
		$subjects = (new ReflectionMethod(FlowWiring::class, 'buildFlowTimerSubjects'))->invoke(null, $container);

		$this->assertInstanceOf(FlowTimerDefinitionStore::class, $store);
		$this->assertInstanceOf(FlowTimerSubjects::class, $subjects);
		$this->assertNotContains(ObjectOperationsInterface::class, $asked);
		$this->assertNotContains(TaskServiceInterface::class, $asked);

		$this->assertSame($taskService, $subjects->taskService());
		$this->assertContains(TaskServiceInterface::class, $asked);
	}
}
