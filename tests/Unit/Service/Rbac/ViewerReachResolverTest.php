<?php

/**
 * How far a caller reaches over views, answered in one place.
 *
 * The reach used to be assembled in `ViewsController` and handed on as three
 * loose arguments, the last of them `bool $isAdmin = false`. Dropping that
 * argument anywhere along the chain still compiled and still answered a list,
 * just the narrow one; and an administrator seeing none of the instance's
 * views reads as an empty database rather than as a bug. The reach is now one
 * object built in one place, and this pins what that place answers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\Rbac\ViewerReachResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins who the resolver says is asking, and what it lets them change.
 */
class ViewerReachResolverTest extends TestCase {

	/**
	 * Who is signed in.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Their groups and their admin status.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The resolver under test.
	 *
	 * @var ViewerReachResolver
	 */
	private ViewerReachResolver $resolver;

	/**
	 * Set up the resolver over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->resolver = new ViewerReachResolver(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Put a signed-in caller on the session.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Nobody signed in is an empty uid, not a uid of something else.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerHasNoUid(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame('', $this->resolver->currentUid());
	}//end testAnAnonymousCallerHasNoUid()

	/**
	 * A signed-in caller's uid comes back as the session states it.
	 *
	 * @return void
	 */
	public function testASignedInCallerHasTheirOwnUid(): void {
		$this->signIn('annemarie');

		$this->assertSame('annemarie', $this->resolver->currentUid());
	}//end testASignedInCallerHasTheirOwnUid()

	/**
	 * An administrator's reach carries the administration, not just the groups.
	 *
	 * @return void
	 */
	public function testAnAdministratorsReachSaysSo(): void {
		$this->signIn('noor');
		$this->groupManager->method('isAdmin')->with('noor')->willReturn(true);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin', 'ciso']);

		$reach = $this->resolver->reachOf(userId: 'noor');

		$this->assertInstanceOf(ViewerReach::class, $reach);
		$this->assertSame('noor', $reach->userId);
		$this->assertSame(['admin', 'ciso'], $reach->groups);
		$this->assertTrue($reach->isAdmin);
	}//end testAnAdministratorsReachSaysSo()

	/**
	 * A membership that cannot be read narrows the reach rather than widening it.
	 *
	 * The fail-closed direction, stated as a test rather than as a comment:
	 * an unreadable group backend answers no groups and no administration.
	 *
	 * @return void
	 */
	public function testAnUnreadableMembershipAnswersTheNarrowestReach(): void {
		$this->signIn('priya');
		$this->groupManager->method('isAdmin')->willThrowException(new RuntimeException('LDAP is down'));

		$reach = $this->resolver->reachOf(userId: 'priya');

		$this->assertFalse($reach->isAdmin, 'an unreadable membership is not an authorization');
		$this->assertSame([], $reach->groups);
		$this->assertSame('priya', $reach->userId);
	}//end testAnUnreadableMembershipAnswersTheNarrowestReach()

	/**
	 * A stranger is refused every field of somebody else's public view.
	 *
	 * The least privileged principal that should be refused: not an
	 * administrator, not the owner, not a share member. A public view is
	 * READABLE by them, and the shape worth pinning is that readable does not
	 * become writable.
	 *
	 * @return void
	 */
	public function testAStrangerIsRefusedEveryFieldOfAPublicView(): void {
		$this->signIn('intruder');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);

		$refused = $this->resolver->refusedFields(
			view: ['owner' => 'someone-else', 'isPublic' => true, 'sharedWith' => []],
			reach: $this->resolver->reachOf(userId: 'intruder'),
			update: ['name' => 'Renamed by a stranger', 'isPublic' => false]
		);

		$this->assertSame(['name', 'isPublic'], $refused);
	}//end testAStrangerIsRefusedEveryFieldOfAPublicView()

	/**
	 * The control: the owner still writes their own view.
	 *
	 * Without it the test above would pass on a resolver that refused
	 * everything to everybody, which is a different bug wearing the same green.
	 *
	 * @return void
	 */
	public function testTheOwnerIsRefusedNothingOnTheirOwnView(): void {
		$this->signIn('annemarie');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);

		$refused = $this->resolver->refusedFields(
			view: ['owner' => 'annemarie', 'isPublic' => true, 'sharedWith' => []],
			reach: $this->resolver->reachOf(userId: 'annemarie'),
			update: ['name' => 'My own view', 'isPublic' => false]
		);

		$this->assertSame([], $refused);
	}//end testTheOwnerIsRefusedNothingOnTheirOwnView()

}//end class
