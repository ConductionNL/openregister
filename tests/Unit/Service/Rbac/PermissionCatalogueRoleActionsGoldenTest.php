<?php

/**
 * Golden test pinning which role definitions PermissionCatalogue::unknownActionsInRoles() reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Known verbs pass, unknown string verbs are reported per role, everything malformed is skipped.
 */
class PermissionCatalogueRoleActionsGoldenTest extends TestCase {

	/**
	 * A role naming only grantable verbs is allowed: nothing is reported.
	 *
	 * @return void
	 */
	public function testARoleOfKnownVerbsIsAllowed(): void {
		$this->assertSame(
			[],
			(new PermissionCatalogue())->unknownActionsInRoles([['name' => 'lezer', 'actions' => ['read', 'update']]])
		);
	}//end testARoleOfKnownVerbsIsAllowed()

	/**
	 * Unknown verbs are refused per role, keyed by name or index, in order; malformed entries are skipped.
	 *
	 * @return void
	 */
	public function testUnknownVerbsAreReportedPerRole(): void {
		$definitions = [
			['name' => 'archivaris', 'actions' => ['read', 'obliterate', 42, 'shred']],
			'not a definition',
			['actions' => ['zap']],
			['name' => 'leeg'],
			['name' => 'kapot', 'actions' => 'destroy'],
			['name' => 'goed', 'actions' => ['read']],
		];

		$this->assertSame(
			['archivaris' => ['obliterate', 'shred'], '2' => ['zap']],
			(new PermissionCatalogue())->unknownActionsInRoles($definitions)
		);
		$this->assertSame([], (new PermissionCatalogue())->unknownActionsInRoles('roles'));
	}//end testUnknownVerbsAreReportedPerRole()
}//end class
