<?php

/**
 * Pins which keyword-search path ChunkMapper takes per platform and per
 * fallback flag: ranked on PostgreSQL either way, the unranked LIKE scan off
 * PostgreSQL only when the caller opts in, and an empty answer with a warning
 * otherwise.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ChunkMapperKeywordFallbackGoldenTest extends TestCase {

	/**
	 * Which path ran: the database calls made and the warnings logged.
	 *
	 * The ranked path runs raw SQL (`executeQuery`), the unranked path builds a
	 * query (`getQueryBuilder`); both are stopped by a failure here.
	 *
	 * @param AbstractPlatform $platform The connection's platform.
	 * @param bool             $fallback The caller's opt-in to the unranked scan.
	 *
	 * @return array{calls: list<string>, warnings: int, result: string}
	 */
	private function pathFor(AbstractPlatform $platform, bool $fallback): array {
		$calls = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeQuery')->willReturnCallback(
			static function () use (&$calls): never {
				$calls[] = 'executeQuery';
				throw new RuntimeException('stop');
			}
		);
		$db->method('getQueryBuilder')->willReturnCallback(
			static function () use (&$calls): never {
				$calls[] = 'getQueryBuilder';
				throw new RuntimeException('stop');
			}
		);

		$warnings = 0;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function () use (&$warnings): void {
				$warnings++;
			}
		);

		try {
			$result = json_encode((new ChunkMapper($db, $logger))->searchByKeyword(search: new KeywordChunkSearch(query: 'report', limit: 5, allowUnrankedFallback: $fallback)));
		} catch (RuntimeException $e) {
			$result = 'thrown';
		}

		return ['calls' => $calls, 'warnings' => $warnings, 'result' => (string)$result];
	}//end pathFor()

	public function testPostgresIsRankedWithOrWithoutTheFallback(): void {
		$ranked = ['calls' => ['executeQuery'], 'warnings' => 1, 'result' => '[]'];
		$this->assertSame($ranked, $this->pathFor(platform: new PostgreSQLPlatform(), fallback: false));
		$this->assertSame($ranked, $this->pathFor(platform: new PostgreSQLPlatform(), fallback: true));
	}//end testPostgresIsRankedWithOrWithoutTheFallback()

	public function testOffPostgresTheOptInTakesTheUnrankedScan(): void {
		$this->assertSame(['calls' => ['getQueryBuilder'], 'warnings' => 0, 'result' => 'thrown'], $this->pathFor(platform: new MariaDBPlatform(), fallback: true));
	}//end testOffPostgresTheOptInTakesTheUnrankedScan()

	public function testOffPostgresWithoutTheOptInNothingIsSearchedAndItWarns(): void {
		$this->assertSame(['calls' => [], 'warnings' => 1, 'result' => '[]'], $this->pathFor(platform: new MariaDBPlatform(), fallback: false));
	}//end testOffPostgresWithoutTheOptInNothingIsSearchedAndItWarns()
}//end class
