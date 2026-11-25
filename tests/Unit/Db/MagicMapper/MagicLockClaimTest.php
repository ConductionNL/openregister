<?php

/**
 * MagicLockClaim writes a lock with one conditional UPDATE, compared as JSON on
 * each platform, and reports success only when exactly one row changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicLockClaim;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MagicLockClaim.
 */
#[CoversClass(MagicLockClaim::class)]
class MagicLockClaimTest extends TestCase {

	/**
	 * The statement as built.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * A claim over a recording connection.
	 *
	 * @param string       $platform The database provider.
	 * @param int          $rows     The rows the UPDATE changes.
	 * @param string|false $stored   What a read returns.
	 *
	 * @return MagicLockClaim
	 */
	private function claim(string $platform, int $rows=1, string|false $stored=false): MagicLockClaim {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $value): string => $column.' = '.$value);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$params = 0;
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$params): string {
				$params++;
				$this->trace[] = ':p'.$params.'='.$value;
				return ':p'.$params;
			}
		);
		foreach (['update', 'set', 'where', 'andWhere', 'select', 'from'] as $verb) {
			$qb->method($verb)->willReturnCallback(
				function (...$args) use ($qb, $verb): IQueryBuilder {
					$this->trace[] = $verb.' '.implode(' ', array_filter($args, static fn ($arg): bool => $arg !== null && $arg !== ''));
					return $qb;
				}
			);
		}

		$qb->method('executeStatement')->willReturn($rows);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($stored);
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		// Answer the way Nextcloud does: MariaDB is reported as `mysql` unless the
		// caller asks in strict mode.
		$db->method('getDatabaseProvider')->willReturnCallback(
			static fn (bool $strict = false): string => ($platform === IDBConnection::PLATFORM_MARIADB && $strict === false) ? IDBConnection::PLATFORM_MYSQL : $platform
		);

		return new MagicLockClaim(db: $db);
	}//end claim()

	/**
	 * Each platform's "unlocked" and "still this lock" condition.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function platforms(): array {
		return [
			'postgres' => [IDBConnection::PLATFORM_POSTGRES, "(_locked IS NULL OR _locked::jsonb IN ('null'::jsonb, '[]'::jsonb, '{}'::jsonb))", '_locked::jsonb = CAST(:p3 AS jsonb)'],
			'mysql' => [IDBConnection::PLATFORM_MYSQL, "(_locked IS NULL OR CAST(_locked AS JSON) IN (CAST('null' AS JSON), CAST('[]' AS JSON), CAST('{}' AS JSON)))", 'CAST(_locked AS JSON) = CAST(:p3 AS JSON)'],
			'mariadb' => [IDBConnection::PLATFORM_MARIADB, "(_locked IS NULL OR JSON_COMPACT(_locked) IN ('null', '[]', '{}'))", 'JSON_COMPACT(_locked) = JSON_COMPACT(:p3)'],
			'sqlite' => [IDBConnection::PLATFORM_SQLITE, "(_locked IS NULL OR json(_locked) IN ('null', '[]', '{}'))", 'json(_locked) = json(:p3)'],
		];
	}//end platforms()

	#[DataProvider('platforms')]
	public function testAnUnlockedRowIsClaimedOnlyWhileItIsStillUnlocked(string $platform, string $unlocked, string $held): void {
		$this->assertTrue($this->claim(platform: $platform)->swap('openregister_table_1_2', 'u-1', null, ['user' => 'a']));

		$this->assertSame(
			[
				'update openregister_table_1_2',
				':p1={"user":"a"}',
				'set _locked :p1',
				':p2=u-1',
				'where _uuid = :p2',
				'andWhere '.$unlocked,
			],
			$this->trace
		);
	}//end testAnUnlockedRowIsClaimedOnlyWhileItIsStillUnlocked()

	public function testMariaDbGetsItsOwnConditionNotTheMySqlCast(): void {
		// MariaDB has no CAST(... AS JSON); the MySQL condition fails there with
		// SQLSTATE 42000 and every lock is refused. Nextcloud only reports
		// `mariadb` in strict mode.
		$this->assertTrue($this->claim(platform: IDBConnection::PLATFORM_MARIADB)->swap('t', 'u-1', null, ['user' => 'a']));

		$this->assertStringContainsString('JSON_COMPACT(_locked)', end($this->trace));
		$this->assertStringNotContainsString('AS JSON', implode("\n", $this->trace));
	}//end testMariaDbGetsItsOwnConditionNotTheMySqlCast()

	#[DataProvider('platforms')]
	public function testAHeldRowIsReplacedOnlyWhileItHoldsTheLockThatWasRead(string $platform, string $unlocked, string $held): void {
		$this->assertTrue($this->claim(platform: $platform)->swap('openregister_table_1_2', 'u-1', ['user' => 'a'], ['user' => 'b']));

		$this->assertSame(':p3={"user":"a"}', $this->trace[5]);
		$this->assertSame('andWhere '.$held, $this->trace[6]);
	}//end testAHeldRowIsReplacedOnlyWhileItHoldsTheLockThatWasRead()

	public function testAnUpdateThatChangesNoRowOrMoreThanOneIsNoClaim(): void {
		$this->assertFalse($this->claim(platform: IDBConnection::PLATFORM_POSTGRES, rows: 0)->swap('t', 'u-1', null, ['user' => 'a']));
		$this->assertFalse($this->claim(platform: IDBConnection::PLATFORM_POSTGRES, rows: 2)->swap('t', 'u-1', null, ['user' => 'a']));
	}//end testAnUpdateThatChangesNoRowOrMoreThanOneIsNoClaim()

	public function testTheCurrentLockIsReadFreshAndAnEmptyOneIsNone(): void {
		$this->assertSame(['user' => 'b'], $this->claim(platform: IDBConnection::PLATFORM_SQLITE, stored: '{"user":"b"}')->current('t', 'u-1'));
		$this->assertNull($this->claim(platform: IDBConnection::PLATFORM_SQLITE, stored: 'null')->current('t', 'u-1'));
		$this->assertNull($this->claim(platform: IDBConnection::PLATFORM_SQLITE, stored: '[]')->current('t', 'u-1'));
		$this->assertNull($this->claim(platform: IDBConnection::PLATFORM_SQLITE, stored: false)->current('t', 'u-1'), 'no row');
	}//end testTheCurrentLockIsReadFreshAndAnEmptyOneIsNone()
}//end class
