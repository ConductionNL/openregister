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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A managed node is looked up in the openregister account's home and handed
 * out only when it lies under `Open Registers/`; any failure on the way is
 * "no node", never a node from elsewhere.
 *
 * @covers \OCA\OpenRegister\Service\File\FolderManagementHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class FolderManagementHandlerManagedNodeTest extends TestCase {

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var Folder&MockObject
	 */
	private Folder $home;

	protected function setUp(): void {
		parent::setUp();

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->home = $this->createMock(Folder::class);
	}//end setUp()

	private function handler(?FileHandlerSupportInterface $fileService, ?ContainerInterface $container = null): FolderManagementHandler {
		return new FolderManagementHandler(
			rootFolder: $this->rootFolder,
			objectEntityMapper: $this->createMock(MagicMapperInterface::class),
			registerMapper: $this->createMock(RegisterMapperInterface::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->logger,
			auditTrailMapper: $this->createMock(AuditTrailMapperInterface::class),
			mountCache: $this->createMock(IUserMountCache::class),
			folderRecorder: $this->createMock(RegisterFolderRecorder::class),
			fileService: $fileService,
			container: $container
		);
	}//end handler()

	/**
	 * @return FileHandlerSupportInterface&MockObject
	 */
	private function systemAccount(): FileHandlerSupportInterface {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('openregister');
		$fileService = $this->createMock(FileHandlerSupportInterface::class);
		$fileService->method('getUser')->willReturn($account);
		$this->rootFolder->method('getUserFolder')->with('openregister')->willReturn($this->home);

		return $fileService;
	}//end systemAccount()

	private function node(string $path): Node&MockObject {
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willReturn($path);

		return $node;
	}//end node()

	public function testANodeUnderOpenRegistersIsReturned(): void {
		$node = $this->node('/openregister/files/Open Registers/Register (1)/obj/a.pdf');
		$this->home->expects($this->once())->method('getFirstNodeById')->with(42)->willReturn($node);

		$this->assertSame($node, $this->handler($this->systemAccount())->getManagedNodeById(42));
	}//end testANodeUnderOpenRegistersIsReturned()

	public function testANodeOutsideTheManagedTreeIsNotHandedOut(): void {
		$this->home->method('getFirstNodeById')->willReturn($this->node('/openregister/files/Private/a.pdf'));

		$this->assertNull($this->handler($this->systemAccount())->getManagedNodeById(42));
	}//end testANodeOutsideTheManagedTreeIsNotHandedOut()

	public function testAnUnknownIdIsNull(): void {
		$this->home->method('getFirstNodeById')->willReturn(null);

		$this->assertNull($this->handler($this->systemAccount())->getManagedNodeById(42));
	}//end testAnUnknownIdIsNull()

	public function testWithoutTheOpenRegisterAccountNoNodeIsFound(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('The OpenRegister account is not available'));

		$this->assertNull($this->handler(null)->getManagedNodeById(42));
	}//end testWithoutTheOpenRegisterAccountNoNodeIsFound()

	public function testTheFileServiceIsResolvedFromTheContainerWhenNotInjected(): void {
		$fileService = $this->systemAccount();
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with(FileHandlerSupportInterface::class)->willReturn($fileService);
		$node = $this->node('/openregister/files/Open Registers');
		$this->home->method('getFirstNodeById')->with(9)->willReturn($node);

		$handler = $this->handler(null, $container);

		$this->assertSame($node, $handler->getManagedNodeById(9));
		// Resolved once, then kept.
		$this->assertSame($node, $handler->getManagedNodeById(9));
	}//end testTheFileServiceIsResolvedFromTheContainerWhenNotInjected()

	public function testAContainerThatCannotBuildTheFileServiceIsLoggedAndYieldsNoNode(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('FileService could not be resolved: not wired'));

		$this->assertNull($this->handler(null, $container)->getManagedNodeById(42));
	}//end testAContainerThatCannotBuildTheFileServiceIsLoggedAndYieldsNoNode()

	public function testIsManagedNodeMatchesOnlyTheOpenRegistersTree(): void {
		$handler = $this->handler(null);

		$this->assertTrue($handler->isManagedNode($this->node('/alice/files/Open Registers')));
		$this->assertTrue($handler->isManagedNode($this->node('/alice/files/Open Registers/R/x.pdf')));
		$this->assertFalse($handler->isManagedNode($this->node('/alice/files/Open Registers Backup/x.pdf')));
		$this->assertFalse($handler->isManagedNode($this->node('/alice/files/Docs/Open Registers/x.pdf')));
		$this->assertFalse($handler->isManagedNode($this->node('/alice/Open Registers/x.pdf')));
	}//end testIsManagedNodeMatchesOnlyTheOpenRegistersTree()

	public function testANodeWhosePathCannotBeReadIsNotManaged(): void {
		$node = $this->createMock(Node::class);
		$node->method('getPath')->willThrowException(new NotFoundException('gone'));

		$this->assertFalse($this->handler(null)->isManagedNode($node));
	}//end testANodeWhosePathCannotBeReadIsNotManaged()
}//end class
