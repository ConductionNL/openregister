<?php

/**
 * Golden test for RateLimiterService::consumeDistinctKeyBudget: which calls
 * are admitted and which refused, what the bucket looks like after an
 * admitted call, and that a refused call writes nothing.
 *
 * No assertion depends on the wall clock beyond "now or one second later":
 * buckets are seeded as fresh, as expired, or with no timestamp at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The distinct-key budget, pinned.
 */
class RateLimiterDistinctKeyBudgetGoldenTest extends TestCase {

	/**
	 * The writes the limiter made.
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: int}>
	 */
	private array $writes = [];

	/**
	 * A limiter whose cache answers the given bucket for every key.
	 *
	 * @param mixed $bucket What the cache holds.
	 *
	 * @return RateLimiterService The limiter.
	 */
	private function limiter(mixed $bucket): RateLimiterService {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($bucket);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->writes[] = [$key, $value, $ttl];

				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new RateLimiterService(cacheFactory: $factory);
	}//end limiter()

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: int, 3: array<int, string>|null}>
	 *         Seeded bucket (`now` replaced by the current time), the key, the
	 *         budget, and the stored keys after an admitted call (null: refused).
	 */
	public static function cases(): array {
		return [
			'no bucket'                         => [null, 'k', 2, ['k']],
			'a bucket that is no array'         => ['junk', 'k', 2, ['k']],
			'a live bucket with room'           => [['t' => 'now', 'keys' => ['a']], 'k', 2, ['a', 'k']],
			'a repeat in a full bucket is free' => [['t' => 'now', 'keys' => ['a', 'b']], 'a', 2, ['a', 'b']],
			'a new key in a full bucket'        => [['t' => 'now', 'keys' => ['a', 'b']], 'c', 2, null],
			'an expired full bucket resets'     => [['t' => 0, 'keys' => ['a', 'b']], 'c', 2, ['c']],
			'a bucket without a timestamp'      => [['keys' => ['a', 'b']], 'c', 2, ['c']],
			'keys that are no list'             => [['t' => 'now', 'keys' => 'a'], 'k', 2, ['a', 'k']],
			'a bucket without keys'             => [['t' => 'now'], 'k', 1, ['k']],
			'a zero budget refuses a fresh key' => [null, 'k', 0, null],
			'a loose key match is a new key'    => [['t' => 'now', 'keys' => ['1']], '01', 1, null],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testTheBudget(mixed $seed, string $key, int $max, ?array $stored): void {
		if (is_array($seed) === true && ($seed['t'] ?? null) === 'now') {
			$seed['t'] = time();
		}

		$before = time();
		$retry = $this->limiter($seed)->consumeDistinctKeyBudget(
			bucketKey: 'getmiss:alice',
			distinctKey: $key,
			maxKeys: $max,
			windowSeconds: 300
		);

		if ($stored === null) {
			self::assertNotNull($retry);
			self::assertGreaterThanOrEqual(299, $retry);
			self::assertLessThanOrEqual(300, $retry);
			self::assertSame([], $this->writes);

			return;
		}

		self::assertNull($retry);
		self::assertCount(1, $this->writes);
		[$bucketKey, $bucket, $ttl] = $this->writes[0];
		self::assertSame('getmiss:alice', $bucketKey);
		self::assertSame(300, $ttl);
		self::assertSame(['t', 'keys'], array_keys($bucket));
		self::assertSame($stored, $bucket['keys']);
		self::assertGreaterThanOrEqual($before - 1, $bucket['t']);
		self::assertLessThanOrEqual(time(), $bucket['t']);
	}//end testTheBudget()

	public function testABucketExactlyOneWindowOldResets(): void {
		$retry = $this->limiter(['t' => time() - 300, 'keys' => ['a']])->consumeDistinctKeyBudget(
			bucketKey: 'b',
			distinctKey: 'c',
			maxKeys: 1,
			windowSeconds: 300
		);

		self::assertNull($retry);
		self::assertSame(['c'], $this->writes[0][1]['keys']);
	}//end testABucketExactlyOneWindowOldResets()

	public function testRetryAfterIsAtLeastOneSecond(): void {
		$retry = $this->limiter(['t' => time() - 299, 'keys' => ['a']])->consumeDistinctKeyBudget(
			bucketKey: 'b',
			distinctKey: 'c',
			maxKeys: 1,
			windowSeconds: 300
		);

		// 299 or 300 seconds old: still live at 299, reset at 300.
		if ($retry === null) {
			self::assertSame(['c'], $this->writes[0][1]['keys']);

			return;
		}

		self::assertSame(1, $retry);
		self::assertSame([], $this->writes);
	}//end testRetryAfterIsAtLeastOneSecond()
}//end class
