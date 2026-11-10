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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Service\ContractBindings;
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
		ContractBindings::register(context: $context);

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
