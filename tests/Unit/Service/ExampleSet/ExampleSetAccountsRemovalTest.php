<?php

/**
 * Unit test for ExampleSetAccountsRemoval: what removing a set does to its memberships, groups and accounts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsRemoval;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsRemoval
 */
class ExampleSetAccountsRemovalTest extends TestCase {
	/**
	 * What cannot be removed stays and is named; what can goes and is counted; an adopted account is only left.
	 *
	 * @return void
	 */
	public function testWhatStaysAndWhatGoes(): void {
		$accounts = $this->createMock(ExampleSetAccounts::class);
		$accounts->method('removeSubAdmin')->willReturnCallback(static fn (string $gid, string $uid): bool => $uid !== 'stuck');
		$accounts->method('removeFromGroup')->willReturnCallback(static fn (string $gid, string $uid): bool => $uid !== 'stuck');
		$accounts->method('deleteGroup')->willReturnCallback(static fn (string $gid): bool => $gid !== 'kept');
		$accounts->method('deleteUser')->willReturnCallback(static fn (string $uid): bool => $uid !== 'stuck');
		$accounts->method('groupName')->willReturnCallback(static fn (string $gid): string => ($gid === 'g1') ? 'Set name' : 'Renamed by someone');
		$accounts->expects($this->once())->method('setGroupName')->with('g1', 'Old name');
		$removal = new ExampleSetAccountsRemoval(accounts: $accounts);
		$report  = ['removed' => ['memberships' => 0, 'groups' => 0, 'accounts' => 0], 'left' => [], 'failed' => []];

		$this->assertSame([['g', 'stuck']], $removal->removeSubAdmins(pairs: [['g', 'a'], ['g', 'stuck']], report: $report));
		$this->assertSame([['g', 'stuck']], $removal->removeMembers(pairs: [['g', 'stuck'], ['g', 'b']], report: $report));
		$this->assertSame([], $removal->restoreGroupNames(names: ['g1' => ['old' => 'Old name', 'new' => 'Set name'], 'g2' => ['old' => 'x', 'new' => 'y']]));
		$this->assertSame(['kept'], $removal->deleteGroups(groups: ['g1', 'kept'], report: $report));
		$this->assertSame(['stuck' => 'created'], $removal->removeUsers(users: ['sanne' => 'adopted', 'stuck' => 'created', 'esra' => 'created'], report: $report));
		$this->assertSame(
			[
				'removed' => ['memberships' => 1, 'groups' => 1, 'accounts' => 1],
				'left'    => ['sanne'],
				'failed'  => ['sub-admin g / stuck', 'membership g / stuck', 'group kept', 'account stuck'],
			],
			$report
		);
	}//end testWhatStaysAndWhatGoes()
}//end class
