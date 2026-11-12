<?php

/**
 * A merge never reports a file as moved or deleted that is still in its source object.
 *
 * Nextcloud does not tell a Node API caller that a listener aborted a delete
 * (the frozen-object guard does): FileService::deleteFile() answers false for a
 * file that is still there, and the merge has to read that.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCP\Files\File;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Object\MergeHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class MergeHandlerFileTransferTest extends TestCase {

	private FileService&MockObject $fileService;

	private MergeHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->fileService = $this->createMock(FileService::class);
		$this->handler = new MergeHandler(
			$this->createMock(MagicMapper::class),
			$this->fileService,
			$this->createMock(IUserSession::class)
		);
	}//end setUp()

	/**
	 * A source file.
	 *
	 * @return File&MockObject
	 */
	private function sourceFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('getContent')->willReturn('bytes');

		return $file;
	}//end sourceFile()

	/**
	 * Call a private method of the handler.
	 *
	 * @param string $method The method.
	 *
	 * @return array The result.
	 */
	private function invoke(string $method): array {
		$reflection = new ReflectionMethod(MergeHandler::class, $method);
		$arguments = [new ObjectEntity()];
		if ($method === 'transferObjectFiles') {
			$arguments[] = new ObjectEntity();
		}

		return $reflection->invokeArgs($this->handler, $arguments);
	}//end invoke()

	/**
	 * A source file that survives its delete is a failed transfer, and the copy is taken back.
	 *
	 * @return void
	 */
	public function testATransferThatCouldNotRemoveTheSourceIsReportedAsFailedAndTheCopyIsTakenBack(): void {
		$copy = $this->createMock(File::class);
		$this->fileService->method('getFiles')->willReturn([$this->sourceFile()]);
		$this->fileService->method('addFile')->willReturn($copy);

		$deleted = [];
		$this->fileService->method('deleteFile')->willReturnCallback(
			function ($file) use (&$deleted, $copy): bool {
				$deleted[] = $file;

				return $file === $copy;
			}
		);

		$result = $this->invoke(method: 'transferObjectFiles');

		$this->assertSame(0, $result['transferred']);
		$this->assertFalse($result['files'][0]['success']);
		$this->assertSame('transfer_failed', $result['files'][0]['action']);
		$this->assertContains($copy, $deleted);
	}//end testATransferThatCouldNotRemoveTheSourceIsReportedAsFailedAndTheCopyIsTakenBack()

	/**
	 * A delete the source refused is a failed delete, not a deleted file.
	 *
	 * @return void
	 */
	public function testADeleteOfAFileThatIsStillThereIsReportedAsFailed(): void {
		$this->fileService->method('getFiles')->willReturn([$this->sourceFile()]);
		$this->fileService->method('deleteFile')->willReturn(false);

		$result = $this->invoke(method: 'deleteObjectFiles');

		$this->assertSame(0, $result['deleted']);
		$this->assertSame('delete_failed', $result['files'][0]['action']);
	}//end testADeleteOfAFileThatIsStillThereIsReportedAsFailed()

	/**
	 * Control: a file that is really deleted is a transfer and a delete.
	 *
	 * @return void
	 */
	public function testAFileThatWasRemovedIsReportedAsDone(): void {
		$this->fileService->method('getFiles')->willReturn([$this->sourceFile()]);
		$this->fileService->method('addFile')->willReturn($this->createMock(File::class));
		$this->fileService->method('deleteFile')->willReturn(true);

		$this->assertSame(1, $this->invoke(method: 'transferObjectFiles')['transferred']);
		$this->assertSame(1, $this->invoke(method: 'deleteObjectFiles')['deleted']);
	}//end testAFileThatWasRemovedIsReportedAsDone()
}//end class
