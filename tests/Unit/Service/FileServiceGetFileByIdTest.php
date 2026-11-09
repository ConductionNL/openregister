<?php

/**
 * Unit tests for FileService::getFileById(): a file outside OpenRegister's own
 * tree is looked up in the caller's home only, never across every user's mounts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

class FileServiceGetFileByIdTest extends TestCase {

	private IRootFolder&MockObject $rootFolder;

	private FolderManagementHandler&MockObject $folders;

	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->folders    = $this->createMock(FolderManagementHandler::class);
		$this->session    = $this->createMock(IUserSession::class);
	}//end setUp()

	private function service(): FileService&MockObject {
		$service = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['checkOwnership'])
			->getMock();

		$values = [
			'rootFolder'              => $this->rootFolder,
			'folderManagementHandler' => $this->folders,
			'userSession'             => $this->session,
			'logger'                  => $this->createMock(LoggerInterface::class),
		];
		foreach ($values as $name => $value) {
			$property = new ReflectionProperty(FileService::class, $name);
			$property->setValue($service, $value);
		}

		return $service;
	}//end service()

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	public function testAManagedFileIsReturnedWithoutTouchingAnyUserTree(): void {
		$managed = $this->createMock(File::class);
		$this->folders->method('getManagedNodeById')->with(nodeId: 7)->willReturn($managed);
		$this->rootFolder->expects($this->never())->method('getById');
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertSame($managed, $this->service()->getFileById(7));
	}//end testAManagedFileIsReturnedWithoutTouchingAnyUserTree()

	public function testAnotherFileIsLookedUpInTheCallersOwnHomeOnly(): void {
		$this->signIn('bob');
		$this->folders->method('getManagedNodeById')->willReturn(null);
		$home = $this->createMock(Folder::class);
		$home->expects($this->once())->method('getById')->with(9)->willReturn([]);
		$this->rootFolder->expects($this->never())->method('getById');
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('bob')->willReturn($home);

		$this->assertNull($this->service()->getFileById(9));
	}//end testAnotherFileIsLookedUpInTheCallersOwnHomeOnly()

	public function testTheCallersOwnFileIsReturned(): void {
		$this->signIn('bob');
		$this->folders->method('getManagedNodeById')->willReturn(null);
		$own  = $this->createMock(File::class);
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturn([$own]);
		$this->rootFolder->method('getUserFolder')->willReturn($home);

		$this->assertSame($own, $this->service()->getFileById(9));
	}//end testTheCallersOwnFileIsReturned()

	public function testWithoutASignedInUserOnlyManagedFilesResolve(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->folders->method('getManagedNodeById')->willReturn(null);
		$this->rootFolder->expects($this->never())->method('getById');
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertNull($this->service()->getFileById(9));
	}//end testWithoutASignedInUserOnlyManagedFilesResolve()
}//end class
