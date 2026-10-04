<?php

/**
 * A filtered object-event subscription holds for every object event class.
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

namespace Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ObjectEventProxyListener;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Counting stand-in for a subscribed listener.
 *
 * @template-implements IEventListener<Event>
 */
final class CountingObjectListener implements IEventListener {

	/**
	 * How many times this listener was invoked.
	 *
	 * @var integer
	 */
	public int $calls = 0;

	/**
	 * Record an invocation.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		$this->calls++;

	}//end handle()
}//end class

/**
 * Pins live defect H1 (3 Oct) for every event class, not only the one seen.
 *
 * H1: the proxy read `getObject()`, `ObjectUpdatingEvent` only has
 * `getNewObject()`, so a subscriber filtered to humaniq's `managerdeputy`
 * schema ran on every update of every app and refused every lifecycle
 * transition on the instance. The fix for that one class landed in #4275; this
 * file asserts the filter holds for EVERY `Object*Event` that carries an
 * object, and fails when a new event class is added without being listed here,
 * so the next accessor name cannot slip through the same way.
 *
 * It also pins the fail-closed rule: an event that carries an object whose
 * register or schema is unknown does not reach a subscriber filtered on that
 * dimension. Only an event that carries no object at all (a merge) still
 * invokes, because a filter cannot be applied to it and skipping it would make
 * the listener silently dead.
 */
class ObjectEventProxyEveryEventTest extends TestCase {

	/**
	 * The subscribed listener.
	 *
	 * @var CountingObjectListener
	 */
	private CountingObjectListener $spy;

	/**
	 * The proxy under test.
	 *
	 * @var ObjectEventProxyListener
	 */
	private ObjectEventProxyListener $proxy;

	/**
	 * Build a proxy whose container resolves the spy and whose filter is on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		ObjectEventSubscription::reset();

		$this->spy = new CountingObjectListener();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === CountingObjectListener::class) {
					return $this->spy;
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('on');

		$this->proxy = new ObjectEventProxyListener(
			$container,
			$appConfig,
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Drop declarations so one test cannot leak into the next.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ObjectEventSubscription::reset();
		parent::tearDown();

	}//end tearDown()

	/**
	 * One factory per object event class, each building the REAL event.
	 *
	 * @return array<string, array{0: class-string, 1: \Closure(ObjectEntity): Event}>
	 */
	public static function objectEvents(): array {
		return [
			'created'      => [ObjectCreatedEvent::class, fn (ObjectEntity $o): Event => new ObjectCreatedEvent($o)],
			'creating'     => [ObjectCreatingEvent::class, fn (ObjectEntity $o): Event => new ObjectCreatingEvent($o)],
			'updating'     => [ObjectUpdatingEvent::class, fn (ObjectEntity $o): Event => new ObjectUpdatingEvent($o, clone $o)],
			'updated'      => [ObjectUpdatedEvent::class, fn (ObjectEntity $o): Event => new ObjectUpdatedEvent($o, clone $o)],
			'deleting'     => [ObjectDeletingEvent::class, fn (ObjectEntity $o): Event => new ObjectDeletingEvent($o)],
			'deleted'      => [ObjectDeletedEvent::class, fn (ObjectEntity $o): Event => new ObjectDeletedEvent($o)],
			'locked'       => [ObjectLockedEvent::class, fn (ObjectEntity $o): Event => new ObjectLockedEvent($o)],
			'unlocked'     => [ObjectUnlockedEvent::class, fn (ObjectEntity $o): Event => new ObjectUnlockedEvent($o)],
			'reverted'     => [ObjectRevertedEvent::class, fn (ObjectEntity $o): Event => new ObjectRevertedEvent($o)],
			'transitioned' => [
				ObjectTransitionedEvent::class,
				fn (ObjectEntity $o): Event => new ObjectTransitionedEvent($o, 'approve', 'draft', 'approved', 'u1', (string)$o->getRegister(), (string)$o->getSchema()),
			],
		];

	}//end objectEvents()

	/**
	 * Declare a subscription on one event class.
	 *
	 * @param string                 $event     Event class.
	 * @param array<int,string>|null $registers Register tokens.
	 * @param array<int,string>|null $schemas   Schema tokens.
	 *
	 * @return void
	 */
	private function declare(string $event, ?array $registers = null, ?array $schemas = null): void {
		ObjectEventSubscription::subscribe(
			dispatcher: $this->createMock(IEventDispatcher::class),
			event: $event,
			listener: CountingObjectListener::class,
			registers: $registers,
			schemas: $schemas
		);

	}//end declare()

	/**
	 * Build an object in the given register and schema.
	 *
	 * @param string|null $register Register id.
	 * @param string|null $schema   Schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $register, ?string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);
		return $object;

	}//end object()

	/**
	 * A subscriber filtered to schema A does not run for an object of schema B.
	 *
	 * @param string   $event   Event class.
	 * @param \Closure $factory Builds the event around an object.
	 *
	 * @return void
	 *
	 * @dataProvider objectEvents
	 */
	public function testSchemaFilteredSubscriberSkipsOtherSchema(string $event, \Closure $factory): void {
		$this->declare(event: $event, schemas: ['62']);

		$this->proxy->handle($factory($this->object(register: '7', schema: '99')));

		$this->assertSame(0, $this->spy->calls, $event . ' reached a subscriber filtered to another schema');

	}//end testSchemaFilteredSubscriberSkipsOtherSchema()

	/**
	 * Positive control: the same subscriber runs for an object of its schema.
	 *
	 * @param string   $event   Event class.
	 * @param \Closure $factory Builds the event around an object.
	 *
	 * @return void
	 *
	 * @dataProvider objectEvents
	 */
	public function testSchemaFilteredSubscriberRunsForItsSchema(string $event, \Closure $factory): void {
		$this->declare(event: $event, schemas: ['62']);

		$this->proxy->handle($factory($this->object(register: '7', schema: '62')));

		$this->assertSame(1, $this->spy->calls);

	}//end testSchemaFilteredSubscriberRunsForItsSchema()

	/**
	 * Every object event class in lib/Event is listed in the provider.
	 *
	 * A new event class whose object sits behind a new accessor name is
	 * exactly how H1 happened; listing it here forces the filter tests above
	 * to run against it.
	 *
	 * @return void
	 */
	public function testProviderCoversEveryObjectEventClass(): void {
		$listed = array_map(static fn (array $row): string => $row[0], self::objectEvents());

		$missing = [];
		foreach (glob(__DIR__ . '/../../../lib/Event/Object*Event.php') as $file) {
			$class = 'OCA\\OpenRegister\\Event\\' . basename($file, '.php');
			$ctor  = (new \ReflectionClass($class))->getConstructor();
			if ($ctor === null) {
				continue;
			}

			$first = ($ctor->getParameters()[0] ?? null)?->getType();
			if ($first instanceof \ReflectionNamedType && $first->getName() === ObjectEntity::class
				&& in_array($class, $listed, true) === false
			) {
				$missing[] = $class;
			}
		}

		$this->assertSame([], $missing, 'object event classes not covered by the filter tests');
		$this->assertCount(10, $listed);

	}//end testProviderCoversEveryObjectEventClass()

	/**
	 * Fail closed: an object without a schema does not reach a schema filter.
	 *
	 * @param string   $event   Event class.
	 * @param \Closure $factory Builds the event around an object.
	 *
	 * @return void
	 *
	 * @dataProvider objectEvents
	 */
	public function testObjectWithoutSchemaSkipsSchemaFilteredSubscriber(string $event, \Closure $factory): void {
		$this->declare(event: $event, schemas: ['62']);

		$this->proxy->handle($factory($this->object(register: '7', schema: null)));

		$this->assertSame(0, $this->spy->calls);

	}//end testObjectWithoutSchemaSkipsSchemaFilteredSubscriber()

	/**
	 * Fail closed: an object without a register does not reach a register filter.
	 *
	 * @return void
	 */
	public function testObjectWithoutRegisterSkipsRegisterFilteredSubscriber(): void {
		$this->declare(event: ObjectUpdatingEvent::class, registers: ['7']);

		$this->proxy->handle(new ObjectUpdatingEvent($this->object(register: null, schema: '62'), null));

		$this->assertSame(0, $this->spy->calls);

	}//end testObjectWithoutRegisterSkipsRegisterFilteredSubscriber()

	/**
	 * An object with neither register nor schema reaches no filtered subscriber.
	 *
	 * @return void
	 */
	public function testObjectWithoutRegisterOrSchemaSkipsFilteredSubscriber(): void {
		$this->declare(event: ObjectUpdatingEvent::class, schemas: ['62']);

		$this->proxy->handle(new ObjectUpdatingEvent($this->object(register: null, schema: null), null));

		$this->assertSame(0, $this->spy->calls);

	}//end testObjectWithoutRegisterOrSchemaSkipsFilteredSubscriber()

	/**
	 * An unfiltered subscriber still runs for an object without a schema.
	 *
	 * @return void
	 */
	public function testUnfilteredSubscriberRunsForObjectWithoutSchema(): void {
		$this->declare(event: ObjectUpdatingEvent::class);

		$this->proxy->handle(new ObjectUpdatingEvent($this->object(register: null, schema: null), null));

		$this->assertSame(1, $this->spy->calls);

	}//end testUnfilteredSubscriberRunsForObjectWithoutSchema()

	/**
	 * A filter on one dimension ignores the other being unknown.
	 *
	 * @return void
	 */
	public function testSchemaFilterIgnoresUnknownRegister(): void {
		$this->declare(event: ObjectUpdatingEvent::class, schemas: ['62']);

		$this->proxy->handle(new ObjectUpdatingEvent($this->object(register: null, schema: '62'), null));

		$this->assertSame(1, $this->spy->calls);

	}//end testSchemaFilterIgnoresUnknownRegister()

	/**
	 * An event that carries no object at all still invokes a filtered subscriber.
	 *
	 * The filter cannot be applied to a merge, and skipping would make every
	 * filtered merge listener silently dead.
	 *
	 * @return void
	 */
	public function testEventWithoutObjectInvokesFilteredSubscriber(): void {
		$this->declare(event: ObjectsMergedEvent::class, schemas: ['62']);

		$this->proxy->handle(new ObjectsMergedEvent('uuid-a', ['uuid-b'], 'op-1'));

		$this->assertSame(1, $this->spy->calls);

	}//end testEventWithoutObjectInvokesFilteredSubscriber()
}//end class
