<?php

/**
 * MagicColumnSqlTypesTest: the column types and identifier quoting of each platform.
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

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnSql;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 */
class MagicColumnSqlTypesTest extends TestCase {

	public function testSizedTypesUseTheColumnSizeOrTheDefault(): void {
		$sql = new MagicColumnSql(false);

		$this->assertSame('VARCHAR(255)', $sql->type('string', []));
		$this->assertSame('VARCHAR(80)', $sql->type('string', ['length' => 80]));
		$this->assertSame('DECIMAL(10,2)', $sql->type('decimal', []));
		$this->assertSame('DECIMAL(20,6)', $sql->type('decimal', ['precision' => 20, 'scale' => 6]));
	}//end testSizedTypesUseTheColumnSizeOrTheDefault()

	public function testDateTimeAndJsonDependOnThePlatform(): void {
		$mysql = new MagicColumnSql(false);
		$postgres = new MagicColumnSql(true);

		$this->assertSame('DATETIME', $mysql->type('datetime', []));
		$this->assertSame('TIMESTAMP', $postgres->type('datetime', []));
		$this->assertSame('JSON', $mysql->type('json', []));
		$this->assertSame('JSONB', $postgres->type('json', []));
	}//end testDateTimeAndJsonDependOnThePlatform()

	public function testFixedTypesAndUnknownTypesFallingBackToText(): void {
		$sql = new MagicColumnSql(true);

		$this->assertSame('BIGINT', $sql->type('bigint', []));
		$this->assertSame('JSON', $sql->type('json_ordered', []));
		$this->assertSame('TEXT', $sql->type('geometry', []));
	}//end testFixedTypesAndUnknownTypesFallingBackToText()

	public function testJsonOnMariaDbIsTextThatMustHoldJson(): void {
		$maria = new MagicColumnSql(false, true);
		$text = 'MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

		$this->assertSame($text, $maria->type('json', []));
		$this->assertSame($text, $maria->type('json_ordered', []));
		$this->assertSame(' CHECK (json_valid(`_files`))', $maria->check('json', '_files'));
		$this->assertSame(' CHECK (json_valid(`we``ird`))', $maria->check('json_ordered', 'we`ird'));
		$this->assertSame('TEXT', $maria->type('text', []));
		$this->assertSame('', $maria->check('text', '_uri'));
	}//end testJsonOnMariaDbIsTextThatMustHoldJson()

	public function testOnlyMariaDbChecksAJsonColumn(): void {
		$this->assertSame('', (new MagicColumnSql(false))->check('json', '_files'));
		$this->assertSame('', (new MagicColumnSql(true))->check('json', '_files'));
	}//end testOnlyMariaDbChecksAJsonColumn()

	/**
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	public function testOrderedJsonOnMysqlIsTextThatMustHoldJson(): void {
		$mysql = new MagicColumnSql(false, false, true);

		$this->assertSame('MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $mysql->type('json_ordered', []));
		$this->assertSame(' CHECK (json_valid(`rows`))', $mysql->check('json_ordered', 'rows'));
		$this->assertSame('JSON', $mysql->type('json', []));
		$this->assertSame('', $mysql->check('json', '_files'));
		$this->assertSame('JSON', (new MagicColumnSql(false))->type('json_ordered', []));
	}//end testOrderedJsonOnMysqlIsTextThatMustHoldJson()

	/**
	 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
	 */
	public function testOnlyMysqlReordersJsonKeys(): void {
		$this->assertTrue(MagicColumnSql::reordersJsonKeys(new MySQLPlatform()));
		$this->assertFalse(MagicColumnSql::reordersJsonKeys(new MariaDBPlatform()));
		$this->assertFalse(MagicColumnSql::reordersJsonKeys(new PostgreSQLPlatform()));
		$this->assertFalse(MagicColumnSql::reordersJsonKeys(new SqlitePlatform()));
	}//end testOnlyMysqlReordersJsonKeys()

	public function testQuotingEscapesTheQuoteCharacterOfThePlatform(): void {
		$this->assertSame('`we``ird`', (new MagicColumnSql(false))->quote('we`ird'));
		$this->assertSame('"we""ird"', (new MagicColumnSql(true))->quote('we"ird'));
	}//end testQuotingEscapesTheQuoteCharacterOfThePlatform()
}//end class
