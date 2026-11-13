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

use DateTime;
use OCA\OpenRegister\Files\Db\File as OrFile;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\File\FileSession;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A formatted file carries its first share's links and, when an OR-side row
 * exists, that row's metadata: the public fields for everyone, the download
 * count and the OR-side lock for authenticated callers only.
 *
 * @covers \OCA\OpenRegister\Service\File\FileFormattingHandler
 * @uses   \OCA\OpenRegister\Files\Db\File
 * @uses   \OCA\OpenRegister\Service\File\FileLockEnvelope
 * @uses   \OCA\OpenRegister\Service\File\FileListFilter
 * @uses   \OCA\OpenRegister\Service\File\FileSession
 */
class FileFormattingHandlerOrMetadataTest extends TestCase {

	/**
	 * @var ILockManager&MockObject
	 */
	private ILockManager $lockManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var FileMapperInterface&MockObject
	 */
	private FileMapperInterface $fileMapper;

	/**
	 * @var FileService&MockObject
	 */
	private FileService $fileService;

	protected function setUp(): void {
		parent::setUp();

		$this->lockManager = $this->createMock(ILockManager::class);
		$this->lockManager->method('isLockProviderAvailable')->willReturn(false);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->fileMapper = $this->createMock(FileMapperInterface::class);
		$this->fileService = $this->createMock(FileService::class);
	}//end setUp()

	private function handler(?FileMapperInterface $fileMapper): FileFormattingHandler {
		$handler = new FileFormattingHandler(
			$this->lockManager,
			new FileSession($this->userSession, $this->createMock(IGroupManager::class), $this->logger),
			$fileMapper
		);
		$handler->setFileService($this->fileService);

		return $handler;
	}//end handler()

	private function node(int $id = 7): Node&MockObject {
		$file = $this->createMock(Node::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getPath')->willReturn('/admin/files/report.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getExtension')->willReturn('pdf');
		$file->method('getSize')->willReturn(2048);
		$file->method('getEtag')->willReturn('etag-' . $id);
		$file->method('getCreationTime')->willReturn(1700000000);
		$file->method('getUploadTime')->willReturn(1700000100);

		return $file;
	}//end node()

	private function anonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
	}//end anonymous()

	private function authenticated(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end authenticated()

	private function orRow(): OrFile {
		$row = new OrFile();
		$row->setDescription('Annual report');
		$row->setCategory('finance');
		$row->setLabels(['public', 'year:2025']);
		$row->setDownloadCount(12);
		$row->setPublished(new DateTime('2020-01-01T00:00:00+00:00'));

		return $row;
	}//end orRow()

	public function testTheFirstShareGivesTheAccessAndDownloadUrl(): void {
		$this->anonymous();
		$first = $this->createMock(IShare::class);
		$second = $this->createMock(IShare::class);
		$file = $this->node();
		$this->fileService->method('findShares')->with($file)->willReturn([$first, $second]);
		$this->fileService->expects($this->once())->method('getShareLink')->with($first)->willReturn('https://cloud.test/s/abc');
		$this->fileService->method('getFileTags')->willReturn([]);

		$result = $this->handler(null)->formatFile($file);

		$this->assertSame('https://cloud.test/s/abc', $result['accessUrl']);
		$this->assertSame('https://cloud.test/s/abc/download', $result['downloadUrl']);
	}//end testTheFirstShareGivesTheAccessAndDownloadUrl()

	public function testWithoutAMapperThePublicationFieldsStayUnpublished(): void {
		$this->anonymous();
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->with('7')->willReturn(['draft']);

		$result = $this->handler(null)->formatFile($this->node());

		$this->assertNull($result['accessUrl']);
		$this->assertNull($result['downloadUrl']);
		$this->assertNull($result['published']);
		$this->assertNull($result['depublished']);
		$this->assertFalse($result['isPublished']);
		$this->assertSame(['draft'], $result['labels']);
		$this->assertArrayNotHasKey('description', $result);
	}//end testWithoutAMapperThePublicationFieldsStayUnpublished()

	public function testAnAnonymousCallerGetsOnlyThePublicFieldsOfTheRow(): void {
		$this->anonymous();
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn(['draft', 'public']);
		$row = $this->orRow();
		$row->setLockedBy('alice');
		$this->fileMapper->expects($this->once())->method('findByFileId')->with(7)->willReturn($row);

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertSame('Annual report', $result['description']);
		$this->assertSame('finance', $result['category']);
		$this->assertSame((new DateTime('2020-01-01T00:00:00+00:00'))->format('c'), $result['published']);
		$this->assertNull($result['depublished']);
		$this->assertTrue($result['isPublished']);
		// Tag labels first, OR labels appended without duplicates; `year:2025` becomes a field.
		$this->assertSame(['draft', 'public'], $result['labels']);
		$this->assertSame('2025', $result['year']);
		$this->assertArrayNotHasKey('downloadCount', $result);
		$this->assertArrayNotHasKey('orLock', $result);
		$this->assertArrayNotHasKey('locked', $result);
	}//end testAnAnonymousCallerGetsOnlyThePublicFieldsOfTheRow()

	public function testAnAuthenticatedCallerAlsoGetsTheDownloadCountAndTheOrLock(): void {
		$this->authenticated();
		$this->lockManager->method('getLocks')->willReturn([]);
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn([]);
		$lockedAt = new DateTime('2026-03-01T10:00:00+00:00');
		$row = $this->orRow();
		$row->setLockedBy('alice');
		$row->setLockedAt($lockedAt);
		$this->fileMapper->method('findByFileId')->with(7)->willReturn($row);

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertSame(12, $result['downloadCount']);
		$this->assertSame(
			['lockedBy' => 'alice', 'lockedAt' => $lockedAt->format('c'), 'lockExpires' => null],
			$result['orLock']
		);
		$this->assertFalse($result['locked']);
	}//end testAnAuthenticatedCallerAlsoGetsTheDownloadCountAndTheOrLock()

	public function testAnUnlockedRowAddsNoOrLock(): void {
		$this->authenticated();
		$this->lockManager->method('getLocks')->willReturn([]);
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn([]);
		$this->fileMapper->method('findByFileId')->willReturn($this->orRow());

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertSame(12, $result['downloadCount']);
		$this->assertArrayNotHasKey('orLock', $result);
	}//end testAnUnlockedRowAddsNoOrLock()

	public function testARowWithoutLabelsKeepsTheTagLabels(): void {
		$this->anonymous();
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn(['draft']);
		$row = $this->orRow();
		$row->setLabels(null);
		$this->fileMapper->method('findByFileId')->willReturn($row);

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertSame(['draft'], $result['labels']);
	}//end testARowWithoutLabelsKeepsTheTagLabels()

	public function testNoRowLeavesTheMetadataUnenriched(): void {
		$this->anonymous();
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn([]);
		$this->fileMapper->method('findByFileId')->willReturn(null);
		$this->logger->expects($this->never())->method('warning');

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertArrayNotHasKey('description', $result);
		$this->assertFalse($result['isPublished']);
	}//end testNoRowLeavesTheMetadataUnenriched()

	public function testAFailedLookupIsLoggedAndTheFileIsStillFormatted(): void {
		$this->anonymous();
		$this->fileService->method('findShares')->willReturn([]);
		$this->fileService->method('getFileTags')->willReturn([]);
		$this->fileMapper->method('findByFileId')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('OR-side metadata lookup failed'),
			$this->callback(static fn (array $context): bool => $context['fileId'] === 7 && $context['error'] === 'db down')
		);

		$result = $this->handler($this->fileMapper)->formatFile($this->node());

		$this->assertSame(7, $result['id']);
		$this->assertSame('report.pdf', $result['title']);
		$this->assertArrayNotHasKey('description', $result);
		$this->assertNull($result['published']);
	}//end testAFailedLookupIsLoggedAndTheFileIsStillFormatted()
}//end class
