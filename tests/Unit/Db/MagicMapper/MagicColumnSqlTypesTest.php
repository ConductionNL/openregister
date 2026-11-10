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

	public function testQuotingEscapesTheQuoteCharacterOfThePlatform(): void {
		$this->assertSame('`we``ird`', (new MagicColumnSql(false))->quote('we`ird'));
		$this->assertSame('"we""ird"', (new MagicColumnSql(true))->quote('we"ird'));
	}//end testQuotingEscapesTheQuoteCharacterOfThePlatform()
}//end class
