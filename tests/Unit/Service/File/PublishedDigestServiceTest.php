<?php

/**
 * PublishedDigestService's failure paths: whatever cannot be read, recorded or
 * made public leaves the file private, and closing a record never blocks an
 * unpublication.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-files-digest-is-recorded-and-sealed-when-it-becomes-public-req-pfd-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\PublishedDigestService;
use OCP\Files\File;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\PublishedDigestService
 * @uses   \OCA\OpenRegister\Db\FileDigest
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class PublishedDigestServiceTest extends TestCase {

	private FileDigestMapper&MockObject $digests;

	private AuditTrailMapperInterface&MockObject $auditTrails;

	private IFilesMetadataManager&MockObject $metadataManager;

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private PublishedDigestService $service;

	/**
	 * Whether the share closure ran.
	 */
	private bool $madePublic = false;

	protected function setUp(): void {
		parent::setUp();

		$this->digests = $this->createMock(FileDigestMapper::class);
		$this->digests->method('insert')->willReturnArgument(0);
		$this->auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$this->auditTrails->method('buildEntry')->willReturnCallback(static function (): AuditTrail {
			$row = new AuditTrail();
			$row->setUuid('row-uuid');
			return $row;
		});
		$this->metadataManager = $this->createMock(IFilesMetadataManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->madePublic = false;

		$this->service = new PublishedDigestService($this->digests, $this->auditTrails, $this->metadataManager, $this->db, $this->logger);
	}//end setUp()

	public function testAFileThatThrowsOnOpenIsNotPublished(): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willThrowException(new RuntimeException('storage offline'));
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertRefused(file: $file, message: 'its digest could not be computed: storage offline');
	}//end testAFileThatThrowsOnOpenIsNotPublished()

	public function testAFileThatCannotBeOpenedIsNotPublished(): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(false);
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertRefused(file: $file, message: 'the file cannot be opened for reading');
	}//end testAFileThatCannotBeOpenedIsNotPublished()

	public function testAFailedMetadataWriteKeepsTheFilePrivate(): void {
		$metadata = $this->createMock(IFilesMetadata::class);
		$this->metadataManager->method('getMetadata')->willReturn($metadata);
		$this->metadataManager->method('saveMetadata')->willThrowException(new RuntimeException('metadata locked'));
		$this->auditTrails->expects($this->never())->method('insertAuditTrails');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->assertRefused(file: $this->readableFile(), message: 'its digest could not be recorded: metadata locked');
	}//end testAFailedMetadataWriteKeepsTheFilePrivate()

	public function testAShareThatCannotBeCreatedRollsTheSealBack(): void {
		$this->metadataManager->method('getMetadata')->willReturn($this->createMock(IFilesMetadata::class));
		$this->auditTrails->expects($this->once())->method('insertAuditTrails');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->publishSealed(
				object: $this->object(),
				file: $this->readableFile(),
				makePublic: static function (): void {
					throw new Exception('Failed to create share link: db down');
				}
			);
			$this->fail('the publication must fail');
		} catch (Exception $e) {
			$this->assertSame('Failed to create share link: db down', $e->getMessage());
		}
	}//end testAShareThatCannotBeCreatedRollsTheSealBack()

	public function testAFailedCloseIsLoggedAndTheUnpublicationStands(): void {
		$this->digests->method('closeOpenForFile')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Could not close the digest record of an unpublished file: db down'));

		$this->service->closePublication(fileId: 42);
	}//end testAFailedCloseIsLoggedAndTheUnpublicationStands()

	public function testAFileWithoutMetadataHasNoDigestToRemove(): void {
		$this->digests->expects($this->once())->method('closeOpenForFile')->with(42);
		$this->metadataManager->method('getMetadata')->willThrowException(new FilesMetadataNotFoundException('none'));
		$this->metadataManager->expects($this->never())->method('saveMetadata');
		$this->logger->expects($this->never())->method('error');

		$this->service->closePublication(fileId: 42);
	}//end testAFileWithoutMetadataHasNoDigestToRemove()

	public function testMetadataWithoutTheDigestIsLeftAlone(): void {
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')->with(PublishedDigestService::METADATA_KEY)->willReturn(false);
		$metadata->expects($this->never())->method('unset');
		$this->metadataManager->method('getMetadata')->willReturn($metadata);
		$this->metadataManager->expects($this->never())->method('saveMetadata');

		$this->service->closePublication(fileId: 42);
	}//end testMetadataWithoutTheDigestIsLeftAlone()

	/**
	 * Publishing fails with a message naming the reason, and the share closure never runs.
	 */
	private function assertRefused(File $file, string $message): void {
		try {
			$this->service->publishSealed(
				object: $this->object(),
				file: $file,
				makePublic: function (): void {
					$this->madePublic = true;
				}
			);
			$this->fail('the publication must fail');
		} catch (Exception $e) {
			$this->assertStringContainsString('The file was not published', $e->getMessage());
			$this->assertStringContainsString($message, $e->getMessage());
		}

		$this->assertFalse($this->madePublic, 'no public share was created');
	}//end assertRefused()

	private function readableFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('fopen')->willReturnCallback(static function () {
			$stream = fopen('php://memory', 'w+');
			fwrite($stream, 'bytes');
			rewind($stream);
			return $stream;
		});
		return $file;
	}//end readableFile()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		return $object;
	}//end object()
}//end class
