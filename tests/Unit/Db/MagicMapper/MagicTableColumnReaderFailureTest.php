<?php

/**
 * MagicTableColumnReader maps information_schema rows and reports a failed read.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader
 */
class MagicTableColumnReaderFailureTest extends TestCase {

	/**
	 * A failing prepare is logged with the table and rethrown unchanged.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndRethrown(): void {
		$cause = new RuntimeException('no information_schema');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$db->method('prepare')->willThrowException($cause);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[MagicMapper] Failed to get existing table columns',
			['tableName' => 'openregister_table_1', 'error' => 'no information_schema']
		);

		try {
			(new MagicTableColumnReader($db, $logger))->read('openregister_table_1', 'oc_openregister_table_1');
			$this->fail('read() should have thrown');
		} catch (RuntimeException $e) {
			$this->assertSame($cause, $e);
		}
	}//end testAFailedReadIsLoggedAndRethrown()

	/**
	 * Rows map to definitions keyed by column name, with nullability and fallbacks decided per row.
	 *
	 * @return void
	 */
	public function testRowsMapToDefinitions(): void {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->expects($this->once())->method('execute')->with(['oc_t']);
		$statement->method('fetchAll')->willReturn([
			[
				'column_name' => 'title', 'data_type' => 'varchar', 'column_type' => 'varchar(80)', 'character_maximum_length' => 80,
				'numeric_precision' => null, 'numeric_scale' => null, 'is_nullable' => 'YES', 'column_default' => null,
			],
			[
				'column_name' => 'amount', 'data_type' => 'numeric', 'character_maximum_length' => null,
				'numeric_precision' => 12, 'numeric_scale' => 2, 'is_nullable' => 'NO', 'column_default' => '0',
			],
		]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$db->method('prepare')->willReturn($statement);

		$columns = (new MagicTableColumnReader($db, $this->createMock(LoggerInterface::class)))->read('t', 'oc_t');

		$this->assertSame(['title', 'amount'], array_keys($columns));
		$this->assertSame('varchar(80)', $columns['title']['sqlType']);
		$this->assertTrue($columns['title']['nullable']);
		$this->assertSame('numeric', $columns['amount']['sqlType']);
		$this->assertFalse($columns['amount']['nullable']);
		$this->assertSame(12, $columns['amount']['precision']);
		$this->assertSame('0', $columns['amount']['default']);
	}//end testRowsMapToDefinitions()
}//end class
