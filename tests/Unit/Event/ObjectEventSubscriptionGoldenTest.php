<?php

/**
 * Golden pin for ObjectEventSubscription.
 *
 * Records, for fixed sequences of register()/subscribe() calls, which proxy
 * registrations reached the context or dispatcher and what every read-side
 * method returned, before the class was split into smaller methods.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every ObjectEventSubscription outcome recorded on the pre-refactor code.
 */
class ObjectEventSubscriptionGoldenTest extends TestCase {
	/**
	 * The request time in place before the test, restored afterwards.
	 *
	 * @var mixed
	 */
	private mixed $savedRequestTime = null;

	/**
	 * Proxy registrations seen by the current case, as `via:event:listener`.
	 *
	 * @var array<int, string>
	 */
	private array $proxyCalls = [];

	/**
	 * Start every case from an empty registry inside one fixed request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		$_SERVER['REQUEST_TIME_FLOAT'] = 1000.5;
		ObjectEventSubscription::reset();
	}//end setUp()

	/**
	 * Leave the registry empty and the request time as it was.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ObjectEventSubscription::reset();
		$_SERVER['REQUEST_TIME_FLOAT'] = $this->savedRequestTime;
		if ($this->savedRequestTime === null) {
			unset($_SERVER['REQUEST_TIME_FLOAT']);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The call sequences, each with its recorded outcome.
	 *
	 * Each call is [via, event, listener, registers, schemas]; via is
	 * `context` for register() and `dispatcher` for subscribe().
	 *
	 * @return array<string, array{array<int, array{string, string, string, mixed, mixed}>, string}> The cases.
	 */
	public static function recordedSequences(): array {
		return [
			'nothing registered' => [[], '{"proxies":[],"subscriptions":{"E1":[],"E2":[],"E3":[],"none":[]},"registers":[],"schemas":[],"count":0,"listeners":[]}'],
			'one unfiltered register' => [[['context', 'E1', 'L1', null, null]], '{"proxies":["context:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":null,"schemas":null}],"E2":[],"E3":[],"none":[]},"registers":[],"schemas":[],"count":1,"listeners":{"E1":["L1"]}}'],
			'proxy registered once per event' => [
				[
					['context', 'E1', 'L1', null, null],
					['context', 'E1', 'L2', ['crm'], null],
					['dispatcher', 'E1', 'L3', null, ['case']],
					['dispatcher', 'E2', 'L1', null, null],
					['context', 'E2', 'L4', null, null],
				],
				'{"proxies":["context:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener","dispatcher:E2:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":null,"schemas":null},{"listener":"L2","registers":["crm"],"schemas":null},{"listener":"L3","registers":null,"schemas":["case"]}],"E2":[{"listener":"L1","registers":null,"schemas":null},{"listener":"L4","registers":null,"schemas":null}],"E3":[],"none":[]},"registers":["crm"],"schemas":["case"],"count":5,"listeners":{"E1":["L1","L2","L3"],"E2":["L1","L4"]}}',
			],
			'tokens trimmed, deduplicated, empties dropped' => [
				[['dispatcher', 'E1', 'L1', [' crm ', 'crm', '', '  ', 'zaken'], ['case', ' case', 7, '12']]],
				'{"proxies":["dispatcher:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":["crm","zaken"],"schemas":["case","7","12"]}],"E2":[],"E3":[],"none":[]},"registers":["crm","zaken"],"schemas":["case"],"count":1,"listeners":{"E1":["L1"]}}',
			],
			'all-empty filters become null' => [[['context', 'E1', 'L1', ['', ' '], []]], '{"proxies":["context:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":null,"schemas":null}],"E2":[],"E3":[],"none":[]},"registers":[],"schemas":[],"count":1,"listeners":{"E1":["L1"]}}'],
			'id tokens excluded from declared tokens' => [
				[
					['context', 'E1', 'L1', ['12', 'crm', '007'], ['3', 'case']],
					['context', 'E2', 'L2', ['crm', 'hr'], ['case', '4a']],
					['dispatcher', 'E3', 'L3', [99, 'x1'], null],
				],
				'{"proxies":["context:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener","context:E2:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener","dispatcher:E3:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":["12","crm","007"],"schemas":["3","case"]}],"E2":[{"listener":"L2","registers":["crm","hr"],"schemas":["case","4a"]}],"E3":[{"listener":"L3","registers":["99","x1"],"schemas":null}],"none":[]},"registers":["crm","hr","x1"],"schemas":["case","4a"],"count":3,"listeners":{"E1":["L1"],"E2":["L2"],"E3":["L3"]}}',
			],
			'numeric-looking tokens keep string form' => [[['context', 'E1', 'L1', ['1.5', '-3', '0x1A'], ['1e3']]], '{"proxies":["context:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":["1.5","-3","0x1A"],"schemas":["1e3"]}],"E2":[],"E3":[],"none":[]},"registers":["1.5","-3","0x1A"],"schemas":["1e3"],"count":1,"listeners":{"E1":["L1"]}}'],
			'same listener twice is kept twice' => [
				[
					['dispatcher', 'E1', 'L1', ['a'], null],
					['dispatcher', 'E1', 'L1', ['a'], null],
				],
				'{"proxies":["dispatcher:E1:OCA\\\\OpenRegister\\\\Listener\\\\ObjectEventProxyListener"],"subscriptions":{"E1":[{"listener":"L1","registers":["a"],"schemas":null},{"listener":"L1","registers":["a"],"schemas":null}],"E2":[],"E3":[],"none":[]},"registers":["a"],"schemas":[],"count":2,"listeners":{"E1":["L1","L1"]}}',
			],
		];
	}//end recordedSequences()

	/**
	 * Each call sequence still yields the recorded registrations and reads.
	 *
	 * @param array<int, array{string, string, string, mixed, mixed}> $calls    The calls.
	 * @param string                                                  $expected The recorded outcome, as JSON.
	 *
	 * @return void
	 */
	#[DataProvider('recordedSequences')]
	public function testSequenceMatchesRecording(array $calls, string $expected): void {
		$this->assertSame($expected, $this->outcome($calls));
	}//end testSequenceMatchesRecording()

	/**
	 * A new request clears what the previous request registered.
	 *
	 * @return void
	 */
	public function testNewRequestStartsEmpty(): void {
		$this->replay([['context', 'E1', 'L1', ['crm'], null]]);
		$_SERVER['REQUEST_TIME_FLOAT'] = 2000.5;

		$this->assertSame([], ObjectEventSubscription::subscriptionsFor('E1'));
		$this->assertSame(0, ObjectEventSubscription::subscriptionCount());

		$this->replay([['context', 'E1', 'L2', null, null]]);
		$this->assertSame(['context:E1:OCA\OpenRegister\Listener\ObjectEventProxyListener', 'context:E1:OCA\OpenRegister\Listener\ObjectEventProxyListener'], $this->proxyCalls);
	}//end testNewRequestStartsEmpty()

	/**
	 * Replay the calls and render every read-side result as JSON.
	 *
	 * @param array<int, array{string, string, string, mixed, mixed}> $calls The calls.
	 *
	 * @return string The outcome.
	 */
	private function outcome(array $calls): string {
		$this->replay($calls);

		$events = ['E1', 'E2', 'E3', 'none'];
		$byEvent = [];
		foreach ($events as $event) {
			$byEvent[$event] = ObjectEventSubscription::subscriptionsFor($event);
		}

		return (string)json_encode(
			[
				'proxies' => $this->proxyCalls,
				'subscriptions' => $byEvent,
				'registers' => ObjectEventSubscription::declaredRegisterTokens(),
				'schemas' => ObjectEventSubscription::declaredSchemaTokens(),
				'count' => ObjectEventSubscription::subscriptionCount(),
				'listeners' => ObjectEventSubscription::subscribedListeners(),
			]
		);
	}//end outcome()

	/**
	 * Replay calls through register() or subscribe() with recording doubles.
	 *
	 * @param array<int, array{string, string, string, mixed, mixed}> $calls The calls.
	 *
	 * @return void
	 */
	private function replay(array $calls): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(function (string $event, string $listener): void {
			$this->proxyCalls[] = 'context:'.$event.':'.$listener;
		});
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(function (string $event, string $listener): void {
			$this->proxyCalls[] = 'dispatcher:'.$event.':'.$listener;
		});

		foreach ($calls as [$via, $event, $listener, $registers, $schemas]) {
			if ($via === 'context') {
				ObjectEventSubscription::register($context, $event, $listener, $registers, $schemas);
				continue;
			}

			ObjectEventSubscription::subscribe($dispatcher, $event, $listener, $registers, $schemas);
		}
	}//end replay()
}//end class
