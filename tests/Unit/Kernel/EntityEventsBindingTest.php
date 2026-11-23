<?php

/**
 * EntityEventsInterface is bound to the Core publisher, and the mappers name no event class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\ServiceRegistrar;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The contract the mappers announce entity events through (ADR-013 Rule 1 and Rule 4).
 */
final class EntityEventsBindingTest extends TestCase {

	/**
	 * The lib/Db files that still name a lib/Event class. This list only shrinks.
	 *
	 * SchemaMapper is held by an open pull request; RegisterMapper is named by the self-check of
	 * ObjectCrudFiresOnlyObjectEventsTest; MagicMapper's object events are another lane's.
	 *
	 * @var array<int, string>
	 */
	private const DB_NAMING_EVENTS = [
		'MagicMapper.php',
		'RegisterMapper.php',
		'SchemaMapper.php',
	];

	public function testTheContractResolvesToTheCorePublisher(): void {
		$context = new RecordingRegistrationContext();
		ServiceRegistrar::register(context: $context);

		$this->assertContains(['alias' => EntityEventsInterface::class, 'target' => EntityEventPublisher::class], $context->aliases);
		$this->assertTrue(is_subclass_of(EntityEventPublisher::class, EntityEventsInterface::class));
	}

	public function testSchemaEventsCarryTheSchemasTheyWereGiven(): void {
		$events = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (Event $event) use (&$events): void {
			$events[] = $event;
		});
		$publisher = new EntityEventPublisher(dispatcher: $dispatcher);
		$new = new Schema();
		$old = new Schema();

		$publisher->schemaCreated($new);
		$publisher->schemaUpdated($new, $old);
		$publisher->schemaDeleted($old);

		$this->assertCount(3, $events);
		$this->assertInstanceOf(SchemaCreatedEvent::class, $events[0]);
		$this->assertSame($new, $events[0]->getSchema());
		$this->assertInstanceOf(SchemaUpdatedEvent::class, $events[1]);
		$this->assertSame($new, $events[1]->getNewSchema());
		$this->assertSame($old, $events[1]->getOldSchema());
		$this->assertInstanceOf(SchemaDeletedEvent::class, $events[2]);
		$this->assertSame($old, $events[2]->getSchema());
	}

	public function testAnEntityOfTheWrongClassIsRefusedBeforeAnythingIsDispatched(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(InvalidArgumentException::class);
		(new EntityEventPublisher(dispatcher: $dispatcher))->sourceCreated(new Application());
	}

	public function testApplicationConfigurationAndSourceEventsCarryTheEntitiesTheyWereGiven(): void {
		$events = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (Event $event) use (&$events): void {
			$events[] = $event;
		});
		$publisher = new EntityEventPublisher(dispatcher: $dispatcher);
		[$newApplication, $oldApplication] = [new Application(), new Application()];
		[$newConfiguration, $oldConfiguration] = [new Configuration(), new Configuration()];
		[$newSource, $oldSource] = [new Source(), new Source()];

		$publisher->applicationCreated($newApplication);
		$publisher->applicationUpdated($newApplication, $oldApplication);
		$publisher->applicationDeleted($oldApplication);
		$publisher->configurationCreated($newConfiguration);
		$publisher->configurationUpdated($newConfiguration, $oldConfiguration);
		$publisher->configurationDeleted($oldConfiguration);
		$publisher->sourceCreated($newSource);
		$publisher->sourceUpdated($newSource, $oldSource);
		$publisher->sourceDeleted($oldSource);

		$this->assertSame(
			[
				ApplicationCreatedEvent::class,
				ApplicationUpdatedEvent::class,
				ApplicationDeletedEvent::class,
				ConfigurationCreatedEvent::class,
				ConfigurationUpdatedEvent::class,
				ConfigurationDeletedEvent::class,
				SourceCreatedEvent::class,
				SourceUpdatedEvent::class,
				SourceDeletedEvent::class,
			],
			array_map(static fn (Event $event): string => get_class($event), $events)
		);
		$this->assertSame($newApplication, $events[0]->getApplication());
		$this->assertSame($newApplication, $events[1]->getNewApplication());
		$this->assertSame($oldApplication, $events[1]->getOldApplication());
		$this->assertSame($oldApplication, $events[2]->getApplication());
		$this->assertSame($newConfiguration, $events[3]->getConfiguration());
		$this->assertSame($newConfiguration, $events[4]->getNewConfiguration());
		$this->assertSame($oldConfiguration, $events[4]->getOldConfiguration());
		$this->assertSame($oldConfiguration, $events[5]->getConfiguration());
		$this->assertSame($newSource, $events[6]->getSource());
		$this->assertSame($newSource, $events[7]->getNewSource());
		$this->assertSame($oldSource, $events[7]->getOldSource());
		$this->assertSame($oldSource, $events[8]->getSource());
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, object>}>
	 */
	public static function wrongEntities(): array {
		return [
			'application created from a source'        => ['applicationCreated', [new Source()]],
			'application updated from a configuration' => ['applicationUpdated', [new Application(), new Configuration()]],
			'application deleted from a schema'        => ['applicationDeleted', [new Schema()]],
			'configuration created from an application' => ['configurationCreated', [new Application()]],
			'configuration updated from a source'      => ['configurationUpdated', [new Source(), new Configuration()]],
			'configuration deleted from a source'      => ['configurationDeleted', [new Source()]],
			'source updated from an application'       => ['sourceUpdated', [new Source(), new Application()]],
			'source deleted from a configuration'      => ['sourceDeleted', [new Configuration()]],
		];
	}

	/**
	 * @param string             $method   The publisher method.
	 * @param array<int, object> $entities The entities passed to it.
	 *
	 * @return void
	 *
	 * @dataProvider wrongEntities
	 */
	public function testEveryApplicationConfigurationAndSourceAnnouncementRefusesAnotherEntity(string $method, array $entities): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(InvalidArgumentException::class);
		(new EntityEventPublisher(dispatcher: $dispatcher))->{$method}(...$entities);
	}

	public function testTheRefusalNamesTheContractAndTheClassItGot(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Expected ' . SourceInterface::class . ', got ' . Application::class . '.');
		(new EntityEventPublisher(dispatcher: $dispatcher))->sourceCreated(new Application());
	}

	public function testATerminalTaskIsAnnouncedWithTheRowItWasGiven(): void {
		$events     = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (Event $event) use (&$events): void {
			$events[] = $event;
		});
		$task = new Task();

		(new EntityEventPublisher(dispatcher: $dispatcher))->taskTerminal(task: $task, committed: false);

		$this->assertCount(1, $events);
		$this->assertInstanceOf(TaskTerminalEvent::class, $events[0]);
		$this->assertSame($task, $events[0]->getTask());
		$this->assertFalse($events[0]->isCommitted());
	}

	public function testATerminalTaskAnnouncementRefusesAnEntityThatIsNoTask(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Expected ' . TaskInterface::class . ', got ' . Application::class . '.');
		(new EntityEventPublisher(dispatcher: $dispatcher))->taskTerminal(task: new Application(), committed: true);
	}

	public function testOnlyTheListedMappersNameAnEventClass(): void {
		$naming = [];
		foreach ((array) glob(__DIR__ . '/../../../lib/Db/*.php') as $path) {
			if (str_contains((string) file_get_contents((string) $path), 'OCA\\OpenRegister\\Event\\') === true) {
				$naming[] = basename((string) $path);
			}
		}

		sort($naming);
		$this->assertSame(
			self::DB_NAMING_EVENTS,
			$naming,
			'announce through EntityEventsInterface; remove a file from the list once it no longer names an event'
		);
	}
}//end class
