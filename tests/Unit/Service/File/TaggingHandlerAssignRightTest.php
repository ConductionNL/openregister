<?php

/**
 * Object tags follow Nextcloud's own tag rules (openregister#4096).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A restricted or invisible system tag is assigned and removed only by whom Nextcloud allows.
 *
 * Before openregister#4096 the object tag path found tags with no visibility
 * filter and assigned them through the object mapper directly, so Nextcloud's
 * `canUserAssignTag()` never ran and any user could put an admin-only tag on
 * an object, or take one off.
 */
class TaggingHandlerAssignRightTest extends TestCase {

	private ISystemTagManager&MockObject $tagManager;

	private ISystemTagObjectMapper&MockObject $tagMapper;

	private IUserSession&MockObject $userSession;

	private TaggingHandler $handler;

	private ISystemTag&MockObject $restricted;

	protected function setUp(): void {
		parent::setUp();

		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->restricted = $this->createMock(ISystemTag::class);
		$this->restricted->method('getName')->willReturn('legal-hold');
		$this->restricted->method('getId')->willReturn('42');
		$this->tagManager->method('getAllTags')->willReturn([$this->restricted]);

		$this->handler = new TaggingHandler(
			$this->tagManager,
			$this->tagMapper,
			$this->createMock(LoggerInterface::class),
			$this->userSession
		);
	}//end setUp()

	/**
	 * Sign in a user.
	 *
	 * @return IUser
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reader');
		$this->userSession->method('getUser')->willReturn($user);

		return $user;
	}//end signIn()

	/**
	 * A tag the caller may not assign is not put on the object.
	 *
	 * @return void
	 */
	public function testARestrictedTagIsNotAssigned(): void {
		$user = $this->signIn();
		$this->tagManager->method('canUserAssignTag')->with($this->restricted, $user)->willReturn(false);
		$this->tagMapper->expects($this->never())->method('assignTags');

		$this->expectException(NotAuthorizedException::class);
		$this->handler->addObjectTag('zaak-1', 'legal-hold');
	}//end testARestrictedTagIsNotAssigned()

	/**
	 * A tag the caller may not assign is not taken off the object.
	 *
	 * @return void
	 */
	public function testARestrictedTagIsNotRemoved(): void {
		$user = $this->signIn();
		$this->tagManager->method('canUserAssignTag')->with($this->restricted, $user)->willReturn(false);
		$this->tagMapper->expects($this->never())->method('unassignTags');

		$this->expectException(NotAuthorizedException::class);
		$this->handler->removeObjectTag('zaak-1', 'legal-hold');
	}//end testARestrictedTagIsNotRemoved()

	/**
	 * An assignable tag is put on the object as before.
	 *
	 * @return void
	 */
	public function testAnAssignableTagIsAssigned(): void {
		$this->signIn();
		$this->tagManager->method('canUserAssignTag')->willReturn(true);
		$this->tagMapper->expects($this->once())->method('assignTags')->with('zaak-1', 'openregister', ['42']);

		$this->handler->addObjectTag('zaak-1', 'legal-hold');
	}//end testAnAssignableTagIsAssigned()

	/**
	 * A caller Nextcloud does not let create tags gets a refusal, not a server error.
	 *
	 * @return void
	 */
	public function testATagTheCallerMayNotCreateIsRefused(): void {
		$this->signIn();
		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getAllTags')->willReturn([]);
		$tagManager->method('createTag')->willThrowException(new \OCP\SystemTag\TagCreationForbiddenException());
		$this->tagMapper->expects($this->never())->method('assignTags');

		$handler = new TaggingHandler($tagManager, $this->tagMapper, $this->createMock(LoggerInterface::class), $this->userSession);

		$this->expectException(NotAuthorizedException::class);
		$handler->addObjectTag('zaak-1', 'brand-new');
	}//end testATagTheCallerMayNotCreateIsRefused()
}//end class
