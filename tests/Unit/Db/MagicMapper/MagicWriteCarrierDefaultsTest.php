<?php

/**
 * MagicWriteCarrierDefaultsTest: what a row write and a bulk-save group assume when the caller says nothing.
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

use OCA\OpenRegister\Db\MagicMapper\MagicBulkSaveGroup;
use OCA\OpenRegister\Db\MagicMapper\MagicRowWrite;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Both classes only carry data between the save steps of MagicMapper; what
 * they decide is their defaults, which the save paths rely on.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicBulkSaveGroup
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicWriteCarrierDefaultsTest extends TestCase {

	public function testABulkGroupReadsThePreUpdateStateUnlessToldNotTo(): void {
		$this->assertTrue((new MagicBulkSaveGroup([['uuid' => 'u1']]))->needsPreUpdateState);
		$this->assertFalse((new MagicBulkSaveGroup([], false))->needsPreUpdateState);
	}//end testABulkGroupReadsThePreUpdateStateUnlessToldNotTo()

	public function testABulkGroupStartsWithoutARegisterOrSchema(): void {
		$group = new MagicBulkSaveGroup([['uuid' => 'u1']]);

		$this->assertNull($group->register);
		$this->assertNull($group->schema);
	}//end testABulkGroupStartsWithoutARegisterOrSchema()

	public function testARowWriteUpsertsAndHasNoRowOrIdentityYet(): void {
		$write = new MagicRowWrite(['title' => 'x'], new Register(), new Schema(), 'openregister_table_1_2');

		$this->assertFalse($write->failIfExists);
		$this->assertNull($write->insertedId);
		$this->assertSame([], $write->row);
		$this->assertSame('', $write->uuid);
	}//end testARowWriteUpsertsAndHasNoRowOrIdentityYet()
}//end class
