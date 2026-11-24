<?php

/**
 * The database-pressure meter: the request minimum, the slow and error
 * shares, the window, the administrator's switch, and failing open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCA\OpenRegister\Service\Resilience\DatabasePressureMeter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Resilience\DatabasePressureMeter
 */
class DatabasePressureMeterTest extends TestCase {

	private FakeMemcache $cache;

	private int $now = 1_000_000;

	/** @var array<string, int|bool> Appconfig values set by a test. */
	private array $config = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new FakeMemcache();
	}

	private function meter(bool $distributed = true): DatabasePressureMeter {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => (int) ($this->config[$key] ?? $default));
		$appConfig->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default): bool => (bool) ($this->config[$key] ?? $default));

		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($distributed);
		$factory->method('createDistributed')->willReturn($this->cache);
		$factory->method('createLocal')->willReturn($this->cache);

		return new DatabasePressureMeter(cacheFactory: $factory, appConfig: $appConfig, clock: $clock, logger: new NullLogger());
	}

	private static function requests(DatabasePressureMeter $meter, int $count, float $seconds = 0.1, bool $error = false): void {
		for ($i = 0; $i < $count; $i++) {
			$meter->record(seconds: $seconds, databaseError: $error);
		}
	}

	public function testAFifthSlowRequestsIsPressure(): void {
		$meter = $this->meter();
		self::requests($meter, 40);
		self::requests($meter, 10, seconds: 2.5);

		$this->assertTrue($meter->underPressure());
		$state = $meter->state();
		$this->assertSame(50, $state['requests']);
		$this->assertSame(10, $state['slow']);
		$this->assertSame(0, $state['errors']);
		$this->assertTrue($state['underPressure']);
		$this->assertTrue($state['shared']);
	}

	public function testJustUnderAFifthSlowIsNoPressure(): void {
		$meter = $this->meter();
		self::requests($meter, 41);
		self::requests($meter, 9, seconds: 2.5);

		$this->assertFalse($meter->underPressure());
	}

	public function testATwoSecondRequestIsNotSlow(): void {
		$meter = $this->meter();
		self::requests($meter, 50, seconds: 2.0);

		$this->assertSame(0, $meter->state()['slow']);
		$this->assertFalse($meter->underPressure());
	}

	public function testATenthDatabaseErrorsIsPressure(): void {
		$meter = $this->meter();
		self::requests($meter, 45);
		self::requests($meter, 5, error: true);

		$this->assertTrue($meter->underPressure());
	}

	public function testFewerThanFiftyRequestsIsNeverPressure(): void {
		$meter = $this->meter();
		self::requests($meter, 49, seconds: 5.0, error: true);

		$this->assertFalse($meter->underPressure());
	}

	public function testCountsOlderThanTheWindowNoLongerCount(): void {
		$meter = $this->meter();
		self::requests($meter, 50, seconds: 5.0);
		$this->assertTrue($meter->underPressure());

		$this->now += 70;
		$this->assertFalse($meter->underPressure());
		$this->assertSame(0, $meter->state()['requests']);
	}

	public function testTheAdministratorsSwitchTurnsSheddingOff(): void {
		$this->config['load_shedding_pressure_enabled'] = false;
		$meter = $this->meter();
		self::requests($meter, 50, seconds: 5.0);

		$this->assertFalse($meter->underPressure());
		$state = $meter->state();
		$this->assertFalse($state['enabled']);
		$this->assertFalse($state['underPressure']);
		$this->assertSame(50, $state['slow'], 'The meter still counts, so the console can show it.');
	}

	public function testAdministeredThresholdsApplyAndAnOutOfRangeOneFallsBack(): void {
		$this->config['load_shedding_pressure_min_requests'] = 5;
		$this->config['load_shedding_pressure_slow_percent'] = 500;
		$meter = $this->meter();
		self::requests($meter, 4);
		self::requests($meter, 1, seconds: 3.0);

		$this->assertTrue($meter->underPressure(), '5 requests reach the administered minimum; 1 of 5 slow reaches the default 20%.');
	}

	public function testABrokenCacheNeverReadsAsPressure(): void {
		$meter = $this->meter();
		self::requests($meter, 50, seconds: 5.0);
		$this->cache->broken = true;

		self::requests($meter, 5, seconds: 5.0);
		$this->assertFalse($meter->underPressure());
	}

	public function testWithoutADistributedCacheTheStateSaysPerWorker(): void {
		$this->assertFalse($this->meter(distributed: false)->state()['shared']);
	}
}
