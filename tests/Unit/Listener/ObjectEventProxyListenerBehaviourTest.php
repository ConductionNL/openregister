<?php

/**
 * ObjectEventProxyListenerBehaviourTest — slug resolution, caching, loading failures and tracing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ObjectEventProxyListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Counts how often it was handed an event.
 */
final class ProxyBehaviourSpyListener implements IEventListener {

	/**
	 * @var Event[]
	 */
	public array $seen = [];

	public function handle(Event $event): void {
		$this->seen[] = $event;
	}
}

/**
 * An event that carries no object at all.
 */
final class ProxyBehaviourObjectlessEvent extends Event {
}

/**
 * @covers \OCA\OpenRegister\Listener\ObjectEventProxyListener
 * @uses   \OCA\OpenRegister\Event\ObjectEventSubscription
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Event\WriteVetoState
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 */
class ObjectEventProxyListenerBehaviourTest extends TestCase {

	private ProxyBehaviourSpyListener $spy;

	private ContainerInterface&MockObject $container;

	private ICache&MockObject $cache;

	private LoggerInterface&MockObject $logger;

	private string $killSwitch = 'on';

	/**
	 * Services the container returns, by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	protected function setUp(): void {
		ObjectEventSubscription::reset();
		$this->spy = new ProxyBehaviourSpyListener();
		$this->services = [ProxyBehaviourSpyListener::class => $this->spy];
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if (array_key_exists($id, $this->services) === false) {
					throw new RuntimeException('no service ' . $id);
				}
				return $this->services[$id];
			}
		);
		$this->cache = $this->createMock(ICache::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		ObjectEventSubscription::reset();
	}

	private function proxy(): ObjectEventProxyListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($key === 'objectEventFilter' ? $this->killSwitch : $default)
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->with('openregister_event_interest')->willReturn($this->cache);

		$proxy = new ObjectEventProxyListener($this->container, $appConfig, $factory, $this->logger);
		// Tracing is decided by a marker file; pin it off so the host cannot change the outcome.
		(new ReflectionProperty($proxy, 'traceOn'))->setValue($proxy, false);
		return $proxy;
	}

	private function subscribe(string $event, ?array $registers = null, ?array $schemas = null, string $listener = ProxyBehaviourSpyListener::class): void {
		ObjectEventSubscription::subscribe($this->createMock(IEventDispatcher::class), $event, $listener, $registers, $schemas);
	}

	private function created(?string $register, ?string $schema): ObjectCreatedEvent {
		$object = new ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);
		return new ObjectCreatedEvent($object);
	}

	public function testAnEventWithoutSubscriptionsResolvesNothing(): void {
		$this->container->expects($this->never())->method('get');

		$this->proxy()->handle($this->created('1', '2'));

		$this->assertSame([], $this->spy->seen);
	}

	public function testASlugIsMatchedThroughTheCachedMap(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['Publications']);
		$this->cache->expects($this->once())->method('get')
			->with('r:' . hash(algo: 'xxh128', data: 'Publications'))
			->willReturn(json_encode(['publications' => ['5']]));
		$this->cache->expects($this->never())->method('set');

		$proxy = $this->proxy();
		$proxy->handle($this->created('5', '9'));
		$proxy->handle($this->created('6', '9'));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testACacheMissResolvesThroughTheMapperAndStoresTheMap(): void {
		$this->subscribe(ObjectCreatedEvent::class, null, ['person']);
		$this->cache->method('get')->willReturn(null);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('findIdsBySlugs')->with(['person'])->willReturn(['person' => ['12']]);
		$this->services[SchemaMapperInterface::class] = $mapper;
		$this->cache->expects($this->once())->method('set')
			->with('s:' . hash(algo: 'xxh128', data: 'person'), '{"person":["12"]}', 60);

		$this->proxy()->handle($this->created('1', '12'));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testACorruptCacheEntryFallsThroughToTheMapper(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['reg']);
		$this->cache->method('get')->willReturn('{not json');
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->expects($this->once())->method('findIdsBySlugs')->with(['reg'])->willReturn(['reg' => ['3']]);
		$this->services[RegisterMapperInterface::class] = $mapper;

		$this->proxy()->handle($this->created('3', '1'));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAMapperFailureSkipsTheFilteredListenerWithAWarning(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['reg']);
		$this->cache->method('get')->willReturn(null);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringStartsWith('Filtered object-event subscription could not resolve slugs: no service'), $this->anything());

		$this->proxy()->handle($this->created('3', '1'));

		$this->assertSame([], $this->spy->seen);
	}

	public function testAMixedIdAndSlugDeclarationMatchesOnTheId(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['reg', '7']);
		$this->cache->expects($this->never())->method('get');

		$this->proxy()->handle($this->created('7', '1'));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAnObjectWithoutRegisterFailsARegisterFilter(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['7']);

		$this->proxy()->handle($this->created(null, '1'));

		$this->assertSame([], $this->spy->seen);
	}

	public function testTheKillSwitchDisablesFiltering(): void {
		$this->killSwitch = 'off';
		$this->subscribe(ObjectCreatedEvent::class, ['7']);

		$this->proxy()->handle($this->created('8', '1'));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAnUpdateIsFilteredOnTheNewObject(): void {
		$this->subscribe(ObjectUpdatedEvent::class, null, ['4']);
		$new = new ObjectEntity();
		$new->setRegister('1');
		$new->setSchema('4');
		$old = new ObjectEntity();
		$old->setRegister('1');
		$old->setSchema('99');

		$this->proxy()->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAPreWriteUpdateIsFilteredOnItsNewObject(): void {
		$this->subscribe(ObjectUpdatingEvent::class, null, ['4']);
		$new = new ObjectEntity();
		$new->setRegister('1');
		$new->setSchema('4');
		$old = new ObjectEntity();
		$old->setSchema('99');

		$proxy = $this->proxy();
		$proxy->handle(new ObjectUpdatingEvent(newObject: $new, oldObject: $old));
		$proxy->handle(new ObjectUpdatingEvent(newObject: $old, oldObject: $new));

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAnEventWithoutObjectBypassesTheFilters(): void {
		$this->subscribe(ProxyBehaviourObjectlessEvent::class, ['7'], ['8']);

		$this->proxy()->handle(new ProxyBehaviourObjectlessEvent());

		$this->assertCount(1, $this->spy->seen);
	}

	public function testAnUnloadableListenerIsLoggedOnceAndSkipped(): void {
		$this->subscribe(ProxyBehaviourObjectlessEvent::class, null, null, 'Missing\\Listener');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringStartsWith('Could not load filtered event listener service Missing\\Listener'), $this->anything());

		$proxy = $this->proxy();
		$proxy->handle(new ProxyBehaviourObjectlessEvent());
		$proxy->handle(new ProxyBehaviourObjectlessEvent());

		$this->assertSame([], $this->spy->seen);
	}

	public function testAServiceThatIsNotAListenerIsRefused(): void {
		$this->services['Not\\A\\Listener'] = new \stdClass();
		$this->subscribe(ProxyBehaviourObjectlessEvent::class, null, null, 'Not\\A\\Listener');
		$this->logger->expects($this->once())->method('error')
			->with('Filtered event listener Not\\A\\Listener does not implement IEventListener');

		$this->proxy()->handle(new ProxyBehaviourObjectlessEvent());
	}

	public function testTracingCountsInvokedAndSkippedDispatches(): void {
		$this->subscribe(ObjectCreatedEvent::class, ['7']);
		$this->subscribe(ObjectCreatedEvent::class, ['8']);
		$proxy = $this->proxy();
		(new ReflectionProperty($proxy, 'traceOn'))->setValue($proxy, true);
		// Armed already, so no shutdown hook writes a log file from this test.
		(new ReflectionProperty($proxy, 'traceArmed'))->setValue($proxy, true);

		$proxy->handle($this->created('7', '1'));

		$trace = (new ReflectionProperty($proxy, 'trace'))->getValue($proxy);
		$this->assertSame(1.0, $trace['dispatch']);
		$this->assertSame(1.0, $trace['invoked']);
		$this->assertSame(1.0, $trace['skipped']);
		$this->assertGreaterThanOrEqual(0.0, $trace['listenerUs']);
		$this->assertCount(1, $this->spy->seen);

		$line = (new \ReflectionMethod($proxy, 'traceLine'))->invoke($proxy);
		$this->assertMatchesRegularExpression('/^\d\d:\d\d:\d\d proxy subscriptions=2 dispatches=1 invoked=1 skipped=1 proxyUs=/', $line);
	}

	public function testFlushingWithoutDispatchesWritesNothing(): void {
		$proxy = $this->proxy();
		$before = @file_exists('/tmp/or-event-proxy.log') ? (string)@file_get_contents('/tmp/or-event-proxy.log') : null;

		(new \ReflectionMethod($proxy, 'flushTrace'))->invoke($proxy);

		$after = @file_exists('/tmp/or-event-proxy.log') ? (string)@file_get_contents('/tmp/or-event-proxy.log') : null;
		$this->assertSame($before, $after);
	}
}
