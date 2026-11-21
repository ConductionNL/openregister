<?php

/**
 * MagicPgTrgmProbeTest: whether trigram search can be used, asked once per probe.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 */
class MagicPgTrgmProbeTest extends TestCase {

	/**
	 * A Postgres connection answering the extension count.
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
		$db->expects($this->once())->method('prepare')->with("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_trgm'")->willReturn($statement);

		return $db;
	}//end postgres()

	public function testOutsidePostgresTrigramsAreNeverAvailableAndNothingIsAsked(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$db->expects($this->never())->method('prepare');

		$this->assertFalse((new MagicPgTrgmProbe($db, $this->createMock(LoggerInterface::class)))->available());
	}//end testOutsidePostgresTrigramsAreNeverAvailableAndNothingIsAsked()

	public function testAnInstalledExtensionIsAvailableAndTheAnswerIsKept(): void {
		$probe = new MagicPgTrgmProbe($this->postgres('1'), $this->createMock(LoggerInterface::class));

		$this->assertTrue($probe->available());
		$this->assertTrue($probe->available());
	}//end testAnInstalledExtensionIsAvailableAndTheAnswerIsKept()

	public function testAMissingExtensionIsNotAvailable(): void {
		$this->assertFalse((new MagicPgTrgmProbe($this->postgres(0), $this->createMock(LoggerInterface::class)))->available());
	}//end testAMissingExtensionIsNotAvailable()

	public function testAFailedCheckIsLoggedAndCountsAsUnavailable(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->method('prepare')->willThrowException(new RuntimeException('permission denied for pg_extension'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[MagicMapper] Failed to check pg_trgm extension availability',
			['error' => 'permission denied for pg_extension']
		);

		$this->assertFalse((new MagicPgTrgmProbe($db, $logger))->available());
	}//end testAFailedCheckIsLoggedAndCountsAsUnavailable()
}//end class
