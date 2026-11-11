<?php

/**
 * A share link needs a reachable OpenRegister user folder before any file is looked up.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Service\File\ShareLinkRequest;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\FileService
 */
class FileServiceCreateShareLinkTest extends TestCase {

	/**
	 * The folder handler double.
	 *
	 * @var FolderManagementHandler&MockObject
	 */
	private FolderManagementHandler&MockObject $folders;

	/**
	 * The root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * A file service holding only the collaborators createShareLink reaches first.
	 *
	 * @return FileService The service.
	 */
	private function service(): FileService {
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);

		$service = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$collaborators = [
			'folderManagementHandler' => $this->folders,
			'rootFolder' => $this->rootFolder,
			'logger' => $this->createMock(LoggerInterface::class),
		];
		foreach ($collaborators as $name => $value) {
			(new ReflectionProperty(FileService::class, $name))->setValue($service, $value);
		}

		return $service;
	}//end service()

	/**
	 * Without the user folder the caller is told so and no file is looked up.
	 *
	 * @return void
	 */
	public function testNoFileIsLookedUpWithoutTheUserFolder(): void {
		$service = $this->service();
		$this->folders->expects($this->once())
			->method('getOpenRegisterUserFolder')
			->willThrowException(new Exception('no folder'));
		$this->rootFolder->expects($this->never())->method('get');

		$this->assertSame("OpenRegister user folder couldn't be found.", $service->createShareLink(new ShareLinkRequest('a/b.txt')));
	}//end testNoFileIsLookedUpWithoutTheUserFolder()

	/**
	 * Control: with the user folder the file is looked up under the trimmed path.
	 *
	 * @return void
	 */
	public function testTheFileIsLookedUpWithTheUserFolder(): void {
		$service = $this->service();
		$this->folders->expects($this->once())
			->method('getOpenRegisterUserFolder')
			->willReturn($this->createMock(Folder::class));
		$this->rootFolder->expects($this->once())
			->method('get')
			->with('a/b.txt')
			->willThrowException(new NotFoundException());

		$this->assertSame('File not found at a/b.txt', $service->createShareLink(new ShareLinkRequest('/a/b.txt/')));
	}//end testTheFileIsLookedUpWithTheUserFolder()
}//end class
