<?php

/**
 * MagicMetadataColumnHealerBehaviourTest: the healer's unreadable-table,
 * open-transaction, primary-key and cache-failure paths.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCP\IConfig;
use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\ICache;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 */
class MagicMetadataColumnHealerBehaviourTest extends TestCase {

	private const TABLE = 'oc_openregister_table_1_2';

	/**
	 * The connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * The mapper.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $mapper;

	/**
	 * Statements run.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * Log lines, as `level message`.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	protected function setUp(): void {
		MagicMetadataColumnHealer::reset();
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->mapper->method('getMetadataColumnDefinitions')->willReturn(
			[
				['name' => '_id', 'type' => 'bigint', 'primary' => true],
				['name' => '_slug', 'type' => 'string'],
				['name' => '_tags', 'type' => 'json'],
				['type' => 'string'],
			]
		);
		$this->mapper->method('buildAddColumnSql')->willReturnCallback(
			static fn (string $table, array $definition): string => 'ADD ' . $table . '.' . $definition['name']
		);
	}//end setUp()

	protected function tearDown(): void {
		MagicMetadataColumnHealer::reset();
	}//end tearDown()

	/**
	 * The healer with the given cache.
	 *
	 * @param ICache|null $cache The cache.
	 *
	 * @return MagicMetadataColumnHealer
	 */
	private function healer(?ICache $cache = null): MagicMetadataColumnHealer {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message) use ($level): void {
					$this->log[] = $level . ' ' . $message;
				}
			);
		}

		return new MagicMetadataColumnHealer(new MagicTableSql($this->db, $this->createMock(IConfig::class), $logger), $this->mapper, $cache);
	}//end healer()

	/**
	 * A cache whose every call throws.
	 *
	 * @return ICache
	 */
	private function brokenCache(): ICache {
		$cache = $this->createMock(ICache::class);
		foreach (['get', 'set', 'remove'] as $method) {
			$cache->method($method)->willThrowException(new RuntimeException('cache down'));
		}

		return $cache;
	}//end brokenCache()

	public function testAnUnreadableTableIsNotGuessedAtNorRemembered(): void {
		$this->mapper->expects($this->exactly(2))->method('getExistingTableColumns')->willThrowException(new RuntimeException('no such table'));
		$healer = $this->healer();

		$this->assertSame([], $healer->ensure(self::TABLE));
		$this->assertSame([], $healer->ensure(self::TABLE));
		$this->assertSame([], $this->statements);
		$this->assertSame('debug [MagicMetadataColumnHealer] Could not read columns of ' . self::TABLE . ': no such table', $this->log[0]);
	}//end testAnUnreadableTableIsNotGuessedAtNorRemembered()

	public function testACompleteTableIsRememberedAsHealthyInTheCache(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_id' => [], '_slug' => [], '_tags' => []]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())->method('remove')->with($this->stringStartsWith('meta_fail_'));
		$cache->expects($this->once())->method('set')->with($this->stringStartsWith('meta_ok_'), true, MagicMetadataColumnHealer::HEALTHY_TTL);

		$this->assertSame([], $this->healer($cache)->ensure(self::TABLE));
		$this->assertSame([], $this->statements);
	}//end testACompleteTableIsRememberedAsHealthyInTheCache()

	public function testInsideAMySqlTransactionTheMissingColumnsAreSkippedNotAdded(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_id' => []]);
		$this->db->method('inTransaction')->willReturn(true);
		$this->db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$healer = $this->healer();

		$this->assertSame(['_slug', '_tags'], $healer->ensure(self::TABLE));
		$this->assertSame([], $this->statements);
		$this->assertSame(['_slug', '_tags'], $healer->missingFor(self::TABLE));
	}//end testInsideAMySqlTransactionTheMissingColumnsAreSkippedNotAdded()

	public function testInsideAPostgresTransactionTheColumnsAreAdded(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_id' => []]);
		$this->db->method('inTransaction')->willReturn(true);
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$this->assertSame([], $this->healer()->ensure(self::TABLE));
		$this->assertSame(['ADD ' . self::TABLE . '._slug', 'ADD ' . self::TABLE . '._tags'], $this->statements);
	}//end testInsideAPostgresTransactionTheColumnsAreAdded()

	public function testAnUnknownTransactionStateDoesNotBlockTheHeal(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_id' => [], '_tags' => []]);
		$this->db->method('inTransaction')->willThrowException(new RuntimeException('no connection'));

		$this->assertSame([], $this->healer()->ensure(self::TABLE));
		$this->assertSame(['ADD ' . self::TABLE . '._slug'], $this->statements);
	}//end testAnUnknownTransactionStateDoesNotBlockTheHeal()

	public function testAMissingPrimaryKeyIsReportedAndNeverAltered(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_slug' => [], '_tags' => []]);

		$outcome = $this->healer()->heal(self::TABLE);

		$this->assertSame(['added' => [], 'failed' => ['_id' => 'a primary key column cannot be added to an existing table']], $outcome);
		$this->assertSame([], $this->statements);
		$this->assertStringStartsWith('warning [MagicMetadataColumnHealer] Could not add metadata columns to ' . self::TABLE, $this->log[0]);
	}//end testAMissingPrimaryKeyIsReportedAndNeverAltered()

	public function testAnAutoincrementColumnCountsAsAPrimaryKey(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn([]);

		$outcome = $this->healer()->heal(self::TABLE, ['seq' => ['name' => 'seq', 'autoincrement' => true]]);

		$this->assertSame(['seq'], array_keys($outcome['failed']));
	}//end testAnAutoincrementColumnCountsAsAPrimaryKey()

	public function testAFailedAlterWhoseRereadAlsoFailsIsAFailure(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willThrowException(new RuntimeException('permission denied'));
		$this->db = $db;
		$this->mapper->method('getExistingTableColumns')->willThrowException(new RuntimeException('gone'));

		$outcome = $this->healer()->heal(self::TABLE, ['_slug' => ['name' => '_slug']]);

		$this->assertSame(['added' => [], 'failed' => ['_slug' => 'permission denied']], $outcome);
	}//end testAFailedAlterWhoseRereadAlsoFailsIsAFailure()

	public function testABrokenCacheOnlyCostsAReinspection(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_id' => [], '_slug' => [], '_tags' => []]);
		$healer = $this->healer($this->brokenCache());

		$this->assertSame([], $healer->ensure(self::TABLE));
		$healer->forget(self::TABLE);
		$this->assertSame([], $healer->missingFor(self::TABLE));
	}//end testABrokenCacheOnlyCostsAReinspection()

	public function testABrokenCacheDoesNotStopAFailureFromBeingRemembered(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_slug' => [], '_tags' => []]);
		$healer = $this->healer($this->brokenCache());

		$healer->heal(self::TABLE);

		$this->assertSame(['_id'], $healer->missingFor(self::TABLE));
	}//end testABrokenCacheDoesNotStopAFailureFromBeingRemembered()

	public function testForgetDropsTheRememberedAnswerAndBothCacheKeys(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_slug' => [], '_tags' => []]);
		$cache = $this->createMock(ICache::class);
		$removed = [];
		$cache->method('remove')->willReturnCallback(
			static function (string $key) use (&$removed): bool {
				$removed[] = $key;
				return true;
			}
		);
		$healer = $this->healer($cache);
		$healer->heal(self::TABLE);
		$this->assertSame(['_id'], $healer->missingFor(self::TABLE));

		$healer->forget(self::TABLE);

		$this->assertSame([], $healer->missingFor(self::TABLE));
		$version = $healer->version();
		$this->assertSame(['meta_ok_' . $version . '_' . self::TABLE, 'meta_fail_' . $version . '_' . self::TABLE], $removed);
	}//end testForgetDropsTheRememberedAnswerAndBothCacheKeys()

	public function testForgetWithoutACacheOnlyDropsTheProcessMemory(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_slug' => [], '_tags' => []]);
		$healer = $this->healer();
		$healer->heal(self::TABLE);

		$healer->forget(self::TABLE);

		$this->assertSame([], $healer->missingFor(self::TABLE));
	}//end testForgetWithoutACacheOnlyDropsTheProcessMemory()

	public function testARepeatedFailureIsWarnedAboutOncePerProcess(): void {
		$this->mapper->method('getExistingTableColumns')->willReturn(['_slug' => [], '_tags' => []]);
		$healer = $this->healer();

		$healer->heal(self::TABLE);
		$healer->heal(self::TABLE);

		$warnings = array_values(array_filter($this->log, static fn (string $line): bool => str_starts_with($line, 'warning ')));
		$this->assertCount(1, $warnings);
		$this->assertSame(['_id'], $healer->missingFor(self::TABLE));
	}//end testARepeatedFailureIsWarnedAboutOncePerProcess()
}//end class
