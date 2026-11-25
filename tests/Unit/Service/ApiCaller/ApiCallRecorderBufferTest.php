<?php

/**
 * The memory-cache buffer of the API call recorder: the first call of a key is
 * written at once, the calls after it in the window are counted in the cache
 * and written by the first call after the window, and a missing or failing
 * cache falls back to one write per call.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\ApiCallKey;
use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Service\ApiCaller\ApiCallObservation;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ApiCallRecorderBufferTest extends TestCase {

	/**
	 * The cache contents, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * The calls each mapper write added, in order.
	 *
	 * @var array<int, int>
	 */
	private array $written = [];

	private function memcache(): IMemcache {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			function (string $key, int $step = 1): int {
				$this->store[$key] = ((int)($this->store[$key] ?? 0) + $step);
				return $this->store[$key];
			}
		);
		$cache->method('dec')->willReturnCallback(
			function (string $key, int $step = 1): int {
				$this->store[$key] = ((int)($this->store[$key] ?? 0) - $step);
				return $this->store[$key];
			}
		);
		$cache->method('add')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				if (array_key_exists($key, $this->store) === true) {
					return false;
				}

				$this->store[$key] = $value;
				return true;
			}
		);

		return $cache;
	}//end memcache()

	private function factory(?ICache $cache, bool $available = true): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($available);
		$factory->method('createDistributed')->willReturn($cache ?? $this->createMock(ICache::class));
		return $factory;
	}//end factory()

	private function recorder(?ICacheFactory $factory, string $uid = 'leverancier'): ApiCallRecorder {
		$mapper = $this->createMock(ApiCallRecordMapperInterface::class);
		$mapper->method('count')->willReturnCallback(
			function (ApiCallKey $key, DateTime $moment, int $calls = 1): bool {
				$this->written[] = $calls;
				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ApiCallRecorder(
			mapper: $mapper,
			userSession: $session,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $factory,
		);
	}//end recorder()

	private static function call(string $path = '/apps/openregister/api/registers'): ApiCallObservation {
		return new ApiCallObservation(path: $path, method: 'GET', apiVersion: '1');
	}//end call()

	/**
	 * Close every open window, as the cache does when its lifetime passes.
	 *
	 * @return void
	 */
	private function closeWindows(): void {
		foreach (array_keys($this->store) as $key) {
			if (str_starts_with($key, 'window_') === true) {
				unset($this->store[$key]);
			}
		}
	}//end closeWindows()

	public function testTheFirstCallIsWrittenAndTheRestOfTheWindowIsBuffered(): void {
		$recorder = $this->recorder(factory: $this->factory(cache: $this->memcache()));

		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());

		$this->assertSame([1], $this->written);
	}//end testTheFirstCallIsWrittenAndTheRestOfTheWindowIsBuffered()

	public function testTheFirstCallAfterTheWindowWritesWhatWasBuffered(): void {
		$recorder = $this->recorder(factory: $this->factory(cache: $this->memcache()));

		for ($i = 0; $i < 4; $i++) {
			$recorder->recordCall(call: self::call());
		}

		$this->closeWindows();
		$recorder->recordCall(call: self::call());

		$this->assertSame([1, 4], $this->written, 'Three buffered calls and the call that closed the window.');
		$this->assertSame(5, array_sum($this->written));
	}//end testTheFirstCallAfterTheWindowWritesWhatWasBuffered()

	public function testEachRouteHasItsOwnWindow(): void {
		$recorder = $this->recorder(factory: $this->factory(cache: $this->memcache()));

		$recorder->recordCall(call: self::call(path: '/apps/openregister/api/registers'));
		$recorder->recordCall(call: self::call(path: '/apps/openregister/api/schemas'));
		$recorder->recordCall(call: self::call(path: '/apps/openregister/api/schemas'));

		$this->assertSame([1, 1], $this->written);
	}//end testEachRouteHasItsOwnWindow()

	public function testWithoutACacheFactoryEveryCallIsWritten(): void {
		$recorder = $this->recorder(factory: null);

		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());

		$this->assertSame([1, 1], $this->written);
	}//end testWithoutACacheFactoryEveryCallIsWritten()

	public function testAnUnavailableCacheWritesEveryCall(): void {
		$recorder = $this->recorder(factory: $this->factory(cache: $this->memcache(), available: false));

		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());

		$this->assertSame([1, 1], $this->written);
	}//end testAnUnavailableCacheWritesEveryCall()

	public function testACacheWithoutCountersWritesEveryCall(): void {
		$recorder = $this->recorder(factory: $this->factory(cache: null));

		$recorder->recordCall(call: self::call());
		$recorder->recordCall(call: self::call());

		$this->assertSame([1, 1], $this->written);
	}//end testACacheWithoutCountersWritesEveryCall()

	public function testACounterThatFailsWritesTheCall(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willThrowException(new RuntimeException('redis gone'));
		$recorder = $this->recorder(factory: $this->factory(cache: $cache));

		$recorder->recordCall(call: self::call());

		$this->assertSame([1], $this->written);
	}//end testACounterThatFailsWritesTheCall()

	public function testACounterThatAnswersNoNumberWritesTheCall(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturn(false);
		$recorder = $this->recorder(factory: $this->factory(cache: $cache));

		$recorder->recordCall(call: self::call());

		$this->assertSame([1], $this->written);
	}//end testACounterThatAnswersNoNumberWritesTheCall()

	public function testAWindowThatCannotBeOpenedLeavesTheCallBuffered(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturn(1);
		$cache->method('add')->willThrowException(new RuntimeException('redis gone'));
		$cache->expects($this->never())->method('dec');
		$recorder = $this->recorder(factory: $this->factory(cache: $cache));

		$recorder->recordCall(call: self::call());

		$this->assertSame([], $this->written);
	}//end testAWindowThatCannotBeOpenedLeavesTheCallBuffered()
}//end class
