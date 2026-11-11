<?php

/**
 * FileMetadataFormSaveGoldenTest
 *
 * Pins, call by call, what FileMetadataFormHandler::save() does for one row:
 * the lookup, the rename before the description change, the audit entry and
 * its data, the answer, and the refusal of a file that is not on the object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\File as DbFile;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileMetadataFormHandler;
use OCA\OpenRegister\Service\File\FileMetadataChange;
use OCA\OpenRegister\Service\File\FileRename;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

/**
 * Golden call sequence of one FileMetadataFormHandler row.
 */
final class FileMetadataFormSaveGoldenTest extends TestCase {

	/**
	 * File-service and audit calls, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The handler over a file service where file 7 is missing and every other file is "a.pdf" / null description.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return FileMetadataFormHandler
	 */
	private function handler(ObjectEntity $object): FileMetadataFormHandler {
		$files = $this->createMock(FileService::class);
		$files->method('getFile')->willReturnCallback(
			function (ObjectEntity $owner, string $file) use ($object): ?File {
				$this->events[] = ['getFile', $owner === $object, $file];
				return $file === '7' ? null : $this->createMock(File::class);
			}
		);
		$files->method('formatFile')->willReturnCallback(
			function (): array {
				$this->events[] = ['formatFile'];
				return ['name' => 'a.pdf', 'description' => null];
			}
		);
		$files->method('renameAttachedFile')->willReturnCallback(
			function (FileRename $rename) use ($object): File {
				$this->events[] = ['rename', $rename->object === $object, $rename->fileId, $rename->newName];
				return $this->createMock(File::class);
			}
		);
		$files->method('changeFileMetadata')->willReturnCallback(
			function (FileMetadataChange $change): DbFile {
				$this->events[] = ['describe', $change->fileId, $change->description];
				return new DbFile();
			}
		);
		$audit = $this->createMock(FileAuditHandler::class);
		$audit->method('logFileAction')->willReturnCallback(
			function (ObjectEntity $owner, int $fileId, string $action, array $data) use ($object): void {
				$this->events[] = ['audit', $owner === $object, $fileId, $action, $data];
			}
		);
		$files->method('getAuditHandler')->willReturn($audit);

		return new FileMetadataFormHandler($files);
	}//end handler()

	/**
	 * Rename then describe, one audit entry with both diffs; an empty name and a null description change nothing.
	 *
	 * @return void
	 */
	public function testOneRowSequence(): void {
		$object = new ObjectEntity();
		$result = $this->handler($object)->save(
			object: $object,
			entries: [
				['fileId' => '3', 'name' => 'b.pdf', 'description' => 'Signed'],
				['fileId' => 4, 'name' => '', 'description' => ''],
				['fileId' => 7, 'name' => 'c.pdf'],
			]
		);

		$diff = ['name' => ['old' => 'a.pdf', 'new' => 'b.pdf'], 'description' => ['old' => null, 'new' => 'Signed']];
		self::assertSame(
			[
				['getFile', true, '3'],
				['formatFile'],
				['rename', true, 3, 'b.pdf'],
				['describe', 3, 'Signed'],
				['audit', true, 3, FileMetadataFormHandler::ACTION, $diff],
				['getFile', true, '4'],
				['formatFile'],
				['getFile', true, '7'],
			],
			$this->events
		);
		self::assertSame([['fileId' => 3] + $diff], $result['changed']);
		self::assertSame([4], $result['unchanged']);
		self::assertSame([['fileId' => 7, 'error' => 'File 7 is not on this object.']], $result['failed']);
	}//end testOneRowSequence()
}//end class
