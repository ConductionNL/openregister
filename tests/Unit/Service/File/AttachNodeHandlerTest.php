<?php

/**
 * Attach a Files node to an object (files-leaf-save-to-object task 1.1).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\AttachNodeHandler;
use OCA\OpenRegister\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The handler goes through FileService::addFile(), and the route looks the
 * node up in the caller's own Files.
 */
class AttachNodeHandlerTest extends TestCase {

	/**
	 * A file node with a name and bytes.
	 *
	 * @param string $name    The name.
	 * @param string $content The bytes.
	 *
	 * @return File The node.
	 */
	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		$file->method('fopen')->willReturn($stream);
		return $file;
	}//end file()

	/**
	 * A file is written to the object through addFile(), under its own name,
	 * with its bytes.
	 *
	 * @return void
	 */
	public function testAFileGoesThroughTheUploadPipeline(): void {
		$object = new ObjectEntity();
		$written = $this->createMock(File::class);
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->once())->method('addFile')
			->with(
				$object,
				'besluit.pdf',
				$this->callback(static fn ($content): bool => is_resource($content) && stream_get_contents($content) === '%PDF-1.7')
			)
			->willReturn($written);

		$result = (new AttachNodeHandler($fileService))->attach($object, $this->file('besluit.pdf', '%PDF-1.7'));

		$this->assertSame([$written], $result['attached']);
		$this->assertSame(0, $result['skipped']);
	}//end testAFileGoesThroughTheUploadPipeline()

	/**
	 * A folder attaches the files directly inside it, skips subfolders, and
	 * counts what it left out past the cap.
	 *
	 * @return void
	 */
	public function testAFolderAttachesItsFilesUpToTheCap(): void {
		$children = [$this->createMock(Folder::class)];
		for ($i = 0; $i < AttachNodeHandler::MAX_FILES + 2; $i++) {
			$children[] = $this->file('scan-' . $i . '.png', 'x');
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->exactly(AttachNodeHandler::MAX_FILES))->method('addFile')->willReturn($this->createMock(File::class));

		$result = (new AttachNodeHandler($fileService))->attach(new ObjectEntity(), $folder);

		$this->assertCount(AttachNodeHandler::MAX_FILES, $result['attached']);
		$this->assertSame(2, $result['skipped']);
	}//end testAFolderAttachesItsFilesUpToTheCap()

	/**
	 * The route looks the node up in the CALLER's Files: an id they have no
	 * node for is 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testTheRouteRefusesANodeTheCallerCannotOpen(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(77)->willReturn([]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($userFolder);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => ($key === 'nodeId' ? '77' : $default));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getObject')->willReturn(new ObjectEntity());
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->never())->method('addFile');

		$controller = new FilesController(
			'openregister',
			$request,
			$fileService,
			$objectService,
			$root,
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			null,
			null,
			$session,
			null,
			$this->createMock(ObjectFileAccess::class)
		);

		$response = $controller->attach('zaken', 'zaak', 'obj-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertStringContainsString('cannot open this file', $response->getData()['error']);
	}//end testTheRouteRefusesANodeTheCallerCannotOpen()

	/**
	 * The route attaches the caller's node and answers the formatted files.
	 *
	 * @return void
	 */
	public function testTheRouteAttachesTheCallersNode(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(78)->willReturn([$this->file('brief.txt', 'Beste')]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => ($key === 'nodeId' ? '78' : $default));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getObject')->willReturn(new ObjectEntity());
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->once())->method('addFile')->willReturn($this->createMock(File::class));
		$fileService->method('formatFile')->willReturn(['title' => 'brief.txt']);

		$controller = new FilesController(
			'openregister',
			$request,
			$fileService,
			$objectService,
			$root,
			$this->createMock(IUserManager::class),
			$this->createMock(IEventDispatcher::class),
			null,
			null,
			$session,
			null,
			$this->createMock(ObjectFileAccess::class)
		);

		$response = $controller->attach('zaken', 'zaak', 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['title' => 'brief.txt']], $response->getData()['attached']);
	}//end testTheRouteAttachesTheCallersNode()
}//end class
