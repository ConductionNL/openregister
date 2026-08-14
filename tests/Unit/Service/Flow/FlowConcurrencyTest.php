<?php

/**
 * The three properties a serial loop gets for free.
 *
 * Making per-item work concurrent is easy; keeping it BOUNDED, ORDERED and
 * ISOLATED while doing so is the part that goes wrong. Each of the three is
 * asserted here against a shape that would fail without it — a bound that is
 * never exceeded is only meaningful if the test could observe it being
 * exceeded, so the concurrency assertion tracks the true in-flight peak rather
 * than trusting the argument.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use GuzzleHttp\Promise\Promise;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowConcurrency
 */
class FlowConcurrencyTest extends TestCase {

	private FlowConcurrency $concurrency;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->concurrency = new FlowConcurrency();

	}//end setUp()

	/**
	 * An empty list does no work and returns nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsAnEmptyResult(): void {
		$called = 0;
		$out = $this->concurrency->map(
			[],
			static function () use (&$called) {
				$called++;
				return 'x';
			}
		);

		$this->assertSame([], $out);
		$this->assertSame(0, $called, 'work was invoked for an empty list');

	}//end testAnEmptyListIsAnEmptyResult()

	/**
	 * Synchronous work is accepted, so a node with a cache hit need not
	 * fabricate a promise.
	 *
	 * @return void
	 */
	public function testPlainValuesAreAccepted(): void {
		$out = $this->concurrency->map(
			['a', 'b', 'c'],
			static function (string $item): string {
				return strtoupper($item);
			}
		);

		$this->assertSame(['A', 'B', 'C'], array_column($out, 'value'));

	}//end testPlainValuesAreAccepted()

	/**
	 * Results come back in INPUT order, never completion order.
	 *
	 * Each promise resolves inside its own wait function, and the LAST item is
	 * made to settle first by having item 0's wait function resolve every other
	 * item before itself. `$settled` records the true completion order, and the
	 * test asserts it really is reversed — so if `map()` returned completion
	 * order the expectation below would be `[3,2,1,0]` and this would fail.
	 *
	 * @return void
	 */
	public function testResultsAreInInputOrderNotCompletionOrder(): void {
		$settled = [];
		$promises = [];

		$out = $this->concurrency->map(
			[0, 1, 2, 3],
			static function (int $item) use (&$promises, &$settled) {
				$promise = new Promise(
					static function () use (&$promise, &$promises, &$settled, $item): void {
						// Item 0 settles the others first, newest to oldest, so
						// completion order is the reverse of input order.
						if ($item === 0) {
							foreach (array_reverse(array_keys($promises)) as $other) {
								if ($other !== 0) {
									$settled[] = $other;
									$promises[$other]->resolve($other);
								}
							}
						}

						$settled[] = $item;
						$promise->resolve($item);
					}
				);

				$promises[$item] = $promise;

				return $promise;
			},
			4
		);

		$this->assertSame([3, 2, 1, 0], $settled, 'the fixture did not settle out of order, so ordering is untested');
		$this->assertSame([0, 1, 2, 3], array_column($out, 'value'));

	}//end testResultsAreInInputOrderNotCompletionOrder()

	/**
	 * One item's rejection does not cost the others their results.
	 *
	 * @return void
	 */
	public function testOneFailureLeavesTheOtherResultsIntact(): void {
		$out = $this->concurrency->map(
			[0, 1, 2, 3, 4],
			static function (int $item) {
				if ($item === 2) {
					return \GuzzleHttp\Promise\Create::rejectionFor(new RuntimeException('upstream said no'));
				}

				return $item * 10;
			}
		);

		$this->assertCount(5, $out);
		$this->assertTrue($out[0]['ok']);
		$this->assertSame(10, $out[1]['value']);
		$this->assertFalse($out[2]['ok'], 'the failing item was not reported as failed');
		$this->assertInstanceOf(RuntimeException::class, $out[2]['error']);
		$this->assertSame('upstream said no', $out[2]['error']->getMessage());
		$this->assertSame(30, $out[3]['value'], 'a later item lost its result to an earlier failure');
		$this->assertSame(40, $out[4]['value']);

	}//end testOneFailureLeavesTheOtherResultsIntact()

	/**
	 * Work that throws SYNCHRONOUSLY is isolated too.
	 *
	 * This is the case a rejection handler alone does not cover: the callable
	 * never returns a promise, so nothing rejects — the throw would escape
	 * `map()` and take every other item's result with it.
	 *
	 * @return void
	 */
	public function testSynchronousThrowsAreIsolated(): void {
		$out = $this->concurrency->map(
			[0, 1, 2],
			static function (int $item) {
				if ($item === 1) {
					throw new RuntimeException('threw before returning');
				}

				return $item;
			}
		);

		$this->assertCount(3, $out);
		$this->assertTrue($out[0]['ok']);
		$this->assertFalse($out[1]['ok']);
		$this->assertSame('threw before returning', $out[1]['error']->getMessage());
		$this->assertTrue($out[2]['ok'], 'a synchronous throw took out a later item');

	}//end testSynchronousThrowsAreIsolated()

	/**
	 * The bound is real: never more than the limit in flight at once.
	 *
	 * Measured, not assumed. Each item increments a counter on entry and
	 * decrements on settle, and the PEAK is asserted — so a `map()` that
	 * started everything at once would record a peak of 12 and fail here.
	 *
	 * @return void
	 */
	public function testConcurrencyNeverExceedsTheLimit(): void {
		$inFlight = 0;
		$peak = 0;
		$pending = [];

		$out = $this->concurrency->map(
			range(1, 12),
			static function (int $item) use (&$inFlight, &$peak, &$pending): Promise {
				$inFlight++;
				$peak = max($peak, $inFlight);

				$promise = new Promise(
					static function () use (&$promise, $item, &$inFlight): void {
						$inFlight--;
						$promise->resolve($item);
					}
				);
				$pending[] = $promise;

				return $promise;
			},
			3
		);

		$this->assertSame(3, $peak, 'more calls were in flight than the limit allowed');
		$this->assertCount(12, $out);

	}//end testConcurrencyNeverExceedsTheLimit()

	/**
	 * A limit above the ceiling is clamped, and one below one becomes one.
	 *
	 * The ceiling is what stops a mis-authored node configuration becoming a
	 * burst; the floor stops a zero from hanging the step instead of failing.
	 *
	 * @return void
	 */
	public function testTheLimitIsClamped(): void {
		$peak = 0;
		$inFlight = 0;

		$work = static function (int $item) use (&$inFlight, &$peak): Promise {
			$inFlight++;
			$peak = max($peak, $inFlight);
			$promise = new Promise(
				static function () use (&$promise, $item, &$inFlight): void {
					$inFlight--;
					$promise->resolve($item);
				}
			);

			return $promise;
		};

		$this->concurrency->map(range(1, 30), $work, 999);
		$this->assertLessThanOrEqual(
			FlowConcurrency::MAX_LIMIT,
			$peak,
			'a limit above the ceiling was honoured instead of clamped'
		);

		$peak = 0;
		$inFlight = 0;
		$out = $this->concurrency->map([1, 2, 3], $work, 0);
		$this->assertCount(3, $out, 'a zero limit did not run the items');

	}//end testTheLimitIsClamped()

}//end class
