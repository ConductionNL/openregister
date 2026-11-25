<?php

/**
 * A group manager double whose administrator answer follows the groups it reports.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCP\IUser;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Administrator verdicts now come from `IGroupManager::isAdmin()` (so Nextcloud's
 * `allowed_admin_ranges` applies). Tests written against the old group lookup set
 * up their caller through `getUserGroupIds()`; this keeps that setup meaningful by
 * answering `isAdmin()` from the same groups, as Nextcloud does inside the range.
 */
trait AdminFollowsGroups {

	/**
	 * Answer `isAdmin($uid)` with whether the double's groups for that user include `admin`.
	 *
	 * @param Stub $groupManager The IGroupManager double.
	 *
	 * @return void
	 */
	private function adminFollowsGroups(Stub $groupManager): void {
		$groupManager->method('isAdmin')->willReturnCallback(
			function (string $uid) use ($groupManager): bool {
				$user = $this->createStub(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return in_array('admin', (array) $groupManager->getUserGroupIds($user), true);
			}
		);
	}//end adminFollowsGroups()
}//end trait
