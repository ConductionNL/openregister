<?php

/**
 * Tests that adding a file to an object through the files API dispatches FileAddedEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-file-added-event/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\FileAddedEvent;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FilesControllerFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every files-API add dispatches one FileAddedEvent after the file is stored (REQ-OFAE-001).
 *
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 * @covers \OCA\OpenRegister\Event\FileAddedEvent
 */
final class FilesControllerFileAddedEventTest extends TestCase {

	private IRequest&MockObject $request;

	private FileService&MockObject $fileService;

	private ObjectService&MockObject $objectService;

	private IEventDispatcher&MockObject $events;

	/**
	 * The events dispatched, in order.
	 *
	 * @var list<Event>
	 */
	private array $dispatched = [];

	/**
	 * Whether the dispatcher throws, as a failing listener does.
	 *
	 * @var bool
	 */
	private bool $listenerFails = false;

	/**
	 * The calls in order: adds and dispatches.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->events = $this->createMock(IEventDispatcher::class);
		$this->events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->log[] = 'dispatch';
				$this->dispatched[] = $event;
				if ($this->listenerFails === true) {
					throw new RuntimeException('listener failed');
				}
			}
		);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('5');
		$object->setSchema('9');
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getObject')->willReturn($object);

		$this->fileService->method('addFile')->willReturnCallback(
			function ($target, $upload): File {
				$this->log[] = 'addFile ' . $upload->fileName;
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(40 + count($this->log));
				$file->method('getName')->willReturn($upload->fileName);

				return $file;
			}
		);
		$this->fileService->method('formatFile')->willReturn(['id' => 1]);
		$this->fileService->method('formatFiles')->willReturn(['results' => []]);
	}

	/**
	 * The controller under test.
	 *
	 * @return FilesController
	 */
	private function controller(): FilesController {
		return FilesControllerFactory::create(
			$this->request,
			$this->fileService,
			$this->objectService,
			$this->createMock(IRootFolder::class),
			$this->createMock(IUserManager::class),
			$this->events
		);
	}

	/**
	 * Posting a file's content dispatches one FileAddedEvent after the add, naming the object, the file,
	 * its name and the object's register and schema.
	 *
	 * @return void
	 */
	public function testCreatingAFileDispatchesFileAdded(): void {
		$this->request->method('getParams')->willReturn(['name' => 'besluit.pdf', 'content' => 'bytes']);

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['addFile besluit.pdf', 'dispatch'], $this->log);
		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(FileAddedEvent::class, $event);
		$this->assertSame('obj-1', $event->getObjectUuid());
		$this->assertSame(41, $event->getFileId());
		$this->assertSame(['fileName' => 'besluit.pdf', 'register' => '5', 'schema' => '9'], $event->getData());
	}

	/**
	 * A multipart upload dispatches one FileAddedEvent per stored file.
	 *
	 * @return void
	 */
	public function testEveryUploadedFileDispatchesFileAdded(): void {
		$first = (string)tempnam(sys_get_temp_dir(), 'or-test-');
		$second = (string)tempnam(sys_get_temp_dir(), 'or-test-');
		file_put_contents($first, 'a');
		file_put_contents($second, 'b');
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getUploadedFile')->willReturnCallback(
			static fn (string $key): ?array => match ($key) {
				'files' => [
					'name' => ['a.pdf', 'b.pdf'],
					'type' => ['application/pdf', 'application/pdf'],
					'tmp_name' => [$first, $second],
					'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
					'size' => [1, 1],
				],
				default => null,
			}
		);

		$response = $this->controller()->createMultipart('reg', 'sch', 'obj-1');
		@unlink($first);
		@unlink($second);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['addFile a.pdf', 'dispatch', 'addFile b.pdf', 'dispatch'], $this->log);
		$this->assertSame(['a.pdf', 'b.pdf'], array_map(static fn (FileAddedEvent $e): string => $e->getData()['fileName'], $this->dispatched));
	}

	/**
	 * A listener that throws does not fail the add: the file is stored and the answer is the file.
	 *
	 * @return void
	 */
	public function testAFailingListenerDoesNotFailTheAdd(): void {
		$this->listenerFails = true;
		$this->request->method('getParams')->willReturn(['name' => 'besluit.pdf', 'content' => 'bytes']);

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 1], $response->getData());
		$this->assertCount(1, $this->dispatched);
	}

	/**
	 * A failed add dispatches nothing.
	 *
	 * @return void
	 */
	public function testAFailedAddDispatchesNothing(): void {
		$this->request->method('getParams')->willReturn(['content' => 'bytes']);

		$response = $this->controller()->create('reg', 'sch', 'obj-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->dispatched);
	}
}
