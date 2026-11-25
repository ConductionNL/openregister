<?php

/**
 * SubscriptionService's APCu event buffer: what pushEvent() writes and what
 * eventsMatching() reads back.
 *
 * APCu is absent in the unit sandbox, so function_exists(), apcu_enabled(),
 * apcu_fetch() and apcu_store() are shadowed in SubscriptionService's namespace
 * by doubles below backed by an in-memory store. They pass through to the real
 * functions unless a test switches the double on, so the sibling tests that
 * rely on APCu being absent are unaffected.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\GraphQL {

	use OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL\SubscriptionApcu;

	/**
	 * Shadows function_exists() for SubscriptionService; passes through unless the APCu double is installed.
	 *
	 * @param string $function The function.
	 *
	 * @return bool
	 */
	function function_exists(string $function): bool {
		if ($function === 'apcu_enabled' && SubscriptionApcu::$installed === true) {
			return true;
		}

		return \function_exists($function);
	}//end function_exists()

	/**
	 * Shadows apcu_enabled() for SubscriptionService.
	 *
	 * @return bool
	 */
	function apcu_enabled(): bool {
		if (SubscriptionApcu::$installed === true) {
			return SubscriptionApcu::$enabled;
		}

		return \apcu_enabled();
	}//end apcu_enabled()

	/**
	 * Shadows apcu_fetch() for SubscriptionService.
	 *
	 * @param string $key The key.
	 *
	 * @return mixed
	 */
	function apcu_fetch(string $key): mixed {
		if (SubscriptionApcu::$installed === true) {
			return (SubscriptionApcu::$store[$key] ?? false);
		}

		return \apcu_fetch($key);
	}//end apcu_fetch()

	/**
	 * Shadows apcu_store() for SubscriptionService.
	 *
	 * @param string  $key   The key.
	 * @param mixed   $value The value.
	 * @param integer $ttl   The time to live.
	 *
	 * @return bool
	 */
	function apcu_store(string $key, mixed $value, int $ttl = 0): bool {
		if (SubscriptionApcu::$installed === true) {
			SubscriptionApcu::$store[$key] = $value;
			SubscriptionApcu::$ttls[$key]  = $ttl;
			return true;
		}

		return \apcu_store($key, $value, $ttl);
	}//end apcu_store()
}

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL {

	// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

	use OCA\OpenRegister\Db\ObjectEntity;
	use OCA\OpenRegister\Db\Schema;
	use OCA\OpenRegister\Db\SchemaMapperInterface;
	use OCA\OpenRegister\Notifications\Service\GraphQL\EventStreamFilter;
	use OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService;
	use OCA\OpenRegister\Service\Object\PermissionAsk;
	use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	/**
	 * What APCu offers SubscriptionService in the current test.
	 */
	final class SubscriptionApcu {

		/**
		 * Whether the double replaces APCu (function_exists('apcu_enabled') answers true).
		 *
		 * @var bool
		 */
		public static bool $installed = false;

		/**
		 * What apcu_enabled() answers while installed.
		 *
		 * @var bool
		 */
		public static bool $enabled = true;

		/**
		 * The stored values, by key.
		 *
		 * @var array<string, mixed>
		 */
		public static array $store = [];

		/**
		 * The TTL each key was stored with.
		 *
		 * @var array<string, int>
		 */
		public static array $ttls = [];

		/**
		 * Back to pass-through.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$installed = false;
			self::$enabled   = true;
			self::$store     = [];
			self::$ttls      = [];
		}//end reset()
	}//end class

	/**
	 * Pins how events reach and leave the APCu buffer.
	 *
	 * @covers \OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService
	 * @uses   \OCA\OpenRegister\Notifications\Service\GraphQL\EventStreamFilter
	 * @uses   \OCA\OpenRegister\Db\ObjectEntity
	 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
	 * @uses   \OCA\OpenRegister\Db\Schema
	 */
	final class SubscriptionServiceCoverageTest extends TestCase {

		private const KEY = 'openregister_graphql_events';

		/**
		 * Schema mapper mock.
		 *
		 * @var SchemaMapperInterface&MockObject
		 */
		private SchemaMapperInterface&MockObject $schemas;

		/**
		 * Permission check mock.
		 *
		 * @var PermissionCheckInterface&MockObject
		 */
		private PermissionCheckInterface&MockObject $permissions;

		/**
		 * Service under test.
		 *
		 * @var SubscriptionService
		 */
		private SubscriptionService $service;

		protected function setUp(): void {
			parent::setUp();
			SubscriptionApcu::reset();
			SubscriptionApcu::$installed = true;

			$this->schemas     = $this->createMock(SchemaMapperInterface::class);
			$this->permissions = $this->createMock(PermissionCheckInterface::class);
			$this->service     = new SubscriptionService($this->schemas, $this->permissions);
		}//end setUp()

		protected function tearDown(): void {
			SubscriptionApcu::reset();
			parent::tearDown();
		}//end tearDown()

		/**
		 * An object as the listener hands it over.
		 *
		 * @return ObjectEntity
		 */
		private function object(): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid('obj-1');
			$object->setRegister('10');
			$object->setSchema('3');
			$object->setOwner('alice');
			$object->setObject(['title' => 'Melding']);

			return $object;
		}//end object()

		/**
		 * A buffered event.
		 *
		 * @param string   $id     The event id.
		 * @param int|null $schema The schema, or null for none.
		 * @param int|null $age    Seconds since the event, defaults to now.
		 *
		 * @return array<string, mixed>
		 */
		private function event(string $id, ?int $schema = null, int $age = 0): array {
			$object = ['uuid' => $id.'-obj', 'register' => 10];
			if ($schema !== null) {
				$object['schema'] = $schema;
			}

			return ['id' => $id, 'action' => 'create', 'timestamp' => (time() - $age), 'object' => $object];
		}//end event()

		public function testWithoutApcuNothingIsBuffered(): void {
			SubscriptionApcu::$installed = false;

			$this->service->pushEvent('create', $this->object());

			$this->assertSame([], SubscriptionApcu::$store);
		}//end testWithoutApcuNothingIsBuffered()

		public function testWithApcuDisabledNothingIsBuffered(): void {
			SubscriptionApcu::$enabled = false;

			$this->service->pushEvent('create', $this->object());

			$this->assertSame([], SubscriptionApcu::$store);
		}//end testWithApcuDisabledNothingIsBuffered()

		public function testACreateIsBufferedWithTheObjectAndItsData(): void {
			$before = time();
			$this->service->pushEvent('create', $this->object());

			$buffer = SubscriptionApcu::$store[self::KEY];
			$this->assertCount(1, $buffer);
			$event = $buffer[0];
			$this->assertStringStartsWith('gql_', $event['id']);
			$this->assertSame('create', $event['action']);
			$this->assertGreaterThanOrEqual($before, $event['timestamp']);
			$this->assertSame(
				[
					'uuid' => 'obj-1',
					'register' => '10',
					'schema' => '3',
					'owner' => 'alice',
					'data' => ['id' => 'obj-1', 'title' => 'Melding'],
				],
				$event['object']
			);
			$this->assertSame(300, SubscriptionApcu::$ttls[self::KEY]);
		}//end testACreateIsBufferedWithTheObjectAndItsData()

		public function testADeleteIsBufferedWithoutTheObjectData(): void {
			$this->service->pushEvent('delete', $this->object());

			$event = SubscriptionApcu::$store[self::KEY][0];
			$this->assertSame('delete', $event['action']);
			$this->assertArrayNotHasKey('data', $event['object']);
			$this->assertSame('obj-1', $event['object']['uuid']);
		}//end testADeleteIsBufferedWithoutTheObjectData()

		public function testAPushAppendsAndDropsEventsOlderThanFiveMinutes(): void {
			SubscriptionApcu::$store[self::KEY] = [
				$this->event('stale', null, 301),
				$this->event('fresh', null, 10),
			];

			$this->service->pushEvent('update', $this->object());

			$buffer = SubscriptionApcu::$store[self::KEY];
			$this->assertCount(2, $buffer);
			$this->assertSame('fresh', $buffer[0]['id']);
			$this->assertSame('update', $buffer[1]['action']);
		}//end testAPushAppendsAndDropsEventsOlderThanFiveMinutes()

		public function testTheBufferIsCappedAtAThousandEvents(): void {
			$events = [];
			for ($i = 0; $i < 1000; $i++) {
				$events[] = $this->event('e'.$i);
			}

			SubscriptionApcu::$store[self::KEY] = $events;

			$this->service->pushEvent('create', $this->object());

			$buffer = SubscriptionApcu::$store[self::KEY];
			$this->assertCount(1000, $buffer);
			$this->assertSame('e1', $buffer[0]['id']);
			$this->assertSame('obj-1', $buffer[999]['object']['uuid']);
		}//end testTheBufferIsCappedAtAThousandEvents()

		public function testAnEmptyBufferMatchesNothing(): void {
			$this->schemas->expects($this->never())->method('find');

			$this->assertSame([], $this->service->eventsMatching(new EventStreamFilter()));

			SubscriptionApcu::$store[self::KEY] = [];
			$this->assertSame([], $this->service->eventsMatching(new EventStreamFilter()));
		}//end testAnEmptyBufferMatchesNothing()

		public function testWithApcuDisabledNothingMatches(): void {
			SubscriptionApcu::$enabled = false;
			SubscriptionApcu::$store[self::KEY] = [$this->event('e1')];

			$this->assertSame([], $this->service->eventsMatching(new EventStreamFilter()));
		}//end testWithApcuDisabledNothingMatches()

		public function testOnlyEventsAfterTheLastIdOnReadableSchemasAreReturned(): void {
			$readable   = new Schema();
			$unreadable = new Schema();
			SubscriptionApcu::$store[self::KEY] = [
				$this->event('e1', 1),
				$this->event('e2', 1),
				$this->event('e3', 2),
				$this->event('e4'),
				$this->event('e5', 3),
			];

			$this->schemas->expects($this->exactly(3))
				->method('find')
				->willReturnCallback(
					static function (int $id) use ($readable, $unreadable): Schema {
						return match ($id) {
							1 => $readable,
							2 => $unreadable,
							default => throw new RuntimeException('schema gone'),
						};
					}
				);
			$this->permissions->expects($this->exactly(2))
				->method('hasPermission')
				->willReturnCallback(static fn (PermissionAsk $ask): bool => $ask->schema === $readable && $ask->action === 'read');

			$events = $this->service->eventsMatching(new EventStreamFilter('e1'));

			$this->assertSame(['e2', 'e4'], array_column($events, 'id'));
		}//end testOnlyEventsAfterTheLastIdOnReadableSchemasAreReturned()
	}//end class
}
