<?php

/**
 * FileBatchHandlerGoldenTest
 *
 * Golden tests that pin what {@see \OCA\OpenRegister\Service\File\FileBatchHandler}
 * returns, throws, calls and logs today for every batch action.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins FileBatchHandler::executeBatch().
 */
class FileBatchHandlerGoldenTest extends TestCase {

	/**
	 * Recorded calls and log lines, in order.
	 *
	 * @var list<string>
	 */
	public array $trail = [];

	/**
	 * The object the batch runs against.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Build the object.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->object = new ObjectEntity();
	}//end setUp()

	/**
	 * A handler with a recording logger and, optionally, a recording FileService.
	 *
	 * @param bool $withService Whether to wire a FileService.
	 *
	 * @return FileBatchHandler
	 */
	private function handler(bool $withService): FileBatchHandler {
		$test    = $this;
		$logger  = new class ($test) extends AbstractLogger {
			public function __construct(private readonly FileBatchHandlerGoldenTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->test->trail[] = 'log '.$level.': '.$message;
			}
		};
		$handler = new FileBatchHandler(
			$this->createMock(FilePublishingHandler::class),
			$this->createMock(DeleteFileHandler::class),
			$this->createMock(TaggingHandlerInterface::class),
			$logger
		);
		if ($withService === true) {
			$handler->setFileService($this->fileService());
		}

		return $handler;
	}//end handler()

	/**
	 * A FileService that records calls; file 13 fails everywhere, file 7 is not deleted.
	 *
	 * @return FileService
	 */
	private function fileService(): FileService {
		$service = $this->createMock(FileService::class);
		$file    = $this->createMock(File::class);
		$check   = function (string $call, mixed $id, mixed $object) use ($file): File {
			$this->trail[] = $call.' '.var_export($id, true).' '.var_export($object === $this->object, true);
			if ($id === 13) {
				throw new Exception('kapot '.$id);
			}

			return $file;
		};
		$service->method('publishFile')->willReturnCallback(fn ($object, $id) => $check('publish', $id, $object));
		$service->method('unpublishFile')->willReturnCallback(fn ($object, $id) => $check('depublish', $id, $object));
		$service->method('deleteFile')->willReturnCallback(
			function ($id, $object) use ($check): bool {
				$check('delete', $id, $object);
				return $id !== 7;
			}
		);
		$service->method('updateFile')->willReturnCallback(
			function ($id, $content, $tags, $object) use ($check): File {
				$this->trail[] = 'label content='.var_export($content, true).' tags='.json_encode($tags);
				return $check('update', $id, $object);
			}
		);
		return $service;
	}//end fileService()

	/**
	 * Run a batch and return the message of the exception it throws.
	 *
	 * @param string $action  The action.
	 * @param array  $fileIds The ids.
	 *
	 * @return string
	 */
	private function refusal(string $action, array $fileIds): string {
		try {
			$this->handler(true)->executeBatch($this->object, $action, $fileIds);
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return 'no exception';
	}//end refusal()

	/**
	 * Refused batches, checked in order: action, size, emptiness.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame('Invalid batch action. Allowed: publish, depublish, delete, label', $this->refusal('move', [1]));
		self::assertSame('Invalid batch action. Allowed: publish, depublish, delete, label', $this->refusal('Publish', []));
		self::assertSame('Invalid batch action. Allowed: publish, depublish, delete, label', $this->refusal('move', range(1, 101)));
		self::assertSame('Batch operations are limited to 100 files per request', $this->refusal('publish', range(1, 101)));
		self::assertSame('No file IDs provided', $this->refusal('delete', []));
		self::assertSame([], $this->trail);
	}//end testRefusals()

	/**
	 * Publish, depublish and delete per file, with per-file failures and logs.
	 *
	 * @return void
	 */
	public function testActions(): void {
		self::assertSame(
			[
				'results' => [
					['fileId' => 1, 'success' => true],
					['fileId' => '13', 'success' => false, 'error' => 'kapot 13'],
					['fileId' => '2x', 'success' => true],
				],
				'summary' => ['total' => 3, 'succeeded' => 2, 'failed' => 1],
			],
			$this->handler(true)->executeBatch($this->object, 'publish', [1, '13', '2x'])
		);
		self::assertSame(
			['publish 1 true', 'publish 13 true', 'log warning: [FileBatchHandler] Batch publish failed for file 13: kapot 13', 'publish 2 true'],
			$this->trail
		);

		$this->trail = [];
		self::assertSame(
			[
				'results' => [['fileId' => 5, 'success' => true]],
				'summary' => ['total' => 1, 'succeeded' => 1, 'failed' => 0],
			],
			$this->handler(true)->executeBatch($this->object, 'depublish', [5], ['labels' => ['x']])
		);
		self::assertSame(['depublish 5 true'], $this->trail);

		$this->trail = [];
		self::assertSame(
			[
				'results' => [
					['fileId' => 7, 'success' => false, 'error' => 'The file could not be deleted'],
					['fileId' => 8, 'success' => true],
					['fileId' => 13, 'success' => false, 'error' => 'kapot 13'],
				],
				'summary' => ['total' => 3, 'succeeded' => 1, 'failed' => 2],
			],
			$this->handler(true)->executeBatch($this->object, 'delete', [7, 8, 13])
		);
		self::assertSame(
			[
				'delete 7 true',
				'log warning: [FileBatchHandler] Batch delete failed for file 7: The file could not be deleted',
				'delete 8 true',
				'delete 13 true',
				'log warning: [FileBatchHandler] Batch delete failed for file 13: kapot 13',
			],
			$this->trail
		);
	}//end testActions()

	/**
	 * Label passes the labels parameter as tags, empty when absent.
	 *
	 * @return void
	 */
	public function testLabel(): void {
		$result = $this->handler(true)->executeBatch($this->object, 'label', [3], ['labels' => ['a', 'b']]);
		self::assertSame(['total' => 1, 'succeeded' => 1, 'failed' => 0], $result['summary']);
		$this->handler(true)->executeBatch($this->object, 'label', [4]);
		$this->handler(true)->executeBatch($this->object, 'label', [5], ['labels' => null]);
		self::assertSame(
			[
				'label content=NULL tags=["a","b"]',
				'update 3 true',
				'label content=NULL tags=[]',
				'update 4 true',
				'label content=NULL tags=[]',
				'update 5 true',
			],
			$this->trail
		);
	}//end testLabel()

	/**
	 * Without a FileService every file fails with the same message.
	 *
	 * @return void
	 */
	public function testWithoutFileService(): void {
		self::assertSame(
			[
				'results' => [
					['fileId' => 1, 'success' => false, 'error' => 'FileService not initialized in FileBatchHandler'],
					['fileId' => 2, 'success' => false, 'error' => 'FileService not initialized in FileBatchHandler'],
				],
				'summary' => ['total' => 2, 'succeeded' => 0, 'failed' => 2],
			],
			$this->handler(false)->executeBatch($this->object, 'label', [1, 2])
		);
		self::assertSame(
			[
				'log warning: [FileBatchHandler] Batch label failed for file 1: FileService not initialized in FileBatchHandler',
				'log warning: [FileBatchHandler] Batch label failed for file 2: FileService not initialized in FileBatchHandler',
			],
			$this->trail
		);
	}//end testWithoutFileService()
}//end class
