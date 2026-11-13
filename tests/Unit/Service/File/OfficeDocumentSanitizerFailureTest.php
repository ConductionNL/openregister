<?php

/**
 * OfficeDocumentSanitizerFailureTest
 *
 * Unit tests for the refusal paths of
 * {@see \OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer}: a target
 * that is not a file, no temp file, an unreadable source or temp stream, a
 * failing strategy, and the temp-file extension per MIME type.
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

namespace Unit\Service\File;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\Sanitizer\SanitizerInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\OfficeDocumentSanitizer
 * @uses   \OCA\OpenRegister\Exception\SanitizationException
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationResult
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 */
class OfficeDocumentSanitizerFailureTest extends TestCase {

	/**
	 * Mock root folder.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * Mock temp manager.
	 *
	 * @var ITempManager&MockObject
	 */
	private ITempManager&MockObject $tempManager;

	/**
	 * Temp paths created by a test, removed afterwards.
	 *
	 * @var list<string>
	 */
	private array $tempPaths = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder  = $this->createMock(IRootFolder::class);
		$this->tempManager = $this->createMock(ITempManager::class);
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempPaths as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The service with one strategy.
	 *
	 * @param SanitizerInterface $strategy The strategy.
	 *
	 * @return OfficeDocumentSanitizer The service.
	 */
	private function service(SanitizerInterface $strategy): OfficeDocumentSanitizer {
		return new OfficeDocumentSanitizer($this->rootFolder, $this->tempManager, new NullLogger(), [$strategy]);
	}//end service()

	/**
	 * A strategy supporting every MIME type.
	 *
	 * @return SanitizerInterface&MockObject The strategy.
	 */
	private function strategy(): SanitizerInterface&MockObject {
		$strategy = $this->createMock(SanitizerInterface::class);
		$strategy->method('supports')->willReturn(true);
		return $strategy;
	}//end strategy()

	/**
	 * A file of one MIME type whose read stream is the given value.
	 *
	 * @param string $mime   The MIME type.
	 * @param mixed  $stream What fopen returns.
	 *
	 * @return void
	 */
	private function fileWith(string $mime, mixed $stream): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('fopen')->willReturn($stream);
		$this->rootFolder->method('getById')->willReturn([$file]);
	}//end fileWith()

	/**
	 * A readable stream holding some content.
	 *
	 * @return resource The stream.
	 */
	private function source() {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, 'document bytes');
		rewind($stream);
		return $stream;
	}//end source()

	/**
	 * A fresh temp path that is cleaned up after the test.
	 *
	 * @return string The path.
	 */
	private function tempPath(): string {
		$path = tempnam(sys_get_temp_dir(), 'or-sanitize-');
		$this->tempPaths[] = $path;
		return $path;
	}//end tempPath()

	/**
	 * Run sanitize and return the refusal it raised.
	 *
	 * @param OfficeDocumentSanitizer $service The service.
	 *
	 * @return SanitizationException The refusal.
	 */
	private function refusal(OfficeDocumentSanitizer $service): SanitizationException {
		try {
			$service->sanitize(11);
		} catch (SanitizationException $e) {
			return $e;
		}

		$this->fail('sanitize() did not refuse');
	}//end refusal()

	public function testAFolderIsRefusedAsNotAFile(): void {
		$this->rootFolder->method('getById')->with(11)->willReturn([$this->createMock(Folder::class)]);

		$e = $this->refusal($this->service($this->strategy()));

		$this->assertSame(SanitizationException::REASON_UNSUPPORTED_MIME, $e->getReason());
		$this->assertSame('Sanitisation target is not a file', $e->getMessage());
	}//end testAFolderIsRefusedAsNotAFile()

	public function testNoTemporaryFileIsAnInternalRefusal(): void {
		$this->fileWith('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $this->source());
		$this->tempManager->method('getTemporaryFile')->willReturn(false);

		$e = $this->refusal($this->service($this->strategy()));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Could not allocate a temporary file for sanitisation', $e->getMessage());
	}//end testNoTemporaryFileIsAnInternalRefusal()

	public function testAnOdtGetsAnOdtTempFileAndAnythingElseADocx(): void {
		$strategy = $this->strategy();
		$strategy->method('sanitize')->willReturn(new SanitizationReport());

		$this->tempManager->expects($this->exactly(2))
			->method('getTemporaryFile')
			->willReturnCallback(
				function (string $extension): string {
					static $expected = ['.odt', '.docx'];
					$this->assertSame(array_shift($expected), $extension);
					return $this->tempPath();
				}
			);

		$odt = $this->createMock(File::class);
		$odt->method('getMimeType')->willReturn('application/vnd.oasis.opendocument.text');
		$odt->method('fopen')->willReturn($this->source());
		$docx = $this->createMock(File::class);
		$docx->method('getMimeType')->willReturn('application/msword');
		$docx->method('fopen')->willReturn($this->source());
		$this->rootFolder->method('getById')->willReturnOnConsecutiveCalls([$odt], [$docx]);

		$service = $this->service($strategy);
		$first = $service->sanitize(1);
		$service->sanitize(2);

		$this->assertSame('document bytes', file_get_contents($first->path), 'the source is copied into the temp file');
	}//end testAnOdtGetsAnOdtTempFileAndAnythingElseADocx()

	public function testAnUnreadableSourceStreamIsAnInternalRefusal(): void {
		$this->fileWith('application/msword', false);
		$this->tempManager->method('getTemporaryFile')->willReturn($this->tempPath());

		$e = $this->refusal($this->service($this->strategy()));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Could not open source file stream for sanitisation', $e->getMessage());
	}//end testAnUnreadableSourceStreamIsAnInternalRefusal()

	public function testAnUnwritableTempFileIsAnInternalRefusal(): void {
		$this->fileWith('application/msword', $this->source());
		$this->tempManager->method('getTemporaryFile')->willReturn(sys_get_temp_dir() . '/or-missing-dir-' . uniqid() . '/x.docx');

		$e = @$this->refusal($this->service($this->strategy()));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Could not open temporary file for writing', $e->getMessage());
	}//end testAnUnwritableTempFileIsAnInternalRefusal()

	public function testAStrategyRefusalIsPassedOnUnchanged(): void {
		$this->fileWith('application/msword', $this->source());
		$this->tempManager->method('getTemporaryFile')->willReturn($this->tempPath());

		$encrypted = new SanitizationException(reason: SanitizationException::REASON_ENCRYPTED, message: 'encrypted');
		$strategy = $this->strategy();
		$strategy->method('sanitize')->willThrowException($encrypted);

		$this->assertSame($encrypted, $this->refusal($this->service($strategy)));
	}//end testAStrategyRefusalIsPassedOnUnchanged()

	public function testAnyOtherStrategyFailureBecomesAnInternalRefusal(): void {
		$this->fileWith('application/msword', $this->source());
		$this->tempManager->method('getTemporaryFile')->willReturn($this->tempPath());

		$boom = new RuntimeException('zip broke');
		$strategy = $this->strategy();
		$strategy->method('sanitize')->willThrowException($boom);

		$e = $this->refusal($this->service($strategy));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Sanitisation surgery failed', $e->getMessage());
		$this->assertSame($boom, $e->getPrevious());
	}//end testAnyOtherStrategyFailureBecomesAnInternalRefusal()
}//end class
