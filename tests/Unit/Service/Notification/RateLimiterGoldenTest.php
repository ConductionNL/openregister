<?php

/**
 * Golden characterization test for RateLimiter::tryConsume().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Plays fixed sequences of tryConsume() calls against an in-memory cache and
 * a controlled clock, and compares every answer, cache call, config read and
 * log line with `fixtures/rate-limiter-golden.json`. Set `OR_GOLDEN_WRITE=1`
 * to regenerate the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Notification\RateLimiter
 * @covers \OCA\OpenRegister\Service\Notification\TokenBucket
 */
final class RateLimiterGoldenTest extends TestCase {

	/**
	 * Calls recorded during one scenario.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The fake clock.
	 *
	 * @var integer
	 */
	private int $now = 1000;

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/rate-limiter-golden.json';
	}//end goldenPath()

	/**
	 * The scenarios: name => [settings, steps].
	 *
	 * Settings: enabled, bucket, refill, cache mode. A step is
	 * [rule, recipient, override] or ['+', seconds].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array>}>
	 */
	private static function scenarios(): array {
		$burst = array_fill(0, 12, ['r', 'alice', null]);
		return [
			'disabled-false' => [['enabled' => 'false'], [['r', 'alice', null]]],
			'disabled-zero' => [['enabled' => '0'], [['r', 'alice', null]]],
			'no-cache' => [['cache' => 'none'], [['r', 'alice', null]]],
			'empty-ids' => [[], [['', 'alice', null], ['r', '', null]]],
			'default-burst-and-refill' => [[], array_merge($burst, [['+', 120], ['r', 'alice', null], ['r', 'alice', null], ['r', 'alice', null], ['+', 30], ['r', 'alice', null]])],
			'override' => [[], [
				['r', 'bob', ['bucketSize' => 2, 'refillSecondsPerToken' => '30']],
				['r', 'bob', ['bucketSize' => 2, 'refillSecondsPerToken' => '30']],
				['r', 'bob', ['bucketSize' => 2, 'refillSecondsPerToken' => '30']],
				['+', 30],
				['r', 'bob', ['bucketSize' => 2, 'refillSecondsPerToken' => '30']],
			]],
			'override-invalid' => [['bucket' => 2], [
				['r', 'bob', ['bucketSize' => '0', 'refillSecondsPerToken' => -1]],
				['r', 'bob', ['bucketSize' => 'x', 'refillSecondsPerToken' => '5s']],
				['r', 'bob', ['bucketSize' => '3']],
				['r', 'bob', []],
			]],
			'shared-budget' => [['bucket' => 3], [
				['a', 'carol', ['bucketSize' => 5]],
				['b', 'carol', ['bucketSize' => 5]],
				['c', 'carol', ['bucketSize' => 5]],
				['d', 'carol', ['bucketSize' => 5]],
				['+', 60],
				['e', 'carol', null],
			]],
			'pseudo-recipient' => [['bucket' => 1], [['a', '__webhook__', null], ['b', '__webhook__', null], ['a', '__webhook__', null]]],
			'config-zero' => [['bucket' => 0, 'refill' => 0], [['r', 'dave', null]]],
			'config-throws' => [['bucket' => 'throw'], [['r', 'dave', null], ['r', 'dave', ['bucketSize' => 1]]]],
			'enabled-throws' => [['enabled' => 'throw'], [['r', 'dave', null]]],
			'get-throws' => [['cache' => 'get-throws'], [['r', 'erin', null]]],
			'shared-get-throws' => [['cache' => 'shared-get-throws', 'bucket' => 1], [['r', 'erin', null], ['r', 'erin', null]]],
			'set-throws' => [['cache' => 'set-throws', 'bucket' => 1], [['r', 'erin', null], ['r', 'erin', null]]],
			'corrupt-state' => [['cache' => 'corrupt'], [['r', 'frank', null]]],
			'clock-backwards' => [['bucket' => 1], [['r', 'gina', null], ['+', -100], ['r', 'gina', null]]],
		];
	}//end scenarios()

	/**
	 * Case names for the data provider.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end cases()

	/**
	 * Each scenario answers and acts as recorded.
	 *
	 * @param string $name The scenario name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testTryConsumeMatchesGolden(string $name): void {
		$actual = $this->runScenario(scenario: self::scenarios()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testTryConsumeMatchesGolden()

	/**
	 * Play one scenario and return what it did.
	 *
	 * @param array{0: array<string, mixed>, 1: array<int, array>} $scenario The scenario.
	 *
	 * @return array<int, mixed>
	 */
	private function runScenario(array $scenario): array {
		[$settings, $steps] = $scenario;
		$this->calls = [];
		$this->now = 1000;
		$mode = ($settings['cache'] ?? 'ok');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($settings): string {
				$this->calls[] = ['config.string', $key];
				if (($settings['enabled'] ?? 'true') === 'throw') {
					throw new \RuntimeException('config down');
				}

				return (string)($settings['enabled'] ?? $default);
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0) use ($settings): int {
				$this->calls[] = ['config.int', $key];
				if (($settings['bucket'] ?? null) === 'throw') {
					throw new \RuntimeException('config down');
				}

				if (str_ends_with($key, 'bucket_size') === true) {
					return (int)($settings['bucket'] ?? $default);
				}

				return (int)($settings['refill'] ?? $default);
			}
		);

		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use (&$store, $mode): mixed {
				$this->calls[] = ['cache.get', $key];
				$shared = str_contains($key, ':shared:');
				if ($mode === 'get-throws' || ($mode === 'shared-get-throws' && $shared === true)) {
					throw new \RuntimeException('cache down');
				}

				if ($mode === 'corrupt') {
					return '{"tokens": 1}';
				}

				return $store[$key] ?? null;
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0) use (&$store, $mode): bool {
				$this->calls[] = ['cache.set', $key, $value, $ttl];
				if ($mode === 'set-throws') {
					throw new \RuntimeException('cache down');
				}

				$store[$key] = $value;
				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		if ($mode === 'none') {
			$cacheFactory->method('createDistributed')->willThrowException(new \RuntimeException('no cache'));
		} else {
			$cacheFactory->method('createDistributed')->willReturn($cache);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message) use ($level): void {
					$this->calls[] = ['log.'.$level, (string)$message];
				}
			);
		}

		$limiter = new RateLimiter(
			cacheFactory: $cacheFactory,
			appConfig: $appConfig,
			logger: $logger,
			timeProvider: fn (): int => $this->now
		);

		foreach ($steps as $step) {
			if ($step[0] === '+') {
				$this->now += $step[1];
				continue;
			}

			$this->calls[] = ['tryConsume', $step[0], $step[1], $limiter->tryConsume($step[0], $step[1], $step[2])];
		}

		return $this->calls;
	}//end runScenario()
}//end class
