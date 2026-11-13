<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\File
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileSession;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refusals of the file lock: a lock held by someone else, a forced unlock
 * by a non-administrator, no logged-in user, and a malformed stored entry.
 *
 * @covers \OCA\OpenRegister\Service\File\FileLockHandler
 * @uses   \OCA\OpenRegister\Service\File\FileSession
 */
class FileLockHandlerRefusalTest extends TestCase {

	private const KEY = 'openregister:file-lock:5';

	/**
	 * @var ICache&MockObject
	 */
	private ICache $cache;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	protected function setUp(): void {
		parent::setUp();

		$this->cache = $this->createMock(ICache::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	private function handler(): FileLockHandler {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('openregister_file_locks')->willReturn($this->cache);

		return new FileLockHandler(
			$factory,
			new FileSession($this->userSession, $this->groupManager, $this->createMock(LoggerInterface::class))
		);
	}//end handler()

	private function actAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end actAs()

	/**
	 * @return array<string, string>
	 */
	private function liveEntry(string $owner): array {
		return [
			'lockedBy' => $owner,
			'lockedAt' => (new \DateTime('-5 minutes'))->format('c'),
			'expiresAt' => (new \DateTime('+25 minutes'))->format('c'),
		];
	}//end liveEntry()

	public function testLockingAFileSomeoneElseHoldsIsRefusedAndWritesNothing(): void {
		$this->actAs('bob');
		$this->cache->method('get')->with(self::KEY)->willReturn($this->liveEntry('alice'));
		$this->cache->expects($this->never())->method('set');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('File is locked by alice');

		$this->handler()->lockFile(5);
	}//end testLockingAFileSomeoneElseHoldsIsRefusedAndWritesNothing()

	public function testTheOwnerRelockingRefreshesTheLock(): void {
		$this->actAs('alice');
		$this->cache->method('get')->with(self::KEY)->willReturn($this->liveEntry('alice'));
		$this->cache->expects($this->once())->method('set')->with(
			self::KEY,
			$this->callback(static fn (array $entry): bool => $entry['lockedBy'] === 'alice'),
			600
		);

		$result = $this->handler()->lockFile(5, 10);

		$this->assertTrue($result['locked']);
		$this->assertSame('alice', $result['lockedBy']);
	}//end testTheOwnerRelockingRefreshesTheLock()

	public function testANonAdministratorCannotForceAnUnlockEvenAsTheOwner(): void {
		$this->actAs('alice');
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->cache->method('get')->with(self::KEY)->willReturn($this->liveEntry('alice'));
		$this->cache->expects($this->never())->method('remove');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Only administrators can force-unlock files');

		$this->handler()->unlockFile(5, true);
	}//end testANonAdministratorCannotForceAnUnlockEvenAsTheOwner()

	public function testAnAdministratorMayForceAnUnlockOfSomeoneElsesLock(): void {
		$this->actAs('root');
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);
		$this->cache->method('get')->with(self::KEY)->willReturn($this->liveEntry('alice'));
		$this->cache->expects($this->once())->method('remove')->with(self::KEY);

		$this->assertSame(['locked' => false], $this->handler()->unlockFile(5, true));
	}//end testAnAdministratorMayForceAnUnlockOfSomeoneElsesLock()

	public function testWithoutALoggedInUserLockingIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->cache->expects($this->never())->method('set');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->handler()->lockFile(5);
	}//end testWithoutALoggedInUserLockingIsRefused()

	public function testAMalformedEntryIsDroppedAndTheFileReadsAsUnlocked(): void {
		$this->cache->method('get')->with(self::KEY)->willReturn(
			['lockedBy' => 'alice', 'lockedAt' => 'yesterday', 'expiresAt' => 'not a date']
		);
		$this->cache->expects($this->once())->method('remove')->with(self::KEY);

		$handler = $this->handler();

		$this->assertNull($handler->getLockInfo(5));
	}//end testAMalformedEntryIsDroppedAndTheFileReadsAsUnlocked()
}//end class
