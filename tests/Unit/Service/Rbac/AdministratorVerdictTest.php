<?php

/**
 * The access value objects take Nextcloud's administrator verdict over the group list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\Rbac\ClassificationCaller;
use PHPUnit\Framework\TestCase;

/**
 * Each value object answers "is the caller an administrator" with the verdict its
 * builder got from IGroupManager::isAdmin(), so a member of the admin group who
 * calls from outside `allowed_admin_ranges` is not one. Without a verdict the
 * group list is read as before.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @covers \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @covers \OCA\OpenRegister\Service\PropertyAccessRequest
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class AdministratorVerdictTest extends TestCase {

	/**
	 * The row-filter call: the verdict wins either way, the group list is the fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testTheRowFilterCallTakesTheVerdict(): void {
		$call = new RbacAccessCall(schema: new Schema());
		$call->userGroups = ['admin'];
		$this->assertTrue($call->callerIsAdmin());

		$call->administrator = false;
		$this->assertFalse($call->callerIsAdmin());

		$call->userGroups    = ['staff'];
		$call->administrator = true;
		$this->assertTrue($call->callerIsAdmin());
	}

	/**
	 * The object permission check: the verdict passed with the caller decides.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testThePermissionCheckTakesTheVerdict(): void {
		$outside = (new RbacPermissionCheck(schema: new Schema(), action: 'read'))->forCaller(userId: 'anna', userGroups: ['admin'], administrator: false);
		$inside  = (new RbacPermissionCheck(schema: new Schema(), action: 'read'))->forCaller(userId: 'anna', userGroups: ['staff'], administrator: true);
		$legacy  = (new RbacPermissionCheck(schema: new Schema(), action: 'read'))->forCaller(userId: 'anna', userGroups: ['admin']);

		$this->assertFalse($outside->callerIsAdmin());
		$this->assertTrue($inside->callerIsAdmin());
		$this->assertTrue($legacy->callerIsAdmin());
		$this->assertSame(['admin'], $outside->userGroups);
	}

	/**
	 * The property request: the verdict decides, and group rules still read the groups.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testThePropertyRequestTakesTheVerdict(): void {
		$request = new PropertyAccessRequest(object: []);
		$outside = $request->withUser(userId: 'anna', userGroups: ['admin'], administrator: false);
		$legacy  = $request->withUser(userId: 'anna', userGroups: ['admin']);

		$this->assertFalse($outside->isAdmin());
		$this->assertTrue($outside->qualifiesFor(group: 'admin'));
		$this->assertTrue($legacy->isAdmin());
		$this->assertFalse($request->isAdmin());
	}

	/**
	 * The classification caller: the verdict decides, the group list is the fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testTheClassificationCallerTakesTheVerdict(): void {
		$this->assertFalse((new ClassificationCaller(userId: 'anna', groups: ['admin'], action: 'read', administrator: false))->isAdministrator());
		$this->assertTrue((new ClassificationCaller(userId: 'anna', groups: [], action: 'read', administrator: true))->isAdministrator());
		$this->assertTrue((new ClassificationCaller(userId: 'anna', groups: ['admin'], action: 'read'))->isAdministrator());
		$this->assertFalse((new ClassificationCaller(userId: 'anna', groups: ['staff'], action: 'read'))->isAdministrator());
	}
}
