<?php

declare(strict_types=1);

/*
 * FileOwnershipHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCP\Files\File;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileOwnershipHandler.
 *
 * Covers the conditional ownership-transfer contract: a node the current user
 * can already write MUST NOT be re-owned to the openregister system user.
 */
class FileOwnershipHandlerTest extends TestCase {

	/**
	 * @var FileOwnershipHandler
	 */
	private FileOwnershipHandler $handler;

	/**
	 * @var IUserManager&MockObject
	 */
	private $userManager;

	/**
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new FileOwnershipHandler(
			$this->userManager,
			$this->groupManager,
			$this->userSession,
			$this->logger
		);
	}//end setUp()

	// =========================================================================
	// transferFileOwnershipIfNeeded - conditional re-own
	// =========================================================================

	public function testTransferSkippedWhenUserHasWriteRights(): void {
		// A node the current session can already write must be left as-is. The
		// guard returns before the method ever resolves the session user, so
		// IUserSession::getUser() must never be called and no transfer happens.
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(true);

		$this->userSession->expects($this->never())->method('getUser');

		$this->handler->transferFileOwnershipIfNeeded(file: $file);

		$this->assertTrue(true);
	}//end testTransferSkippedWhenUserHasWriteRights()
}//end class
