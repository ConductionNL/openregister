<?php

/**
 * Who may do what with a flow.
 *
 * FlowAccess was extracted from FlowController because that constructor had
 * reached ten parameters and PHPMD's ExcessiveParameterList was failing
 * `PHP Quality (phpmd)` on it. An extraction that only moves code is a
 * refactor nobody can check; these tests state what the three answers ARE, so
 * the seam is pinned rather than merely relocated.
 *
 * The branch that matters is `callerIsAdmin()` with no session. It fails
 * CLOSED — an unresolvable identity is not an administrator — and the caller
 * (`FlowController::nodeCatalog`) uses it to choose between the admin palette
 * and the reduced one. Failing open there would hand the wider palette to
 * precisely the request whose identity could not be established.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The flow endpoints' three authorisation answers.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowAccess
 */
class FlowAccessTest extends TestCase {

	/**
	 * The session, mocked.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The group manager, mocked.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The rights matrix, mocked.
	 *
	 * @var OpenRegisterActionAuthService&MockObject
	 */
	private OpenRegisterActionAuthService&MockObject $actionAuth;

	/**
	 * The service under test.
	 *
	 * @var FlowAccess
	 */
	private FlowAccess $access;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->actionAuth = $this->createMock(OpenRegisterActionAuthService::class);

		$this->access = new FlowAccess(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			actionAuth: $this->actionAuth
		);

	}//end setUp()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The session's user is handed back as-is, so the caller can tell an
	 * anonymous request (401) from an authenticated refusal (403).
	 *
	 * @return void
	 */
	public function testCurrentUserReturnsTheSessionUser(): void {
		$user = $this->user(uid: 'alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->assertSame($user, $this->access->currentUser());

	}//end testCurrentUserReturnsTheSessionUser()

	/**
	 * No session means null, not an exception and not a stand-in user.
	 *
	 * @return void
	 */
	public function testCurrentUserIsNullWithoutASession(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertNull($this->access->currentUser());

	}//end testCurrentUserIsNullWithoutASession()

	/**
	 * A held right is granted.
	 *
	 * @return void
	 */
	public function testMayGrantsAHeldRight(): void {
		$user = $this->user(uid: 'alice');
		$this->actionAuth->expects($this->once())
			->method('can')
			->with($user, 'flow.run')
			->willReturn(true);

		$this->assertTrue($this->access->may(user: $user, action: 'flow.run'));

	}//end testMayGrantsAHeldRight()

	/**
	 * The positive control's opposite: a right the matrix refuses is refused
	 * here too, so `may()` is not a function that always answers true.
	 *
	 * @return void
	 */
	public function testMayRefusesARightTheMatrixWithholds(): void {
		$user = $this->user(uid: 'mallory');
		$this->actionAuth->method('can')->willReturn(false);

		$this->assertFalse($this->access->may(user: $user, action: 'flow.run'));

	}//end testMayRefusesARightTheMatrixWithholds()

	/**
	 * An administrator is recognised as one.
	 *
	 * @return void
	 */
	public function testCallerIsAdminForAnAdministrator(): void {
		$this->userSession->method('getUser')->willReturn($this->user(uid: 'admin'));
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->assertTrue($this->access->callerIsAdmin());

	}//end testCallerIsAdminForAnAdministrator()

	/**
	 * A signed-in non-administrator is not one.
	 *
	 * @return void
	 */
	public function testCallerIsNotAdminForAnOrdinaryUser(): void {
		$this->userSession->method('getUser')->willReturn($this->user(uid: 'alice'));
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);

		$this->assertFalse($this->access->callerIsAdmin());

	}//end testCallerIsNotAdminForAnOrdinaryUser()

	/**
	 * THE FAIL-CLOSED BRANCH. With no session the group manager is never even
	 * asked — there is no uid to ask about — and the answer is false. The
	 * `expects(never())` is the part that matters: passing a null uid through
	 * to `isAdmin()` is the shape that would throw or, worse, match something.
	 *
	 * @return void
	 */
	public function testCallerIsNotAdminWithoutASession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects($this->never())->method('isAdmin');

		$this->assertFalse($this->access->callerIsAdmin());

	}//end testCallerIsNotAdminWithoutASession()
}//end class
