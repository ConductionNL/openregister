<?php

/**
 * TextExtractionFilesystemContextTest
 *
 * Covers WOO-576: text extraction must establish the owner's filesystem context
 * itself instead of leaning on the Nextcloud 34+ fallback in
 * Root::getByIdInPath(), and the backfill must not stall forever on a handful of
 * unreadable files with low file ids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Filesystem-context and backfill-progress tests for TextExtractionService.
 */
class TextExtractionFilesystemContextTest extends TestCase {
	private TextExtractionService $service;
	private FileMapper&MockObject $fileMapper;
	private IRootFolder&MockObject $rootFolder;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new TextExtractionService(
			$this->fileMapper,
			$this->createMock(ChunkMapper::class),
			$this->rootFolder,
			$this->createMock(IDBConnection::class),
			$this->logger,
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(EntityRecognitionHandler::class),
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(RiskLevelService::class),
			$this->createMock(EmlParser::class),
			new SpreadsheetExtractor($this->logger),
			new PdfExtractor($this->logger),
			new WordExtractor($this->logger)
		);
	}//end setUp()

	/**
	 * Invoke a private method on the service under test.
	 *
	 * @param string $name Method name.
	 * @param array  $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function invoke(string $name, array $args) {
		$method = new ReflectionMethod(TextExtractionService::class, $name);
		$method->setAccessible(true);

		return $method->invokeArgs($this->service, $args);
	}//end invoke()

	// =========================================================================
	// resolveFileNode — the filesystem context itself
	// =========================================================================

	/**
	 * The heart of WOO-576: with an owner known, the lookup goes through that
	 * user's folder — which sets up their mounts — and the bare root lookup is
	 * never reached. On Nextcloud 33 and below the root lookup returns nothing
	 * in a background job, so relying on it is exactly the bug.
	 *
	 * @return void
	 */
	public function testResolvesThroughOwnerFolderAndNeverTouchesRoot(): void {
		$file = $this->createMock(File::class);
		$userFolder = $this->createMock(Folder::class);

		$userFolder->expects($this->once())
			->method('getById')
			->with(1408)
			->willReturn([$file]);

		$this->rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		// The NC 34+ fallback must not be what makes this work.
		$this->rootFolder->expects($this->never())->method('getById');

		$this->assertSame($file, $this->invoke('resolveFileNode', [1408, 'alice']));
	}//end testResolvesThroughOwnerFolderAndNeverTouchesRoot()

	/**
	 * A file that is not in the owner's folder still falls back to the root
	 * lookup, so nothing that worked before regresses.
	 *
	 * @return void
	 */
	public function testFallsBackToRootWhenOwnerFolderHasNoMatch(): void {
		$file = $this->createMock(File::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->rootFolder->expects($this->once())
			->method('getById')
			->with(1408)
			->willReturn([$file]);

		$this->assertSame($file, $this->invoke('resolveFileNode', [1408, 'alice']));
	}//end testFallsBackToRootWhenOwnerFolderHasNoMatch()

	/**
	 * Without an owner — an unusual storage id, a group folder — the behaviour is
	 * the old one: straight to the root lookup, no user folder attempted.
	 *
	 * @return void
	 */
	public function testGoesStraightToRootWhenOwnerIsUnknown(): void {
		$file = $this->createMock(File::class);

		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->rootFolder->expects($this->once())->method('getById')->willReturn([$file]);

		$this->assertSame($file, $this->invoke('resolveFileNode', [1408, null]));
	}//end testGoesStraightToRootWhenOwnerIsUnknown()

	/**
	 * An owner that no longer exists must not abort the extraction: it is logged
	 * as a warning and the root lookup still gets its turn.
	 *
	 * @return void
	 */
	public function testWarnsAndFallsBackWhenUserFolderThrows(): void {
		$file = $this->createMock(File::class);

		$this->rootFolder->method('getUserFolder')
			->willThrowException(new NotFoundException('no such user'));

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Could not set up filesystem for owner'));

		$this->rootFolder->expects($this->once())->method('getById')->willReturn([$file]);

		$this->assertSame($file, $this->invoke('resolveFileNode', [1408, 'ghost']));
	}//end testWarnsAndFallsBackWhenUserFolderThrows()

	/**
	 * Nowhere to be found in either place is still an error.
	 *
	 * @return void
	 */
	public function testThrowsWhenTheFileIsNowhere(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
		$this->rootFolder->method('getById')->willReturn([]);

		$this->expectExceptionMessage('File not found in Nextcloud file system');

		$this->invoke('resolveFileNode', [1408, 'alice']);
	}//end testThrowsWhenTheFileIsNowhere()

	/**
	 * A folder node where a file was expected is rejected.
	 *
	 * @return void
	 */
	public function testThrowsWhenNodeIsNotAFile(): void {
		$folderNode = $this->createMock(Folder::class);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$folderNode]);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->expectExceptionMessage('Node is not a file');

		$this->invoke('resolveFileNode', [1408, 'alice']);
	}//end testThrowsWhenNodeIsNotAFile()

	// =========================================================================
	// performTextExtraction — the owner actually reaches the resolver
	// =========================================================================

	/**
	 * End to end at unit level: the owner that FileMapper derived from the
	 * storage id is what the extraction sets the filesystem up with.
	 *
	 * @return void
	 */
	public function testExtractionUsesTheOwnerFromTheFileMetadata(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('de inhoud van een testdocument');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->willReturn([$file]);

		$this->rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('bob')
			->willReturn($userFolder);
		$this->rootFolder->expects($this->never())->method('getById');

		$text = $this->invoke(
			'performTextExtraction',
			[
				1408,
				[
					'mimetype' => 'text/plain',
					'path' => 'files/Documenten/test.txt',
					'owner' => 'bob',
				],
			]
		);

		$this->assertSame('de inhoud van een testdocument', $text);
	}//end testExtractionUsesTheOwnerFromTheFileMetadata()

	// =========================================================================
	// extractPendingFiles — the backfill must keep moving
	// =========================================================================

	/**
	 * The head-of-line regression from WOO-576: unreadable files keep matching
	 * findUntrackedFiles() because nothing records their failure, and the fileid
	 * ordering parks them at the front of every window. The backfill must step
	 * past them instead of re-reading the same block forever.
	 *
	 * @return void
	 */
	public function testBackfillStepsOverFilesThatKeepFailing(): void {
		$seenOffsets = [];

		// Every file fails: getFile() returning null makes extractFile() throw.
		$this->fileMapper->method('getFile')->willReturn(null);

		$this->fileMapper->method('findUntrackedFiles')
			->willReturnCallback(
				function (int $limit, int $offset = 0) use (&$seenOffsets): array {
					$seenOffsets[] = $offset;

					if ($offset >= 6) {
						return [];
					}

					return [
						['fileid' => ($offset + 34), 'name' => 'Readme.md'],
						['fileid' => ($offset + 35), 'name' => 'Welcome.docx'],
						['fileid' => ($offset + 36), 'name' => 'Reasons.pdf'],
					];
				}
			);

		$result = $this->service->extractPendingFiles(3);

		$this->assertSame([0, 3, 6], $seenOffsets, 'offset moet met het aantal mislukte bestanden opschuiven');
		$this->assertSame(0, $result['processed']);
		$this->assertSame(6, $result['failed']);
		$this->assertSame(6, $result['total']);
	}//end testBackfillStepsOverFilesThatKeepFailing()

	/**
	 * A window shorter than the limit means the pool is exhausted; no second
	 * query is fired.
	 *
	 * @return void
	 */
	public function testBackfillStopsWhenTheWindowIsShorterThanTheLimit(): void {
		$this->fileMapper->method('getFile')->willReturn(null);

		$this->fileMapper->expects($this->once())
			->method('findUntrackedFiles')
			->willReturn([['fileid' => 34, 'name' => 'Readme.md']]);

		$result = $this->service->extractPendingFiles(10);

		$this->assertSame(1, $result['failed']);
		$this->assertSame(1, $result['total']);
	}//end testBackfillStopsWhenTheWindowIsShorterThanTheLimit()
}//end class
