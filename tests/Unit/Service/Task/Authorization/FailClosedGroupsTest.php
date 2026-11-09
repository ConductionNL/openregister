<?php

/**
 * Tests for FailClosedGroups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task\Authorization;

use OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A missing or failing group backend grants nothing.
 *
 * @covers \OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups
 */
class FailClosedGroupsTest extends TestCase {

	/**
	 * The backend's answer is passed through when it works.
	 *
	 * @return void
	 */
	public function testWorkingBackendAnswers(): void {
		$backend = $this->createMock(IGroupManager::class);
		$backend->method('isAdmin')->willReturnMap([['root', true], ['alice', false]]);
		$backend->method('isInGroup')->willReturnMap([['alice', 'staff', true], ['alice', 'hr', false]]);
		$backend->method('groupExists')->willReturnMap([['staff', true], ['ghost', false]]);
		$groups = new FailClosedGroups(groupManager: $backend);

		$this->assertTrue($groups->isAdmin(uid: 'root'));
		$this->assertFalse($groups->isAdmin(uid: 'alice'));
		$this->assertTrue($groups->isInGroup(uid: 'alice', groupId: 'staff'));
		$this->assertFalse($groups->isInGroup(uid: 'alice', groupId: 'hr'));
		$this->assertNull($groups->roleProblem(role: 'staff'));
		$this->assertSame("role 'ghost' does not resolve to any group.", $groups->roleProblem(role: 'ghost'));
	}//end testWorkingBackendAnswers()

	/**
	 * An empty group id is never asked about.
	 *
	 * @return void
	 */
	public function testEmptyGroupIdIsNotMembership(): void {
		$backend = $this->createMock(IGroupManager::class);
		$backend->expects($this->never())->method('isInGroup');

		$this->assertFalse((new FailClosedGroups(groupManager: $backend))->isInGroup(uid: 'alice', groupId: ''));
	}//end testEmptyGroupIdIsNotMembership()

	/**
	 * A throwing backend grants nothing and makes every role unresolvable.
	 *
	 * @return void
	 */
	public function testFailingBackendFailsClosed(): void {
		$backend = $this->createMock(IGroupManager::class);
		$backend->method('isAdmin')->willThrowException(new RuntimeException('ldap'));
		$backend->method('isInGroup')->willThrowException(new RuntimeException('ldap'));
		$backend->method('groupExists')->willThrowException(new RuntimeException('ldap'));
		$groups = new FailClosedGroups(groupManager: $backend);

		$this->assertFalse($groups->isAdmin(uid: 'root'));
		$this->assertFalse($groups->isInGroup(uid: 'alice', groupId: 'staff'));
		$this->assertSame("role 'staff' does not resolve to any group.", $groups->roleProblem(role: 'staff'));
	}//end testFailingBackendFailsClosed()

	/**
	 * No backend at all grants nothing and names the reason.
	 *
	 * @return void
	 */
	public function testNoBackendFailsClosed(): void {
		$groups = new FailClosedGroups(groupManager: null);

		$this->assertFalse($groups->isAdmin(uid: 'root'));
		$this->assertFalse($groups->isInGroup(uid: 'alice', groupId: 'staff'));
		$this->assertSame(
			"role 'staff' cannot be resolved because no group backend is available.",
			$groups->roleProblem(role: 'staff')
		);
	}//end testNoBackendFailsClosed()
}//end class
