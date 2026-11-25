<?php

/**
 * The failure paths of ArchiveTextExtractor: an archive that is not a zip, a
 * stream that cannot be opened, bytes that are not an archive, and members that
 * are missing or fail to read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\ArchiveContainers;
use OCA\OpenRegister\Files\Service\TextExtraction\ArchiveExtraction;
use OCA\OpenRegister\Files\Service\TextExtraction\ArchiveTextExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCA\OpenRegister\Service\File\ArchiveListing;
use OCA\OpenRegister\Service\File\ArchiveMemberEnumerator;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

#[CoversClass(ArchiveTextExtractor::class)]
#[UsesClass(ArchiveExtraction::class)]
#[UsesClass(ArchiveContainers::class)]
#[UsesClass(ArchiveListing::class)]
class ArchiveTextExtractorFailureTest extends TestCase {

	/** @var ArchiveMemberEnumerator&MockObject */
	private ArchiveMemberEnumerator $enumerator;

	/** @var PdfExtractor&MockObject */
	private PdfExtractor $pdf;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ArchiveTextExtractor The subject under test. */
	private ArchiveTextExtractor $extractor;

	/**
	 * @var string[] Temporary files to remove after each test.
	 */
	private array $paths = [];

	protected function setUp(): void {
		$this->enumerator = $this->createMock(ArchiveMemberEnumerator::class);
		$this->pdf        = $this->createMock(PdfExtractor::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->extractor  = new ArchiveTextExtractor(
			$this->enumerator,
			$this->pdf,
			$this->createMock(SpreadsheetExtractor::class),
			$this->createMock(EmlParser::class),
			$this->logger
		);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * Write a zip holding the given members and return its path.
	 *
	 * @param array<string,string> $members Member name => bytes.
	 *
	 * @return string
	 */
	private function zip(array $members): string {
		$path          = (string) tempnam(sys_get_temp_dir(), 'or-test-zip-');
		$this->paths[] = $path;
		$zip           = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($members as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		return $path;
	}//end zip()

	/**
	 * A listing of LISTED members with the given mime types.
	 *
	 * @param array<string,string> $members Member path => mime type.
	 *
	 * @return ArchiveListing
	 */
	private function listing(array $members): ArchiveListing {
		$listing = new ArchiveListing();
		foreach ($members as $path => $mimeType) {
			$listing->add(['path' => $path, 'status' => ArchiveListing::LISTED, 'mimeType' => $mimeType, 'size' => 1]);
		}

		return $listing;
	}//end listing()

	public function testANonZipFileIsReportedUnsupportedWithoutOpeningIt(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/x-7z-compressed');
		$file->expects($this->never())->method('fopen');
		$this->enumerator->expects($this->never())->method('enumeratePath');

		$extraction = $this->extractor->extractFile(file: $file);

		$this->assertSame('application/x-7z-compressed', $extraction->archiveType);
		$this->assertFalse($extraction->supported);
		$this->assertSame(ArchiveExtraction::UNSUPPORTED, $extraction->status());
		$this->assertSame([], $extraction->unread());
	}//end testANonZipFileIsReportedUnsupportedWithoutOpeningIt()

	public function testAZipWhoseStreamCannotBeOpenedIsUnreadableUnderItsName(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/zip');
		$file->method('getName')->willReturn('bundle.zip');
		$file->expects($this->once())->method('fopen')->with('r')->willReturn(false);
		$this->enumerator->expects($this->never())->method('enumeratePath');

		$extraction = $this->extractor->extractFile(file: $file);

		$this->assertSame('application/zip', $extraction->archiveType);
		$this->assertSame([['path' => 'bundle.zip', 'reason' => 'unreadable']], $extraction->unread());
		$this->assertSame(ArchiveExtraction::PARTIAL, $extraction->status());
		$this->assertSame([], $extraction->texts());
	}//end testAZipWhoseStreamCannotBeOpenedIsUnreadableUnderItsName()

	public function testBytesThatAreNoZipAreOneUnreadableEntryWithAnEmptyPath(): void {
		$path          = (string) tempnam(sys_get_temp_dir(), 'or-test-nozip-');
		$this->paths[] = $path;
		file_put_contents($path, 'this is plain text, not an archive');
		$this->enumerator->expects($this->once())->method('enumeratePath')
			->with($path, null)
			->willReturn(new ArchiveListing());

		$extraction = $this->extractor->extractPath(path: $path, archiveType: 'application/x-zip-compressed');

		$this->assertSame('application/x-zip-compressed', $extraction->archiveType);
		$this->assertSame([['path' => '', 'reason' => 'unreadable']], $extraction->unread());
		$this->assertSame([], $extraction->texts());
	}//end testBytesThatAreNoZipAreOneUnreadableEntryWithAnEmptyPath()

	public function testAListedMemberMissingFromTheArchiveIsUnreadable(): void {
		$path = $this->zip(['notes.txt' => 'kept text']);
		$this->enumerator->method('enumeratePath')
			->willReturn($this->listing(['ghost.txt' => 'text/plain', 'notes.txt' => 'text/plain']));

		$extraction = $this->extractor->extractPath(path: $path);

		$this->assertSame([['path' => 'ghost.txt', 'reason' => 'unreadable']], $extraction->unread());
		$this->assertSame([['path' => 'notes.txt', 'text' => 'kept text']], $extraction->texts());
	}//end testAListedMemberMissingFromTheArchiveIsUnreadable()

	public function testAMemberWhoseReaderThrowsIsLoggedAndUnreadableWhileOthersAreStillRead(): void {
		$path = $this->zip(['broken.pdf' => '%PDF-not-really', 'after.txt' => 'still read']);
		$this->enumerator->method('enumeratePath')
			->willReturn($this->listing(['broken.pdf' => 'application/pdf', 'after.txt' => 'text/plain']));
		$this->pdf->expects($this->once())->method('extractText')
			->with('%PDF-not-really')
			->willThrowException(new RuntimeException('secured pdf'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[ArchiveTextExtractor] An archive member could not be read',
			['mimeType' => 'application/pdf', 'exception' => RuntimeException::class]
		);

		$extraction = $this->extractor->extractPath(path: $path);

		$this->assertSame([['path' => 'broken.pdf', 'reason' => 'unreadable']], $extraction->unread());
		$this->assertSame([['path' => 'after.txt', 'text' => 'still read']], $extraction->texts());
		$this->assertSame(ArchiveExtraction::PARTIAL, $extraction->status());
	}//end testAMemberWhoseReaderThrowsIsLoggedAndUnreadableWhileOthersAreStillRead()
}//end class
