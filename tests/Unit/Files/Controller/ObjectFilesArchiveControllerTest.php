<?php

/**
 * GET /api/objects/{register}/{schema}/{id}/files/download, served by the Files ObjectFilesArchiveController.
 *
 * The bulk download moved from ObjectsController::downloadFiles() to file storage's own controller.
 * These are the tests that pinned it on ObjectsController, retargeted unchanged: the object lookup
 * answers 404 / 409 / 500 first, file storage builds the archive, the temporary ZIP is removed, and
 * one audit entry is written without ever breaking the download.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Controller;

use Exception;
use OCA\OpenRegister\Files\Controller\ObjectFilesArchiveController;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bulk download route on ObjectFilesArchiveController.
 */
class ObjectFilesArchiveControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private ObjectService&MockObject $objectService;

	private ?ObjectFilesArchiveInterface $archive = null;

	/**
	 * The mocks the route reads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * The controller, built when the test asks for it so a test can set the archive first.
	 *
	 * @return ObjectFilesArchiveController
	 */
	private function controller(): ObjectFilesArchiveController {
		return new ObjectFilesArchiveController(
			$this->request,
			($this->archive ?? $this->createMock(ObjectFilesArchiveInterface::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A slug two objects of the register and schema hold is the 409 the object routes answer
	 * (#4037), as it was on ObjectsController (moved from ObjectsControllerAmbiguousSlugTest).
	 *
	 * @return void
	 */
	public function testAnAmbiguousSlugIs409(): void {
		$this->objectService->method('find')->willThrowException(
			new MultipleObjectsReturnedException('Identifier "hello-world" matches more than one object in this register and schema')
		);
		$archive = $this->createMock(ObjectFilesArchiveInterface::class);
		$archive->expects($this->never())->method('createZip');
		$this->archive = $archive;

		$response = $this->controller()->downloadFiles('hello-world', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(
			[
				'message' => 'Identifier "hello-world" matches more than one object in this register and schema',
				'error' => 'ambiguous-identifier',
			],
			$response->getData()
		);
	}//end testAnAmbiguousSlugIs409()

	/**
	 * The object is looked up in the route's register and schema before the archive is built.
	 *
	 * @return void
	 */
	public function testTheObjectIsLookedUpInTheRouteScopeBeforeTheArchive(): void {
		$calls = [];
		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$this->objectService->method('setRegister')->willReturnCallback(function (...$args) use (&$calls) {
			$calls[] = ['setRegister', $args];
			return $this->objectService;
		});
		$this->objectService->method('setSchema')->willReturnCallback(function (...$args) use (&$calls) {
			$calls[] = ['setSchema', $args];
			return $this->objectService;
		});
		$this->objectService->method('find')->willReturnCallback(function (...$args) use (&$calls, $object) {
			$calls[] = ['find', $args];
			return $object;
		});
		$archive = $this->createMock(ObjectFilesArchiveInterface::class);
		$archive->method('createZip')->willReturnCallback(function () use (&$calls): array {
			$calls[] = ['createZip', []];
			throw new Exception('stop here');
		});
		$this->archive = $archive;

		$response = $this->controller()->downloadFiles('obj-1', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['setRegister', 'setSchema', 'find', 'createZip'], array_column($calls, 0));
		$this->assertSame(['zaken'], $calls[0][1]);
		$this->assertSame(['persoon'], $calls[1][1]);
	}//end testTheObjectIsLookedUpInTheRouteScopeBeforeTheArchive()

	public function testDownloadFilesReturns404WhenObjectNotFound(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());
	}

	public function testDownloadFilesReturns500OnGenericException(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')
			->willThrowException(new Exception('Zip failed'));

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());
	}

	public function testDownloadFilesReturnsZipOnSuccess(): void {
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn($objectEntity);

		// Create a real temp file for the zip
		$tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
		file_put_contents($tempZip, 'fake-zip-content');

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('createObjectFilesZip')->willReturn([
			'path' => $tempZip,
			'filename' => 'test-files.zip',
			'mimeType' => 'application/zip',
		]);

		$this->archive = (new \OCA\OpenRegister\Service\File\ObjectFilesArchive($fileService));
		$this->request->method('getParam')->willReturn(null);

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=test-files\.zip$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('application/zip', $result->getHeaders()['Content-Type']);

		// Clean up if file still exists
		if (file_exists($tempZip)) {
			unlink($tempZip);
		}
	}

	/**
	 * A bulk download is audited as one entry naming every file, and the temporary ZIP goes.
	 *
	 * @return void
	 */
	public function testDownloadFilesAuditsOneEntryAndRemovesTheZip(): void {
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$this->objectService->method('find')->willReturn($objectEntity);

		$tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
		file_put_contents($tempZip, 'fake-zip-content');

		$first = $this->createMock(\OCP\Files\File::class);
		$first->method('getId')->willReturn(11);
		$first->method('getName')->willReturn('a.txt');
		$second = $this->createMock(\OCP\Files\File::class);
		$second->method('getId')->willReturn(12);
		$second->method('getName')->willReturn('b.txt');

		$audit = $this->createMock(\OCA\OpenRegister\Files\Service\File\FileAuditHandler::class);
		$audit->expects($this->once())->method('logBulkDownload')->with(
			$objectEntity,
			new \OCA\OpenRegister\Files\Service\File\BulkDownloadArchive(
				fileIds: [11, 12],
				fileNames: ['a.txt', 'b.txt'],
				zipName: 'test-files.zip',
				totalBytes: 16
			)
		);
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('createObjectFilesZip')->willReturn(
			['path' => $tempZip, 'filename' => 'test-files.zip', 'mimeType' => 'application/zip', 'size' => 16]
		);
		$fileService->method('getFiles')->willReturn([$first, $second]);
		$fileService->method('getAuditHandler')->willReturn($audit);
		$this->archive = (new \OCA\OpenRegister\Service\File\ObjectFilesArchive($fileService));

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertSame('fake-zip-content', $result->render());
		$this->assertFileDoesNotExist($tempZip);
	}

	/**
	 * A failing audit trail does not break the download.
	 *
	 * @return void
	 */
	public function testDownloadFilesSurvivesAFailingAudit(): void {
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$this->objectService->method('find')->willReturn($objectEntity);

		$tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
		file_put_contents($tempZip, 'zip');

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('createObjectFilesZip')->willReturn(
			['path' => $tempZip, 'filename' => 'test-files.zip', 'mimeType' => 'application/zip']
		);
		$fileService->method('getFiles')->willThrowException(new \RuntimeException('no trail'));
		$this->archive = (new \OCA\OpenRegister\Service\File\ObjectFilesArchive($fileService));

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=test-files\.zip$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('application/zip', $result->getHeaders()['Content-Type']);
	}

	public function testDownloadFilesReturns404WhenObjectDoesNotExistViaException(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$result = $this->controller()->downloadFiles('nonexistent', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame(404, $result->getStatus());
	}

	public function testDownloadFilesUsesCustomFilename(): void {
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn($objectEntity);

		$tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
		file_put_contents($tempZip, 'fake-zip-content');

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('createObjectFilesZip')->willReturn([
			'path' => $tempZip,
			'filename' => 'custom-name.zip',
			'mimeType' => 'application/zip',
		]);

		$this->archive = (new \OCA\OpenRegister\Service\File\ObjectFilesArchive($fileService));
		$this->request->method('getParam')->willReturn('custom-name');

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
		$this->assertMatchesRegularExpression('/filename=custom-name\.zip$/', $result->getHeaders()['Content-Disposition']);
		$this->assertSame('application/zip', $result->getHeaders()['Content-Type']);

		if (file_exists($tempZip)) {
			unlink($tempZip);
		}
	}

	public function testDownloadFilesReturns500WhenZipCreationFails(): void {
		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('uuid-123');
		$objectEntity->setRegister('1');
		$objectEntity->setSchema('2');
		$objectEntity->setObject(['title' => 'Test']);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn($objectEntity);

		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('createObjectFilesZip')
			->willThrowException(new \Exception('Disk full'));

		$this->archive = (new \OCA\OpenRegister\Service\File\ObjectFilesArchive($fileService));
		$this->request->method('getParam')->willReturn(null);

		$result = $this->controller()->downloadFiles('uuid-123', '1', '2', $this->objectService);

		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
		$this->assertSame(500, $result->getStatus());
		$data = $result->getData();
		// SEC-CTRL-7: internal exception detail must not leak; generic envelope only.
		$this->assertSame('Internal server error', $data['error']);
	}
}//end class
