<?php

/**
 * MagicDecimalWidenerMySqlTest: widening a decimal column on MySQL restates its nullability and numeric default.
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

use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner;
use OCA\OpenRegister\Db\MagicMapper\MagicDecimalWidener;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicDecimalWidener
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberWidening
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicDecimalWidenerMySqlTest extends TestCase {

	/**
	 * Statements run.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * The widener on a recording MySQL connection.
	 *
	 * @return MagicDecimalWidener
	 */
	private function widener(): MagicDecimalWidener {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);

		return new MagicDecimalWidener(new MagicDdlRunner($db, $this->createMock(LoggerInterface::class)), new MagicNumberColumnSizes($db, 'oc_'));
	}//end widener()

	public function testANonDecimalColumnIsNeverWidened(): void {
		$drift = new MagicColumnDrift(['amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2]], ['amount' => ['type' => 'string']]);

		$this->assertSame([], $this->widener()->widenings($drift));
	}//end testANonDecimalColumnIsNeverWidened()

	public function testOnMySqlTheWideningRestatesNotNullAndANumericDefault(): void {
		$current = [
			'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false, 'default' => '0.00'],
			'rate' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'default' => 'none'],
		];
		$required = [
			'amount' => ['type' => 'decimal', 'precision' => 20, 'scale' => 6],
			'rate' => ['type' => 'decimal', 'precision' => 20, 'scale' => 6],
		];
		$sync = new TableStructureSync('openregister_table_1_2', 'oc_openregister_table_1_2', new MagicColumnDrift($current, $required), false);

		$this->assertSame(['amount', 'rate'], $this->widener()->widen($sync));
		$this->assertSame(
			[
				'ALTER TABLE `oc_openregister_table_1_2` MODIFY `amount` DECIMAL(20,6) NOT NULL DEFAULT 0.00',
				'ALTER TABLE `oc_openregister_table_1_2` MODIFY `rate` DECIMAL(20,6) NULL',
			],
			$this->statements
		);
	}//end testOnMySqlTheWideningRestatesNotNullAndANumericDefault()
}//end class
