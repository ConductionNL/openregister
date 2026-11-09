<?php

/**
 * ExactKeyHydration copies exact-key fields onto a register and skips values it cannot set.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\Register
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Register;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Register\ExactKeyHydration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Register\ExactKeyHydration
 * @uses   \OCA\OpenRegister\Db\Register
 */
class ExactKeyHydrationTest extends TestCase {

	/**
	 * A value the setter rejects is skipped and the other fields still land.
	 *
	 * @return void
	 */
	public function testARejectedValueIsSkipped(): void {
		$register = new Register();

		(new ExactKeyHydration($register))->apply(['updated' => 'not a date', 'title' => 'Zaken', 'unknownKey' => 'x', 0 => 'positional']);

		$this->assertNull($register->getUpdated());
		$this->assertSame('Zaken', $register->getTitle());
	}//end testARejectedValueIsSkipped()

	/**
	 * An empty array on a JSON field is stored as null; on other fields it is kept.
	 *
	 * @return void
	 */
	public function testAnEmptyJsonFieldBecomesNull(): void {
		$register = new Register();
		$register->setGroups(['admin']);

		(new ExactKeyHydration($register))->apply(['groups' => [], 'slug' => 'zaken']);

		$this->assertNull($register->getGroups());
		$this->assertSame('zaken', $register->getSlug());
	}//end testAnEmptyJsonFieldBecomesNull()
}//end class
