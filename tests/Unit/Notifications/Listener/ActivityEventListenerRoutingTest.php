<?php

/**
 * Characterisation test: ActivityEventListener routes each event to exactly one publisher.
 *
 * Pins, for each of the nine entity events, which ActivityService method is
 * called, with which entity, and that no other publisher is called.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Notifications\Listener\ActivityEventListener;
use OCA\OpenRegister\Notifications\Service\ActivityService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ActivityEventListenerRoutingTest extends TestCase {

	public static function singleEntityEvents(): array {
		return [
			'object created' => [ObjectCreatedEvent::class, 'getObject', ObjectEntity::class, 'publishObjectCreated'],
			'object deleted' => [ObjectDeletedEvent::class, 'getObject', ObjectEntity::class, 'publishObjectDeleted'],
			'register created' => [RegisterCreatedEvent::class, 'getRegister', Register::class, 'publishRegisterCreated'],
			'register updated' => [RegisterUpdatedEvent::class, 'getNewRegister', Register::class, 'publishRegisterUpdated'],
			'register deleted' => [RegisterDeletedEvent::class, 'getRegister', Register::class, 'publishRegisterDeleted'],
			'schema created' => [SchemaCreatedEvent::class, 'getSchema', Schema::class, 'publishSchemaCreated'],
			'schema updated' => [SchemaUpdatedEvent::class, 'getNewSchema', Schema::class, 'publishSchemaUpdated'],
			'schema deleted' => [SchemaDeletedEvent::class, 'getSchema', Schema::class, 'publishSchemaDeleted'],
		];
	}

	#[DataProvider('singleEntityEvents')]
	public function testAnEventPublishesExactlyItsOwnActivity(string $eventClass, string $getter, string $entityClass, string $publish): void {
		$entity = $this->createMock($entityClass);
		$event = $this->createMock($eventClass);
		$event->method($getter)->willReturn($entity);

		$service = $this->createMock(ActivityService::class);
		$service->expects($this->once())->method($publish)->with($entity);
		$service->expects($this->never())->method($this->logicalNot($this->equalTo($publish)));

		(new ActivityEventListener($service))->handle($event);
	}

	public function testAnObjectUpdatePublishesTheNewAndOldObject(): void {
		$new = $this->createMock(ObjectEntity::class);
		$old = $this->createMock(ObjectEntity::class);
		$event = $this->createMock(ObjectUpdatedEvent::class);
		$event->method('getNewObject')->willReturn($new);
		$event->method('getOldObject')->willReturn($old);

		$service = $this->createMock(ActivityService::class);
		$service->expects($this->once())->method('publishObjectUpdated')->with($new, $old);
		$service->expects($this->never())->method($this->logicalNot($this->equalTo('publishObjectUpdated')));

		(new ActivityEventListener($service))->handle($event);
	}

	public function testAnUnknownEventPublishesNothing(): void {
		$service = $this->createMock(ActivityService::class);
		$service->expects($this->never())->method($this->anything());

		(new ActivityEventListener($service))->handle(new Event());
	}
}
