<?php

/**
 * Unit tests for the user/group lookups of NotificationRecipientResolver.
 *
 * A lookup that throws answers "no" without caching it, so the uid is
 * retried rather than dropped for the rest of the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver
 */
final class NotificationRecipientResolverLookupTest extends TestCase {

	/** @var IUserManager&MockObject */
	private $userManager;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	/** @var LoggerInterface&MockObject */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager  = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
	}

	private function resolver(): NotificationRecipientResolver {
		return new NotificationRecipientResolver($this->userManager, $this->groupManager, $this->logger);
	}

	public function testAnEmptyUidDoesNotExistWithoutAsking(): void {
		$this->userManager->expects($this->never())->method('userExists');

		$this->assertFalse($this->resolver()->userExists(uid: ''));
	}

	public function testAnExistingUserIsAskedOnceAndCached(): void {
		$this->userManager->expects($this->once())->method('userExists')->with('alice')->willReturn(true);
		$resolver = $this->resolver();

		$this->assertTrue($resolver->userExists(uid: 'alice'));
		$this->assertTrue($resolver->userExists(uid: 'alice'));
	}

	public function testAFailedUserLookupIsLoggedAndNotCached(): void {
		$this->userManager->expects($this->exactly(2))
			->method('userExists')
			->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('ldap down')), true);
		$this->logger->expects($this->once())
			->method('warning')
			->with('[NotificationRecipientResolver] userExists check failed for "alice" (not cached, will retry): ldap down');
		$resolver = $this->resolver();

		$this->assertFalse($resolver->userExists(uid: 'alice'));
		$this->assertTrue($resolver->userExists(uid: 'alice'));
	}

	public function testAnEmptyGroupIdDoesNotExistWithoutAsking(): void {
		$this->groupManager->expects($this->never())->method('groupExists');

		$this->assertFalse($this->resolver()->groupExists(gid: ''));
	}

	public function testAGroupLookupAnswersTheGroupManager(): void {
		$this->groupManager->method('groupExists')->willReturnMap([['admins', true], ['ghosts', false]]);

		$this->assertTrue($this->resolver()->groupExists(gid: 'admins'));
		$this->assertFalse($this->resolver()->groupExists(gid: 'ghosts'));
	}

	public function testAFailedGroupLookupIsLoggedAndAnswersNo(): void {
		$this->groupManager->method('groupExists')->willThrowException(new RuntimeException('ldap down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[NotificationRecipientResolver] groupExists check failed for "admins": ldap down');

		$this->assertFalse($this->resolver()->groupExists(gid: 'admins'));
	}

	public function testARelationValueThatIsNeitherStringNorListHasNoUids(): void {
		$this->assertSame([], $this->resolver()->extractUidsFromRelation(value: 42));
		$this->assertSame([], $this->resolver()->extractUidsFromRelation(value: ''));
		$this->assertSame(['alice'], $this->resolver()->extractUidsFromRelation(value: 'alice'));
	}
}
