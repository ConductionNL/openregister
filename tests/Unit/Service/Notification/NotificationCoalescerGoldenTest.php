<?php

/**
 * Golden test for NotificationCoalescer.
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
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\CoalesceBucket;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationCoalescer;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Plays fixed sequences of shouldDispatch() and inspect() calls against an
 * in-memory cache and a scripted clock (fixed timestamps, never the real
 * time), and pins every answer, the cache contents, the cache traffic and
 * the log lines. Expected values were recorded from the code before its
 * refactor and live in `fixtures/notification-coalescer-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\NotificationCoalescer
 */
class NotificationCoalescerGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-coalescer-golden.json';
	}//end goldenPath()

	/**
	 * Calls played per environment: [rule, recipient, override, clock].
	 *
	 * @return array<int, array{string, string, array<string, mixed>|null, int}>
	 */
	public static function script(): array {
		$w60   = ['windowSeconds' => 60];
		$max3  = ['windowSeconds' => 60, 'maxEvents' => 3];
		$str   = ['windowSeconds' => '30', 'maxEvents' => '2'];
		$calls = [
			['r', 'alice', null, 1000],
			['r', 'alice', $w60, 1000],
			['r', 'alice', $w60, 1010],
			['r', 'alice', $w60, 1059],
			['r', 'alice', $w60, 1060],
			['r', 'alice', $w60, 1061],
			['r', 'bob', $max3, 2000],
			['r', 'bob', $max3, 2001],
			['r', 'bob', $max3, 2002],
			['r', 'bob', $max3, 2003],
			['r', 'bob', $max3, 2004],
			['s', 'bob', $str, 3000],
			['s', 'bob', $str, 3001],
			['s', 'bob', $str, 3002],
			['s', 'bob', $str, 3031],
			['', 'bob', $w60, 4000],
			['r', '', $w60, 4000],
			['r', 'carol', [], 4000],
			['r', 'carol', ['windowSeconds' => 0], 4000],
			['r', 'carol', ['windowSeconds' => -5], 4000],
			['r', 'carol', ['windowSeconds' => '1.5'], 4000],
			['r', 'carol', ['windowSeconds' => '007', 'maxEvents' => 0], 4000],
			['r', 'carol', ['windowSeconds' => '007', 'maxEvents' => '-1'], 4001],
			['r', 'carol', ['windowSeconds' => 7.0, 'maxEvents' => 1], 4002],
			['r', 'carol', ['windowSeconds' => 9, 'maxEvents' => 1], 4003],
			['r', 'carol', ['windowSeconds' => 9, 'maxEvents' => 1], 4004],
			['r', 'dave', ['windowSeconds' => 9, 'maxEvents' => 2], 5000],
			['r', 'dave', ['windowSeconds' => 9, 'maxEvents' => 2], 5001],
			['r', 'dave', ['windowSeconds' => 9, 'maxEvents' => 2], 5002],
			['r', 'eve', $w60, 6000],
			['r', 'eve', $w60, 6001],
		];

		return $calls;
	}//end script()

	/**
	 * Environments: app-config answer, and how the cache behaves.
	 *
	 * @return array<string, array{config: string|null, cache: string}>
	 */
	public static function environments(): array {
		return [
			'default'        => ['config' => 'true', 'cache' => 'ok'],
			'disabled'       => ['config' => 'false', 'cache' => 'ok'],
			'disabled-zero'  => ['config' => '0', 'cache' => 'ok'],
			'config-other'   => ['config' => 'no', 'cache' => 'ok'],
			'config-throws'  => ['config' => null, 'cache' => 'ok'],
			'no-cache'       => ['config' => 'true', 'cache' => 'factory-throws'],
			'get-throws'     => ['config' => 'true', 'cache' => 'get-throws'],
			'set-throws'     => ['config' => 'true', 'cache' => 'set-throws'],
			'corrupt-state'  => ['config' => 'true', 'cache' => 'corrupt'],
		];
	}//end environments()

	/**
	 * Play the script in one environment.
	 *
	 * @param array{config: string|null, cache: string} $env The environment.
	 *
	 * @return array<string, mixed>
	 */
	private function play(array $env): array {
		$store   = [];
		$traffic = [];
		$log     = [];
		$clock   = 0;

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store, &$traffic, $env) {
				$traffic[] = 'get '.$key;
				if ($env['cache'] === 'get-throws') {
					throw new RuntimeException('get failed');
				}

				if ($env['cache'] === 'corrupt' && isset($store[$key]) === false) {
					return '{"count":"x"}';
				}

				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value, int $ttl) use (&$store, &$traffic, $env): bool {
				$traffic[] = 'set '.$key.' '.$value.' '.$ttl;
				if ($env['cache'] === 'set-throws') {
					throw new RuntimeException('set failed');
				}

				$store[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		if ($env['cache'] === 'factory-throws') {
			$factory->method('createDistributed')->willThrowException(new RuntimeException('no backend'));
		} else {
			$factory->method('createDistributed')->willReturn($cache);
		}

		$config = $this->createMock(IAppConfig::class);
		if ($env['config'] === null) {
			$config->method('getValueString')->willThrowException(new RuntimeException('config down'));
		} else {
			$config->method('getValueString')->willReturn($env['config']);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function ($message) use (&$log, $level): void {
					$log[] = $level.': '.$message;
				}
			);
		}

		$coalescer = new NotificationCoalescer($factory, $config, $logger, static function () use (&$clock): int {
			return $clock;
		});

		$answers = [];
		foreach (self::script() as [$rule, $recipient, $override, $now]) {
			$clock     = $now;
			$answers[] = (int) $coalescer->shouldDispatch(new CoalesceBucket($rule, $recipient), $override);
		}

		$inspect = [];
		foreach ([['r', 'alice'], ['r', 'bob'], ['s', 'bob'], ['r', 'nobody'], ['', 'bob'], ['r', '']] as [$rule, $recipient]) {
			$inspect[$rule.'/'.$recipient] = $coalescer->inspect($rule, $recipient);
		}

		return ['answers' => implode('', $answers), 'inspect' => $inspect, 'store' => $store, 'traffic' => $traffic, 'log' => $log];
	}//end play()

	/**
	 * Everything pinned, per environment.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(): array {
		$out = [];
		foreach (self::environments() as $name => $env) {
			$out[$name] = $this->play(env: $env);
		}

		return $out;
	}//end observe()

	/**
	 * The coalescer still produces the recorded observations.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden pins both answers and real state, not silence.
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertStringContainsString('0', $golden['default']['answers']);
		$this->assertStringContainsString('1', $golden['default']['answers']);
		$this->assertNotSame([], $golden['default']['store']);
		$this->assertNotNull($golden['default']['inspect']['r/bob']);
		$this->assertNotSame([], $golden['set-throws']['log']);
	}//end testGoldenIsNotTrivial()
}//end class
