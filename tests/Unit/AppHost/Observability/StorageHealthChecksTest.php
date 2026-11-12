<?php

/**
 * The storage health checks: the database answers a trivial query, and a
 * temporary file can be obtained and written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\AppHost\Observability\StorageHealthChecks;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * StorageHealthChecks.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\StorageHealthChecks
 */
class StorageHealthChecksTest extends TestCase {

	private function checks(?IDBConnection $db = null, ?ITempManager $temp = null): StorageHealthChecks {
		return new StorageHealthChecks(($db ?? $this->createMock(IDBConnection::class)), ($temp ?? $this->createMock(ITempManager::class)));
	}//end checks()

	private function temp(string|false $path): ITempManager {
		$temp = $this->createMock(ITempManager::class);
		$temp->expects($this->once())->method('getTemporaryFile')->with('-apphost-health')->willReturn($path);

		return $temp;
	}//end temp()

	public function testTheDatabaseIsProbedWithATrivialQuery(): void {
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetch');
		$result->expects($this->once())->method('closeCursor');
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('executeQuery')->with('SELECT 1')->willReturn($result);

		$this->assertSame([true, ''], $this->checks(db: $db)->database());
	}//end testTheDatabaseIsProbedWithATrivialQuery()

	public function testAWritableTempFilePassesAndIsRemoved(): void {
		$path = tempnam(sys_get_temp_dir(), 'or-health');

		$this->assertSame([true, ''], $this->checks(temp: $this->temp($path))->filesystem());
		$this->assertFileDoesNotExist($path);
	}//end testAWritableTempFilePassesAndIsRemoved()

	public function testNoTempFileFails(): void {
		$this->assertSame([false, 'temp file unavailable'], $this->checks(temp: $this->temp(false))->filesystem());
		$this->assertSame([false, 'temp file unavailable'], $this->checks(temp: $this->temp(''))->filesystem());
	}//end testNoTempFileFails()

	public function testAnUnwritableTempPathFails(): void {
		$dir = sys_get_temp_dir().'/or-health-dir-'.bin2hex(random_bytes(4));
		mkdir($dir);

		try {
			$this->assertSame([false, 'temp write failed'], @$this->checks(temp: $this->temp($dir))->filesystem());
		} finally {
			@rmdir($dir);
		}
	}//end testAnUnwritableTempPathFails()
}//end class
