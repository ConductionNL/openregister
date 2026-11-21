<?php

/**
 * Golden: who may read, change, delete or run an export profile.
 *
 * Pins ExportProfileService::assertOwnerOrAdmin() before the caller's uid and
 * role travel as one value: the owner is allowed, an administrator is allowed
 * on any profile, and anybody else is refused with the `profile-not-yours`
 * rule and a 403, as is a caller whose uid is empty.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Service\Export\ExportProfileCaller;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden for the export profile ownership check.
 */
class ExportProfileOwnershipGoldenTest extends TestCase {

	/**
	 * The outcome of the check for each caller, as `allowed` or the refusal's rule and status.
	 *
	 * @return void
	 */
	public function testOwnershipOutcomesMatchGolden(): void {
		$expected = [
			'owner'               => 'allowed',
			'owner as admin'      => 'allowed',
			'administrator'       => 'allowed',
			'somebody else'       => 'refused profile-not-yours 403',
			'empty uid'           => 'refused profile-not-yours 403',
			'other case of owner' => 'refused profile-not-yours 403',
		];

		$actual = [
			'owner'               => $this->outcome(uid: 'eigenaar-1', isAdmin: false),
			'owner as admin'      => $this->outcome(uid: 'eigenaar-1', isAdmin: true),
			'administrator'       => $this->outcome(uid: 'beheerder', isAdmin: true),
			'somebody else'       => $this->outcome(uid: 'andere-gebruiker', isAdmin: false),
			'empty uid'           => $this->outcome(uid: '', isAdmin: false),
			'other case of owner' => $this->outcome(uid: 'Eigenaar-1', isAdmin: false),
		];

		$this->assertSame($expected, $actual);
	}//end testOwnershipOutcomesMatchGolden()

	/**
	 * Run the check for one caller against a profile owned by `eigenaar-1`.
	 *
	 * @param string $uid     The caller.
	 * @param bool   $isAdmin Whether the caller is an administrator.
	 *
	 * @return string
	 */
	private function outcome(string $uid, bool $isAdmin): string {
		$profile = new ExportProfile();
		$profile->setOwner('eigenaar-1');

		$service = (new ReflectionClass(ExportProfileService::class))->newInstanceWithoutConstructor();
		try {
			$service->assertOwnerOrAdmin($profile, new ExportProfileCaller($uid, $isAdmin));
		} catch (ExportRefusedException $e) {
			return 'refused '.$e->toResponseBody()['rule'].' '.$e->getStatusCode();
		}

		return 'allowed';
	}//end outcome()
}//end class
