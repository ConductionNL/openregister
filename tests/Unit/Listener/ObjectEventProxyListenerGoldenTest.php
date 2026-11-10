<?php

/**
 * Golden test for ObjectEventProxyListener routing, slug maps and listener resolution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Listener\ObjectEventProxyListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins which subscriptions the proxy invokes, in which order, how slug maps
 * are resolved and cached, and how unresolvable listeners are handled.
 */
class ObjectEventProxyListenerGoldenTest extends TestCase {

	/**
	 * Names of listeners invoked, in order.
	 *
	 * @var array<int, string>
	 */
	public array $invoked = [];

	/**
	 * Container ids requested, in order.
	 *
	 * @var array<int, string>
	 */
	private array $gets = [];

	/**
	 * Logged lines: [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logs = [];

	/**
	 * Cache writes: [key, value, ttl].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: int}>
	 */
	private array $cacheWrites = [];

	/**
	 * Slugs asked of the mappers: [kind, slugs].
	 *
	 * @var array<int, array{0: string, 1: array<int, string>}>
	 */
	private array $slugQueries = [];

	/**
	 * Reset declarations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ObjectEventSubscription::reset();
	}//end setUp()

	/**
	 * Reset declarations.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ObjectEventSubscription::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * A listener that records its name.
	 *
	 * @param string $name Name.
	 *
	 * @return IEventListener<Event>
	 */
	private function spy(string $name): IEventListener {
		$test = $this;
		return new class($test, $name) implements IEventListener {
			/**
			 * Constructor.
			 *
			 * @param ObjectEventProxyListenerGoldenTest $test Recorder.
			 * @param string                             $name Name.
			 */
			public function __construct(private ObjectEventProxyListenerGoldenTest $test, private string $name) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param Event $event Event.
			 *
			 * @return void
			 */
			public function handle(Event $event): void {
				$this->test->invoked[] = $this->name;
			}//end handle()
		};
	}//end spy()

	/**
	 * Build the proxy.
	 *
	 * @param array<string, mixed>              $services  Container services by id (Throwable = throw).
	 * @param string                            $filter    Kill switch value.
	 * @param string|null                       $cached    Cached map JSON for every key, or null.
	 * @param array<string, array<int, string>>|\Throwable $schemaMap What findIdsBySlugs answers for schemas.
	 * @param \Throwable|null                   $setError  Thrown by every cache write, or null.
	 *
	 * @return ObjectEventProxyListener
	 */
	private function proxy(
		array $services,
		string $filter = 'on',
		?string $cached = null,
		array|\Throwable $schemaMap = [],
		?\Throwable $setError = null
	): ObjectEventProxyListener {
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			function (array $slugs) use ($schemaMap) {
				$this->slugQueries[] = ['schema', $slugs];
				if ($schemaMap instanceof \Throwable) {
					throw $schemaMap;
				}

				return $schemaMap;
			}
		);
		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('findIdsBySlugs')->willReturnCallback(
			function (array $slugs) {
				$this->slugQueries[] = ['register', $slugs];
				return ['main' => ['7']];
			}
		);
		$services[SchemaMapperInterface::class] = $schemaMapper;
		$services[RegisterMapperInterface::class] = $registerMapper;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				$this->gets[] = $id;
				$service = ($services[$id] ?? new \RuntimeException('unknown ' . $id));
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($filter);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0) use ($setError): bool {
				$this->cacheWrites[] = [$key, $value, $ttl];
				if ($setError !== null) {
					throw $setError;
				}

				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocal')->with('openregister_event_interest')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level, $message];
				}
			);
		}

		return new ObjectEventProxyListener($container, $appConfig, $cacheFactory, $logger);
	}//end proxy()

	/**
	 * Declare a subscription on ObjectCreatedEvent.
	 *
	 * @param string                 $listener  Listener id.
	 * @param array<int,string>|null $registers Register tokens.
	 * @param array<int,string>|null $schemas   Schema tokens.
	 *
	 * @return void
	 */
	private function declare(string $listener, ?array $registers = null, ?array $schemas = null): void {
		ObjectEventSubscription::subscribe(
			dispatcher: $this->createMock(IEventDispatcher::class),
			event: ObjectCreatedEvent::class,
			listener: $listener,
			registers: $registers,
			schemas: $schemas
		);
	}//end declare()

	/**
	 * A create event for register 7, schema 9.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function event(): ObjectCreatedEvent {
		$object = new ObjectEntity();
		$object->setRegister('7');
		$object->setSchema('9');
		return new ObjectCreatedEvent($object);
	}//end event()

	/**
	 * Slug and id declarations match in declaration order; the map is built once and cached.
	 *
	 * @return void
	 */
	public function testSlugAndIdDeclarationsRouteInOrderAndCacheTheMap(): void {
		$this->declare(listener: 'a', schemas: ['Person']);
		$this->declare(listener: 'b', schemas: ['other']);
		$this->declare(listener: 'c', registers: ['main', '99'], schemas: ['9']);
		$this->declare(listener: 'd');
		$this->declare(listener: 'e', schemas: ['3']);
		$proxy = $this->proxy(
			services: ['a' => $this->spy(name: 'a'), 'b' => $this->spy(name: 'b'), 'c' => $this->spy(name: 'c'), 'd' => $this->spy(name: 'd'), 'e' => $this->spy(name: 'e')],
			schemaMap: ['person' => ['9'], 'other' => ['3']]
		);

		$proxy->handle($this->event());
		$proxy->handle($this->event());

		$this->assertSame(['a', 'c', 'd', 'a', 'c', 'd'], $this->invoked);
		$this->assertSame([['schema', ['Person', 'other']], ['register', ['main']]], $this->slugQueries);
		$this->assertSame(
			[
				['s:' . hash(algo: 'xxh128', data: 'Person,other'), json_encode(['person' => ['9'], 'other' => ['3']]), 60],
				['r:' . hash(algo: 'xxh128', data: 'main'), json_encode(['main' => ['7']]), 60],
			],
			$this->cacheWrites
		);
		$this->assertSame([], $this->logs);
	}//end testSlugAndIdDeclarationsRouteInOrderAndCacheTheMap()

	/**
	 * A cached map is used without asking the mapper.
	 *
	 * @return void
	 */
	public function testCachedMapSkipsTheMapper(): void {
		$this->declare(listener: 'a', schemas: ['person']);
		$proxy = $this->proxy(services: ['a' => $this->spy(name: 'a')], cached: json_encode(['person' => ['9']]));

		$proxy->handle($this->event());

		$this->assertSame(['a'], $this->invoked);
		$this->assertSame([], $this->slugQueries);
		$this->assertSame([], $this->cacheWrites);
	}//end testCachedMapSkipsTheMapper()

	/**
	 * A failing slug lookup is logged and the slug declaration does not match.
	 *
	 * @return void
	 */
	public function testSlugFailureLogsAndSkips(): void {
		$this->declare(listener: 'a', schemas: ['person']);
		$proxy = $this->proxy(services: ['a' => $this->spy(name: 'a')], schemaMap: new \RuntimeException('db down'));

		$proxy->handle($this->event());

		$this->assertSame([], $this->invoked);
		$this->assertSame([['warning', 'Filtered object-event subscription could not resolve slugs: db down']], $this->logs);
		$this->assertSame([], $this->cacheWrites);
	}//end testSlugFailureLogsAndSkips()

	/**
	 * A failing cache write is logged but the fetched map still routes.
	 *
	 * @return void
	 */
	public function testCacheWriteFailureKeepsTheFetchedMap(): void {
		$this->declare(listener: 'a', schemas: ['person']);
		$proxy = $this->proxy(
			services: ['a' => $this->spy(name: 'a')],
			schemaMap: ['person' => ['9']],
			setError: new \RuntimeException(message: 'cache down')
		);

		$proxy->handle($this->event());

		$this->assertSame(['a'], $this->invoked);
		$this->assertSame([['schema', ['person']]], $this->slugQueries);
		$this->assertSame([['warning', 'Filtered object-event subscription could not resolve slugs: cache down']], $this->logs);
	}//end testCacheWriteFailureKeepsTheFetchedMap()

	/**
	 * With the kill switch off every subscription runs.
	 *
	 * @return void
	 */
	public function testKillSwitchOffInvokesEverything(): void {
		$this->declare(listener: 'a', schemas: ['nothing']);
		$proxy = $this->proxy(services: ['a' => $this->spy(name: 'a')], filter: 'off');

		$proxy->handle($this->event());

		$this->assertSame(['a'], $this->invoked);
		$this->assertSame([], $this->slugQueries);
	}//end testKillSwitchOffInvokesEverything()

	/**
	 * Unresolvable and non-listener services are logged once and skipped.
	 *
	 * @return void
	 */
	public function testUnresolvableListenersAreLoggedOnceAndSkipped(): void {
		$this->declare(listener: 'broken');
		$this->declare(listener: 'plain');
		$this->declare(listener: 'a');
		$proxy = $this->proxy(services: ['broken' => new \RuntimeException('boom'), 'plain' => new \stdClass(), 'a' => $this->spy(name: 'a')]);

		$proxy->handle($this->event());
		$proxy->handle($this->event());

		$this->assertSame(['a', 'a'], $this->invoked);
		$this->assertSame(['broken', 'plain', 'a'], $this->gets);
		$this->assertSame(
			[
				['error', 'Could not load filtered event listener service broken: boom'],
				['error', 'Filtered event listener plain does not implement IEventListener'],
			],
			$this->logs
		);
	}//end testUnresolvableListenersAreLoggedOnceAndSkipped()

	/**
	 * A throwing listener aborts the remaining ones.
	 *
	 * @return void
	 */
	public function testThrowingListenerPropagates(): void {
		$thrower = $this->createMock(IEventListener::class);
		$thrower->method('handle')->willThrowException(new \LogicException('stop'));
		$this->declare(listener: 't');
		$this->declare(listener: 'a');
		$proxy = $this->proxy(services: ['t' => $thrower, 'a' => $this->spy(name: 'a')]);

		try {
			$proxy->handle($this->event());
			$this->fail('expected the listener exception');
		} catch (\LogicException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertSame([], $this->invoked);
	}//end testThrowingListenerPropagates()

	/**
	 * An event without subscriptions touches nothing.
	 *
	 * @return void
	 */
	public function testEventWithoutSubscriptionsDoesNothing(): void {
		$proxy = $this->proxy(services: []);

		$proxy->handle($this->event());

		$this->assertSame([], $this->gets);
	}//end testEventWithoutSubscriptionsDoesNothing()
}//end class
