<?php

/**
 * MagicUnionArm casts and pads union columns in the platform's own syntax.
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

use OCA\OpenRegister\Db\MagicMapper\MagicUnionArm;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionArm
 */
class MagicUnionArmTest extends TestCase {

	/**
	 * An arm on the given platform.
	 *
	 * @param bool $isPostgres Whether the arm is on PostgreSQL.
	 *
	 * @return MagicUnionArm
	 */
	private function arm(bool $isPostgres): MagicUnionArm {
		$arm = new MagicUnionArm('oc_openregister_table_1_2', ['_limit' => 5], $this->createMock(Schema::class), $this->createMock(Register::class));
		$arm->isPostgres = $isPostgres;
		return $arm;
	}//end arm()

	/**
	 * PostgreSQL casts with the :: operator.
	 *
	 * @return void
	 */
	public function testPostgresCastsWithTheColonOperator(): void {
		$arm = $this->arm(true);

		$this->assertSame('_uuid::text AS _uuid', $arm->castAsText('_uuid'));
		$this->assertSame('NULL::text AS title', $arm->nullAs('title'));
	}//end testPostgresCastsWithTheColonOperator()

	/**
	 * Other platforms cast with CAST(... AS CHAR) and pad with a bare NULL.
	 *
	 * @return void
	 */
	public function testOtherPlatformsCastAsChar(): void {
		$arm = $this->arm(false);

		$this->assertSame('CAST(_uuid AS CHAR) AS _uuid', $arm->castAsText('_uuid'));
		$this->assertSame('NULL AS title', $arm->nullAs('title'));
	}//end testOtherPlatformsCastAsChar()

	/**
	 * A new arm defaults to the full, non-PostgreSQL, column-less shape and keeps its query.
	 *
	 * @return void
	 */
	public function testANewArmStartsEmpty(): void {
		$arm = new MagicUnionArm('t', ['_limit' => 5], $this->createMock(Schema::class), $this->createMock(Register::class));

		$this->assertFalse($arm->isPostgres);
		$this->assertFalse($arm->metadataOnly);
		$this->assertSame([], $arm->allPropertyColumns);
		$this->assertSame([], $arm->existingColumns);
		$this->assertSame(['_limit' => 5], $arm->query);
		$this->assertSame('CAST(x AS CHAR) AS x', $arm->castAsText('x'));
	}//end testANewArmStartsEmpty()
}//end class
