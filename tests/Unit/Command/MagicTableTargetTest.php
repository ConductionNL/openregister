<?php

/**
 * The label a magic-table command prints for a target: register and schema
 * by slug, falling back to their ids, and the table name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * MagicTableTarget.
 *
 * @covers \OCA\OpenRegister\Command\MagicTableTarget
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class MagicTableTargetTest extends TestCase {

	private function target(?string $registerSlug, ?string $schemaSlug): MagicTableTarget {
		$register = new Register();
		$register->setId(3);
		$register->setSlug($registerSlug);
		$schema = new Schema();
		$schema->setId(8);
		$schema->setSlug($schemaSlug);

		return new MagicTableTarget(register: $register, schema: $schema, tableName: 'oc_openregister_table_3_8');
	}//end target()

	public function testTheLabelNamesRegisterAndSchemaBySlug(): void {
		$this->assertSame('zaken/case (oc_openregister_table_3_8)', $this->target('zaken', 'case')->label());
	}//end testTheLabelNamesRegisterAndSchemaBySlug()

	public function testIdsStandInForMissingSlugs(): void {
		$this->assertSame('3/8 (oc_openregister_table_3_8)', $this->target(null, null)->label());
		$this->assertSame('zaken/8 (oc_openregister_table_3_8)', $this->target('zaken', null)->label());
	}//end testIdsStandInForMissingSlugs()
}//end class
