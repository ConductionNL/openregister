<?php

/**
 * AuditHashRechainGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden: the full re-chain and the integrity summary.
 *
 * Records every lock, config, query-builder, statement and log call of a case,
 * plus the returned value or exception, and compares them with
 * fixtures/audit-hash-rechain-golden.json. Covers the refused run (lock held
 * elsewhere), a walk over several windows with tombstones that do and do not
 * carry a hash, a write that throws (the lock is still released), and the
 * integrity summary on a partly sealed, an empty and a fully unsealed trail.
 * Lock timestamps are recorded as `<time>` so no case depends on the clock.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class AuditHashRechainGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	public static function cases(): array {
		$base = fn (int $id) => ['id' => $id, 'uuid' => 'u-' . $id, 'action' => 'update', 'hash' => 'old-' . $id, 'previous_hash' => 'shared'];
		$row = fn (int $id, array $extra = []) => $extra + $base($id);
		return [
			'rechain refused' => [['call' => 'rechain', 'locked' => true, 'windows' => [[$row(1)]]]],
			'rechain empty trail' => [['call' => 'rechain', 'windows' => []]],
			'rechain windows with tombstones' => [[
				'call' => 'rechain',
				'batch' => 3,
				'windows' => [
					[$row(1), $row(2, ['purged_at' => '2026-01-01', 'hash' => 'kept-2']), $row(3)],
					[$row(5, ['purged_at' => '2026-01-02', 'hash' => '']), $row(6, ['purged_at' => '2026-01-02', 'hash' => null]), $row(7)],
					[$row(9)],
				],
			]],
			'rechain write fails' => [['call' => 'rechain', 'failWrite' => true, 'windows' => [[$row(1), $row(2)]]]],
			'status partly sealed' => [['call' => 'status', 'scalars' => [1000, 250, '987']]],
			'status empty' => [['call' => 'status', 'scalars' => [0, 0, false]]],
			'status none sealed' => [['call' => 'status', 'scalars' => [3, 3, null]]],
			'status rounding' => [['call' => 'status', 'scalars' => [3, 1, 7]]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testRechainAndStatusMatchGolden(array $case): void {
		$this->log = [];
		$service = $this->service($case);
		try {
			$result = match ($case['call']) {
				'rechain' => isset($case['batch']) === true ? $service->rechainAll($case['batch']) : $service->rechainAll(),
				default => $service->getIntegrityStatus(),
			};
		} catch (\Throwable $e) {
			$result = ['error' => [get_class($e), $e->getMessage()]];
		}

		$actual = json_decode((string)json_encode(['result' => $result, 'log' => $this->log], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), true);
		$file = __DIR__ . '/fixtures/audit-hash-rechain-golden.json';
		$name = $this->dataName();
		$golden = is_file($file) === true ? json_decode((string)file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertSame($golden[$name], $actual);
	}//end testRechainAndStatusMatchGolden()

	/**
	 * Mutation check: a re-chain that rewrote a tombstone would not match the golden.
	 *
	 * @return void
	 */
	public function testTombstoneHashIsCarriedForwardNotRewritten(): void {
		$this->log = [];
		$case = self::cases()['rechain windows with tombstones'][0];
		$service = $this->service($case);
		$service->rechainAll(3);

		$ids = [];
		foreach ($this->log as $entry) {
			if ($entry[0] === 'qb.createNamedParameter' && count($ids) < 100) {
				$ids[] = $entry[1];
			}
		}

		$this->assertNotContains(2, $ids, 'A tombstone row must never be written');
		$this->assertNotContains(5, $ids);
		$this->assertNotContains(6, $ids);
		$this->assertContains('kept-2', $ids, 'The tombstone hash is the next row\'s predecessor');
	}//end testTombstoneHashIsCarriedForwardNotRewritten()

	private function service(array $case): AuditHashService {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(
			function (string $key, int $type) use ($case): void {
				$this->log[] = ['acquireLock', $key, $type];
				if (($case['locked'] ?? false) === true) {
					throw new LockedException($key);
				}
			}
		);
		$locks->method('releaseLock')->willReturnCallback(
			function (string $key, int $type): void {
				$this->log[] = ['releaseLock', $key, $type];
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->log[] = ['setValueInt', $app, $key, $value === 0 ? 0 : '<time>'];
				return true;
			}
		);
		$config->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0): int {
				$this->log[] = ['getValueInt', $app, $key];
				return 0;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		$db = $this->createMock(IDBConnection::class);
		$state = ['windows' => $case['windows'] ?? [], 'scalars' => $case['scalars'] ?? []];
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$state, $case): IQueryBuilder {
				$this->log[] = ['getQueryBuilder'];
				return $this->queryBuilder($state, $case);
			}
		);

		return new AuditHashService($db, $locks, $logger, $config);
	}//end service()

	private function queryBuilder(array &$state, array $case): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['gt', 'eq', 'isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(fn (...$args) => $method . '(' . implode(',', array_map(fn ($a) => var_export($a, true), $args)) . ')');
		}

		$func = $this->createMock(IFunctionBuilder::class);
		foreach (['count', 'max'] as $method) {
			$func->method($method)->willReturnCallback(
				function (...$args) use ($method): IQueryFunction {
					$this->log[] = ['func.' . $method, $args];
					return $this->createMock(IQueryFunction::class);
				}
			);
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) {
				$this->log[] = ['qb.createNamedParameter', $value, $type];
				return ':p';
			}
		);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'update', 'set'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method) {
					$this->log[] = ['qb.' . $method, array_map(fn ($a) => $a instanceof IQueryFunction ? 'IQueryFunction' : $a, $args)];
					return $qb;
				}
			);
		}

		$qb->method('executeStatement')->willReturnCallback(
			function () use ($case): int {
				$this->log[] = ['executeStatement'];
				if (($case['failWrite'] ?? false) === true) {
					throw new RuntimeException('write failed');
				}

				return 1;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$this->log[] = ['executeQuery'];
				$result = $this->createMock(IResult::class);
				$rows = array_shift($state['windows']) ?? [];
				$scalar = array_shift($state['scalars']);
				$result->method('fetchAll')->willReturn($rows);
				$result->method('fetchOne')->willReturn($scalar);
				$result->method('closeCursor')->willReturnCallback(
					function (): bool {
						$this->log[] = ['closeCursor'];
						return true;
					}
				);
				return $result;
			}
		);

		return $qb;
	}//end queryBuilder()
}//end class
