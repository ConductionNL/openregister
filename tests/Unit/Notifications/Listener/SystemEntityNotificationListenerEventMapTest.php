<?php

/**
 * Characterisation test: every system-entity event SystemEntityNotificationListener bridges.
 *
 * Pins, for each of the ten created/updated events, the trigger, the system
 * schema slug, the entity handed to the dispatcher and the old/new data in the
 * context, plus the "nothing moved" skip for every updated event.
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

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Notifications\Listener\SystemEntityNotificationListener;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\SystemSchemaRules;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SystemEntityNotificationListenerEventMapTest extends TestCase {

	/**
	 * Two entities of one kind whose content differs, or not.
	 *
	 * @return array{0: Entity, 1: Entity}
	 */
	private static function pair(string $kind, bool $moved): array {
		$make = static function (string $title) use ($kind): Entity {
			$entity = new $kind();
			$entity->setUuid('uuid-1');
			if ($entity instanceof Agent) {
				$entity->setName($title);
			} else {
				$entity->setTitle($title);
			}

			return $entity;
		};

		return [$make('New'), $make(($moved === true) ? 'Old' : 'New')];
	}

	public static function createdEvents(): array {
		return [
			'register' => [RegisterCreatedEvent::class, Register::class, SystemSchemaRules::SLUG_REGISTER],
			'schema' => [SchemaCreatedEvent::class, Schema::class, SystemSchemaRules::SLUG_SCHEMA],
			'configuration' => [ConfigurationCreatedEvent::class, Configuration::class, SystemSchemaRules::SLUG_CONFIGURATION],
			'source' => [SourceCreatedEvent::class, Source::class, SystemSchemaRules::SLUG_SOURCE],
			'agent' => [AgentCreatedEvent::class, Agent::class, SystemSchemaRules::SLUG_AGENT],
		];
	}

	public static function updatedEvents(): array {
		return [
			'register' => [RegisterUpdatedEvent::class, Register::class, SystemSchemaRules::SLUG_REGISTER],
			'schema' => [SchemaUpdatedEvent::class, Schema::class, SystemSchemaRules::SLUG_SCHEMA],
			'configuration' => [ConfigurationUpdatedEvent::class, Configuration::class, SystemSchemaRules::SLUG_CONFIGURATION],
			'source' => [SourceUpdatedEvent::class, Source::class, SystemSchemaRules::SLUG_SOURCE],
			'agent' => [AgentUpdatedEvent::class, Agent::class, SystemSchemaRules::SLUG_AGENT],
		];
	}

	/**
	 * Handle an event and return the dispatcher calls it made.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function dispatched(Event $event): array {
		$calls = [];
		$dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$dispatcher->method('dispatchWithSchema')->willReturnCallback(
			static function (...$args) use (&$calls): void {
				$calls[] = $args;
			}
		);

		(new SystemEntityNotificationListener(dispatcher: $dispatcher, rules: new SystemSchemaRules()))->handle($event);

		return $calls;
	}

	#[DataProvider('createdEvents')]
	public function testACreatedEventDispatchesCreatedWithAnEmptyContext(string $eventClass, string $kind, string $slug): void {
		[$entity] = self::pair(kind: $kind, moved: false);

		$calls = $this->dispatched(new $eventClass($entity));

		$this->assertCount(1, $calls);
		[$object, $trigger, $context, $schema] = $calls[0];
		$this->assertSame('created', $trigger);
		$this->assertSame([], $context);
		$this->assertSame($slug, $schema->getSlug());
		$this->assertSame('uuid-1', $object->getUuid());
	}

	#[DataProvider('updatedEvents')]
	public function testAnUpdatedEventDispatchesUpdatedWithOldAndNewData(string $eventClass, string $kind, string $slug): void {
		[$new, $old] = self::pair(kind: $kind, moved: true);

		$calls = $this->dispatched(new $eventClass($new, $old));

		$this->assertCount(1, $calls);
		[$object, $trigger, $context, $schema] = $calls[0];
		$this->assertSame('updated', $trigger);
		$this->assertSame($slug, $schema->getSlug());
		$this->assertSame(['_newData', '_oldData'], array_keys($context));
		$this->assertEquals($new->jsonSerialize(), $context['_newData']);
		$this->assertEquals($old->jsonSerialize(), $context['_oldData']);
		$this->assertSame('uuid-1', $object->getUuid());
	}

	#[DataProvider('updatedEvents')]
	public function testAnUpdatedEventWhereNothingMovedDispatchesNothing(string $eventClass, string $kind, string $slug): void {
		[$new, $old] = self::pair(kind: $kind, moved: false);

		$this->assertSame([], $this->dispatched(new $eventClass($new, $old)));
	}

	public function testAnUnknownEventDispatchesNothing(): void {
		$this->assertSame([], $this->dispatched(new Event()));
	}
}
