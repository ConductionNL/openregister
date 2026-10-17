<?php

/**
 * Unit tests for the FileService facade the folder handler resolves on first use.
 *
 * The facade is injected by FileService's own constructor. A request that
 * reached the handler without building FileService first (ObjectSharingService
 * granting a share or minting a link) found it null, so the OpenRegister
 * account could not be resolved and every grant answered "Could not resolve
 * the object folder to share".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-openregisters-own-account-holds-every-managed-folder-req-ofoa-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and mock builders.

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class FolderManagementHandlerLazyFileServiceTest extends TestCase {

	/**
	 * A handler over mocks, with or without a container to resolve FileService.
	 *
	 * @param ContainerInterface|null $container The container, or none.
	 * @param IRootFolder             $rootFolder The root folder mock.
	 *
	 * @return FolderManagementHandler The handler, with no facade injected.
	 */
	private function handler(?ContainerInterface $container, IRootFolder $rootFolder): FolderManagementHandler {
		return new FolderManagementHandler(
			rootFolder: $rootFolder,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->createMock(RegisterFolderRecorder::class),
			fileService: null,
			container: $container
		);
	}//end handler()

	/**
	 * With nothing injected, the facade is resolved and the account's folder answers.
	 *
	 * @return void
	 */
	public function testTheAccountFolderResolvesWhenNothingInjectedTheFacade(): void {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('openregister');

		$fileService = $this->createMock(FileService::class);
		$fileService->method('getUser')->willReturn($account);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(FileService::class)
			->willReturn($fileService);

		$folder = $this->createMock(Folder::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('openregister')->willReturn($folder);

		$handler = $this->handler(container: $container, rootFolder: $rootFolder);

		$this->assertSame($folder, $handler->getOpenRegisterUserFolder());
		// Resolved once and kept: a second call does not ask the container again.
		$this->assertSame($folder, $handler->getOpenRegisterUserFolder());
	}//end testTheAccountFolderResolvesWhenNothingInjectedTheFacade()

	/**
	 * The control: with no container either, the account is still unavailable.
	 *
	 * @return void
	 */
	public function testWithoutAContainerTheAccountIsStillUnavailable(): void {
		$handler = $this->handler(container: null, rootFolder: $this->createMock(IRootFolder::class));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('The OpenRegister account is not available');

		$handler->getOpenRegisterUserFolder();
	}//end testWithoutAContainerTheAccountIsStillUnavailable()
}//end class
