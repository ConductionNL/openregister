<?php

/**
 * MagicNumberColumnSizesCacheTest: live decimal sizes are read once per table and forgotten after a widening.
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

use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 */
class MagicNumberColumnSizesCacheTest extends TestCase {

	/**
	 * Times the information schema was read.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * A connection answering one decimal column per read.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			function (): IPreparedStatement {
				$this->reads++;
				$rows = [['col' => 'amount', 'numeric_precision' => '20', 'numeric_scale' => 6 + $this->reads]];
				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('fetch')->willReturnCallback(
					static function () use (&$rows) {
						return (array_shift($rows) ?? false);
					}
				);
				return $stmt;
			}
		);

		return $db;
	}//end connection()

	public function testASecondAskForTheSameTableIsAnsweredFromMemory(): void {
		$sizes = new MagicNumberColumnSizes($this->connection(), 'oc_');
		$table = 'openregister_table_cache_' . uniqid();

		$first = $sizes->sizes($table);
		$second = $sizes->sizes(strtoupper($table));

		$this->assertSame(['amount' => ['precision' => 20, 'scale' => 7]], $first);
		$this->assertSame($first, $second);
		$this->assertSame(1, $this->reads);
	}//end testASecondAskForTheSameTableIsAnsweredFromMemory()

	public function testForgetMakesTheNextAskReadTheTableAgain(): void {
		$sizes = new MagicNumberColumnSizes($this->connection(), 'oc_');
		$table = 'openregister_table_forget_' . uniqid();
		$sizes->sizes($table);

		$sizes->forget('OC_' . $table);

		$this->assertSame(['amount' => ['precision' => 20, 'scale' => 8]], $sizes->sizes($table));
		$this->assertSame(2, $this->reads);
	}//end testForgetMakesTheNextAskReadTheTableAgain()
}//end class
