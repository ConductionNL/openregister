<?php

/**
 * OpenRegister - a record's file metadata corrected in one form.
 *
 * Pins the claim the spec makes about the trail: one entry per file actually
 * changed, and none at all for a file the form left alone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\FileMetadataFormHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\FileMetadataFormHandler
 */
final class FileMetadataFormHandlerTest extends TestCase {

	private FileService&MockObject $fileService;

	private FileAuditHandler&MockObject $audit;

	private ObjectEntity $object;

	/**
	 * Wire the collaborators.
	 *
	 * The six files are a stored name and description each, answered by
	 * `formatFile`, so the handler's change detection is exercised rather than
	 * assumed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fileService = $this->createMock(originalClassName: FileService::class);
		$this->audit = $this->createMock(originalClassName: FileAuditHandler::class);
		$this->fileService->method('getAuditHandler')->willReturn($this->audit);

		$this->object = new ObjectEntity();
		$this->object->setUuid('obj-11111111-2222-3333-4444-555555555555');

		$this->fileService->method('getFile')->willReturn($this->createMock(originalClassName: File::class));
		$this->fileService->method('formatFile')->willReturnCallback(
			static function (): array {
				return ['name' => 'scan0007.pdf', 'description' => 'Ingescand'];
			}
		);
	}//end setUp()

	/**
	 * Tidying a dossier before it goes out.
	 *
	 * Three of six files are renamed and saved together, so three are renamed
	 * and three audit entries are written.
	 *
	 * @return void
	 */
	public function testThreeRenamesWriteThreeEntries(): void {
		$this->fileService->expects($this->exactly(3))->method('renameFile');
		$this->audit->expects($this->exactly(3))->method('logFileAction');

		$result = (new FileMetadataFormHandler($this->fileService))->save(
			object: $this->object,
			entries: [
				['fileId' => 1, 'name' => 'Aanvraag.pdf'],
				['fileId' => 2, 'name' => 'Bijlage 1.pdf'],
				['fileId' => 3, 'name' => 'Bijlage 2.pdf'],
				['fileId' => 4, 'name' => 'scan0007.pdf'],
				['fileId' => 5, 'name' => 'scan0007.pdf'],
				['fileId' => 6, 'name' => 'scan0007.pdf'],
			]
		);

		$this->assertCount(3, $result['changed']);
		$this->assertSame([4, 5, 6], $result['unchanged']);
		$this->assertSame([], $result['failed']);
	}//end testThreeRenamesWriteThreeEntries()

	/**
	 * Nothing changed, nothing recorded.
	 *
	 * @return void
	 */
	public function testSavingWithNoEditsWritesNothing(): void {
		$this->fileService->expects($this->never())->method('renameFile');
		$this->fileService->expects($this->never())->method('updateFileMetadata');
		$this->audit->expects($this->never())->method('logFileAction');

		$result = (new FileMetadataFormHandler($this->fileService))->save(
			object: $this->object,
			entries: [
				['fileId' => 1, 'name' => 'scan0007.pdf', 'description' => 'Ingescand'],
				['fileId' => 2],
			]
		);

		$this->assertSame([], $result['changed']);
		$this->assertSame([1, 2], $result['unchanged']);
	}//end testSavingWithNoEditsWritesNothing()

	/**
	 * A changed description writes one entry carrying both values.
	 *
	 * @return void
	 */
	public function testAChangedDescriptionIsRecordedWithBothValues(): void {
		$this->fileService->expects($this->once())->method('updateFileMetadata');

		$result = (new FileMetadataFormHandler($this->fileService))->save(
			object: $this->object,
			entries: [['fileId' => 9, 'description' => 'Getekende aanvraag']]
		);

		$this->assertCount(1, $result['changed']);
		$this->assertSame('Ingescand', $result['changed'][0]['description']['old']);
		$this->assertSame('Getekende aanvraag', $result['changed'][0]['description']['new']);
	}//end testAChangedDescriptionIsRecordedWithBothValues()

	/**
	 * One row failing leaves the others saved.
	 *
	 * A name that collides with one already in the folder must not cost the
	 * person the five renames that did work.
	 *
	 * @return void
	 */
	public function testOneRefusedRowDoesNotRollTheOthersBack(): void {
		$this->fileService->method('renameFile')->willReturnCallback(
			function (ObjectEntity $object, int $fileId, string $newName): File {
				if ($fileId === 2) {
					throw new \Exception('A file with that name already exists');
				}

				return $this->createMock(originalClassName: File::class);
			}
		);

		$result = (new FileMetadataFormHandler($this->fileService))->save(
			object: $this->object,
			entries: [
				['fileId' => 1, 'name' => 'Aanvraag.pdf'],
				['fileId' => 2, 'name' => 'Aanvraag.pdf'],
				['fileId' => 3, 'name' => 'Bijlage.pdf'],
			]
		);

		$this->assertCount(2, $result['changed']);
		$this->assertCount(1, $result['failed']);
		$this->assertSame(2, $result['failed'][0]['fileId']);
	}//end testOneRefusedRowDoesNotRollTheOthersBack()

	/**
	 * A row with no file id is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testARowWithoutAFileIdIsRefused(): void {
		$result = (new FileMetadataFormHandler($this->fileService))->save(
			object: $this->object,
			entries: [['name' => 'Aanvraag.pdf']]
		);

		$this->assertCount(1, $result['failed']);
		$this->assertNull($result['failed'][0]['fileId']);
	}//end testARowWithoutAFileIdIsRefused()
}//end class
