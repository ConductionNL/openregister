<?php

/**
 * ObjectFilesArchive unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\BulkDownloadArchive;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\ObjectFilesArchive;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\ObjectFilesArchive
 */
final class ObjectFilesArchiveTest extends TestCase {

	public function testItIsTheCorePort(): void {
		$this->assertInstanceOf(ObjectFilesArchiveInterface::class, new ObjectFilesArchive($this->createMock(FileService::class)));
	}

	public function testTheZipIsTheFileServicesWithTheRequestedName(): void {
		$object = new ObjectEntity();
		$zip = ['path' => '/tmp/x.zip', 'filename' => 'x.zip', 'mimeType' => 'application/zip'];
		$files = $this->createMock(FileService::class);
		$files->expects($this->once())->method('createObjectFilesZip')->with($object, 'x')->willReturn($zip);

		$this->assertSame($zip, (new ObjectFilesArchive($files))->createZip($object, 'x'));
	}

	public function testAFailingZipReachesTheCaller(): void {
		$files = $this->createMock(FileService::class);
		$files->method('createObjectFilesZip')->willThrowException(new \Exception('Disk full'));

		$this->expectExceptionMessage('Disk full');
		(new ObjectFilesArchive($files))->createZip(new ObjectEntity(), null);
	}

	public function testTheDownloadIsOneAuditEntryNamingEveryFile(): void {
		$object = new ObjectEntity();
		$first = $this->createMock(File::class);
		$first->method('getId')->willReturn(11);
		$first->method('getName')->willReturn('a.txt');
		$second = $this->createMock(File::class);
		$second->method('getId')->willReturn(12);
		$second->method('getName')->willReturn('b.txt');

		$audit = $this->createMock(FileAuditHandler::class);
		$audit->expects($this->once())->method('logBulkDownload')->with(
			$object,
			new BulkDownloadArchive(fileIds: [11, 12], fileNames: ['a.txt', 'b.txt'], zipName: 'x.zip', totalBytes: null)
		);
		$files = $this->createMock(FileService::class);
		$files->method('getFiles')->with($object)->willReturn([$first, $second]);
		$files->method('getAuditHandler')->willReturn($audit);

		(new ObjectFilesArchive($files))->recordDownload($object, ['filename' => 'x.zip']);
	}

	public function testAFailingTrailReachesTheCaller(): void {
		$files = $this->createMock(FileService::class);
		$files->method('getFiles')->willThrowException(new RuntimeException('no trail'));

		$this->expectException(RuntimeException::class);
		(new ObjectFilesArchive($files))->recordDownload(new ObjectEntity(), ['filename' => 'x.zip']);
	}
}
