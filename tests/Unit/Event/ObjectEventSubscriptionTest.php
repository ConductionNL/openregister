<?php

/**
 * Tests for filtered object-event subscription.
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
use OCA\OpenRegister\Event\ObjectEventSubscription;
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
final class SpyObjectListener implements IEventListener {

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
 * Covers the numeric-declaration defect: a register/schema declared by id must
 * actually MATCH, not merely stop crashing.
 *
 * The regression these tests pin is two-layered, and only the second layer is
 * about behaviour. `normalise()` deduplicated through array keys, so PHP
 * coerced the numeric-string key `'62'` to `int 62`, and `strtolower(int)`
 * under `strict_types` is a fatal `TypeError` — every object write returned a
 * 500. Casting the keys back to string stops the crash but is NOT the fix on
 * its own: a numeric token would then be resolved as a slug, match no row, and
 * the listener would silently never fire. Proving "no longer 500s" would pass
 * against a completely dead listener, so every test here that matters asserts
 * an INVOCATION.
 */
class ObjectEventSubscriptionTest extends TestCase {

	/**
	 * The subscribed listener spy.
	 *
	 * @var SpyObjectListener
	 */
	private SpyObjectListener $spy;

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

		$this->spy = new SpyObjectListener();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === SpyObjectListener::class) {
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
	 * Declare a subscription through the boot-time entry point.
	 *
	 * @param array<int,string>|null $registers Register tokens.
	 * @param array<int,string>|null $schemas Schema tokens.
	 *
	 * @return void
	 */
	private function declare(?array $registers = null, ?array $schemas = null): void {
		ObjectEventSubscription::subscribe(
			dispatcher: $this->createMock(IEventDispatcher::class),
			event: ObjectCreatedEvent::class,
			listener: SpyObjectListener::class,
			registers: $registers,
			schemas: $schemas
		);

	}//end declare()

	/**
	 * Dispatch a create event for an object in the given register/schema.
	 *
	 * Both are written as the ids the entity actually carries, which is what
	 * `MagicMapper` stores.
	 *
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 *
	 * @return void
	 */
	private function dispatch(string $register, string $schema): void {
		$object = new ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);

		$this->proxy->handle(new ObjectCreatedEvent($object));

	}//end dispatch()

	/**
	 * A schema declared by numeric id INVOKES the listener.
	 *
	 * This is the positive control for the whole numeric-token path. Without
	 * token classification the id would be resolved as a slug, match nothing,
	 * and this assertion would read 0.
	 *
	 * @return void
	 */
	public function testNumericSchemaIdDeclarationInvokesListener(): void {
		$this->declare(schemas: ['62']);

		$this->dispatch(register: '17', schema: '62');

		$this->assertSame(1, $this->spy->calls, 'A schema declared by id must invoke the listener.');

	}//end testNumericSchemaIdDeclarationInvokesListener()

	/**
	 * A register declared by numeric id INVOKES the listener.
	 *
	 * @return void
	 */
	public function testNumericRegisterIdDeclarationInvokesListener(): void {
		$this->declare(registers: ['17']);

		$this->dispatch(register: '17', schema: '62');

		$this->assertSame(1, $this->spy->calls, 'A register declared by id must invoke the listener.');

	}//end testNumericRegisterIdDeclarationInvokesListener()

	/**
	 * A numeric declaration still SKIPS an object it does not name.
	 *
	 * The complement of the positive control: a filter that invoked everything
	 * would also pass the two tests above.
	 *
	 * @return void
	 */
	public function testNumericIdDeclarationSkipsOtherSchema(): void {
		$this->declare(schemas: ['62']);

		$this->dispatch(register: '17', schema: '999');

		$this->assertSame(0, $this->spy->calls, 'A schema declared by id must not invoke for another schema.');

	}//end testNumericIdDeclarationSkipsOtherSchema()

	/**
	 * Both filters must pass when register and schema are both declared by id.
	 *
	 * @return void
	 */
	public function testNumericRegisterAndSchemaMustBothMatch(): void {
		$this->declare(registers: ['17'], schemas: ['62']);

		$this->dispatch(register: '18', schema: '62');

		$this->assertSame(0, $this->spy->calls, 'A non-matching register must veto a matching schema.');

	}//end testNumericRegisterAndSchemaMustBothMatch()

	/**
	 * Dispatching a numeric declaration does not raise a TypeError.
	 *
	 * The original crash: `array_keys()` handed back `int 62`, and
	 * `strtolower(int)` is fatal under `strict_types`. Kept as an explicit
	 * regression pin so the 500 cannot come back silently behind the
	 * behavioural assertions above.
	 *
	 * @return void
	 */
	public function testNumericDeclarationDoesNotRaiseTypeError(): void {
		$this->declare(registers: ['17'], schemas: ['62']);

		$this->dispatch(register: '17', schema: '62');

		$this->assertSame(1, $this->spy->calls);

	}//end testNumericDeclarationDoesNotRaiseTypeError()

	/**
	 * Numeric tokens survive normalisation as strings.
	 *
	 * @return void
	 */
	public function testNumericTokensAreStoredAsStrings(): void {
		$this->declare(schemas: ['62', '62', '7']);

		$subscriptions = ObjectEventSubscription::subscriptionsFor(ObjectCreatedEvent::class);

		$this->assertCount(1, $subscriptions);
		$this->assertSame(['62', '7'], $subscriptions[0]['schemas']);

		foreach ($subscriptions[0]['schemas'] as $token) {
			$this->assertIsString($token, 'A numeric token must not be coerced to int by the dedup.');
		}

	}//end testNumericTokensAreStoredAsStrings()

	/**
	 * Id tokens are kept out of the slug-resolution list.
	 *
	 * Sending an id to `findIdsBySlugs()` cannot match and only widens the
	 * query the mechanism exists to keep bounded.
	 *
	 * @return void
	 */
	public function testIdTokensAreExcludedFromSlugResolution(): void {
		$this->declare(registers: ['17', 'procest'], schemas: ['62', 'bezwaar']);

		$this->assertSame(['procest'], ObjectEventSubscription::declaredRegisterTokens());
		$this->assertSame(['bezwaar'], ObjectEventSubscription::declaredSchemaTokens());

	}//end testIdTokensAreExcludedFromSlugResolution()

	/**
	 * Token classification treats only unsigned digit runs as ids.
	 *
	 * @return void
	 */
	public function testIsIdTokenClassification(): void {
		$this->assertTrue(ObjectEventSubscription::isIdToken('62'));
		$this->assertTrue(ObjectEventSubscription::isIdToken('0'));
		$this->assertFalse(ObjectEventSubscription::isIdToken('bezwaar'));
		$this->assertFalse(ObjectEventSubscription::isIdToken('-1'));
		$this->assertFalse(ObjectEventSubscription::isIdToken('1.5'));
		$this->assertFalse(ObjectEventSubscription::isIdToken('1e3'));
		$this->assertFalse(ObjectEventSubscription::isIdToken('123abc'));

	}//end testIsIdTokenClassification()

	/**
	 * The subscription count is the observable invariant.
	 *
	 * An app whose guard fell back to unfiltered registration contributes
	 * nothing here, which is what makes a silent fallback detectable.
	 *
	 * @return void
	 */
	public function testSubscriptionCountIsObservable(): void {
		$this->assertSame(0, ObjectEventSubscription::subscriptionCount());

		$this->declare(schemas: ['62']);
		$this->declare(schemas: ['63']);

		$this->assertSame(2, ObjectEventSubscription::subscriptionCount());
		$this->assertSame(
			[ObjectCreatedEvent::class => [SpyObjectListener::class, SpyObjectListener::class]],
			ObjectEventSubscription::subscribedListeners()
		);

	}//end testSubscriptionCountIsObservable()

	/**
	 * `subscribe()` registers the shared proxy exactly once per event class.
	 *
	 * @return void
	 */
	public function testSubscribeRegistersProxyOncePerEvent(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())
			->method('addServiceListener')
			->with(ObjectCreatedEvent::class, ObjectEventProxyListener::class);

		foreach (['62', '63', '64'] as $schema) {
			ObjectEventSubscription::subscribe(
				dispatcher: $dispatcher,
				event: ObjectCreatedEvent::class,
				listener: SpyObjectListener::class,
				schemas: [$schema]
			);
		}

	}//end testSubscribeRegistersProxyOncePerEvent()

	/**
	 * A declaration of neither register nor schema still means "all".
	 *
	 * @return void
	 */
	public function testUndeclaredSubscriptionInvokesForEverything(): void {
		$this->declare();

		$this->dispatch(register: '999', schema: '999');

		$this->assertSame(1, $this->spy->calls, 'Declaring nothing must still mean all.');

	}//end testUndeclaredSubscriptionInvokesForEverything()
}//end class
