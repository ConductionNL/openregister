<?php

/**
 * MagicUnaccentProbeTest: when accent folding is available, and why not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/search-accent-insensitive/tasks.md#1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe;
use OCA\OpenRegister\Db\MagicMapper\SearchFolding;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(MagicUnaccentProbe::class)]
#[UsesClass(SearchFolding::class)]
final class MagicUnaccentProbeTest extends TestCase {

	/**
	 * A PostgreSQL connection answering the wrapper count once.
	 *
	 * @param mixed $count The fetched count.
	 *
	 * @return IDBConnection
	 */
	private function postgres(mixed $count): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($count);
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->once())
			->method('prepare')
			->with("SELECT COUNT(*) FROM pg_proc WHERE proname = 'openregister_fold'")
			->willReturn($statement);

		return $db;
	}//end postgres()

	public function testPostgresWithTheWrapperFoldsAndAsksOnce(): void {
		$probe = new MagicUnaccentProbe($this->postgres(1), $this->createMock(LoggerInterface::class));

		$this->assertTrue($probe->available());
		$this->assertSame('', $probe->reason());
		$this->assertSame(
			'openregister_fold(c::text) LIKE openregister_fold(p)',
			$probe->folding(wanted: true)->match(quotedColumn: 'c', quotedPattern: 'p', nullSafe: false)
		);
		$this->assertSame('c::text ILIKE p', $probe->folding(wanted: false)->match(quotedColumn: 'c', quotedPattern: 'p', nullSafe: false));
	}//end testPostgresWithTheWrapperFoldsAndAsksOnce()

	public function testPostgresWithoutTheWrapperNamesTheExtensionAndStaysExact(): void {
		$probe = new MagicUnaccentProbe($this->postgres('0'), $this->createMock(LoggerInterface::class));

		$this->assertFalse($probe->available());
		$this->assertSame(MagicUnaccentProbe::REASON_NO_EXTENSION, $probe->reason());
		$this->assertStringContainsString('unaccent', $probe->reason());
		$this->assertFalse($probe->folding(wanted: true)->isFolded());
	}//end testPostgresWithoutTheWrapperNamesTheExtensionAndStaysExact()

	public function testAFailedCheckIsLoggedAndCountsAsUnavailable(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->once())->method('prepare')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[MagicMapper] Failed to check accent folding availability', ['error' => 'down']);

		$probe = new MagicUnaccentProbe($db, $logger);

		$this->assertFalse($probe->available());
		$this->assertSame(MagicUnaccentProbe::REASON_NO_EXTENSION, $probe->reason());
	}//end testAFailedCheckIsLoggedAndCountsAsUnavailable()

	public function testMariadbFoldsThroughTheCollationWithoutAsking(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$db->expects($this->never())->method('prepare');

		$probe = new MagicUnaccentProbe($db, $this->createMock(LoggerInterface::class));

		$this->assertTrue($probe->available());
		$this->assertSame(
			'CAST(c AS CHAR) COLLATE utf8mb4_unicode_ci LIKE p COLLATE utf8mb4_unicode_ci',
			$probe->folding(wanted: true)->match(quotedColumn: 'c', quotedPattern: 'p', nullSafe: false)
		);
	}//end testMariadbFoldsThroughTheCollationWithoutAsking()

	public function testAnotherPlatformIsUnsupported(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());
		$db->expects($this->never())->method('prepare');

		$probe = new MagicUnaccentProbe($db, $this->createMock(LoggerInterface::class));

		$this->assertFalse($probe->available());
		$this->assertSame(MagicUnaccentProbe::REASON_UNSUPPORTED, $probe->reason());
		$this->assertFalse($probe->folding(wanted: true)->isFolded());
	}//end testAnotherPlatformIsUnsupported()
}//end class
