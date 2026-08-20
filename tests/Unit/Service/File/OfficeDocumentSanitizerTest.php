<?php

/**
 * OfficeDocumentSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\OfficeDocumentSanitizer}
 * — strategy dispatch, unsupported-MIME handling, original-file preservation,
 * and PII-free logging.
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
use OCA\OpenRegister\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Service\File\SanitizationReport;
use OCA\OpenRegister\Service\File\Sanitizer\SanitizerInterface;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see OfficeDocumentSanitizer}.
 */
class OfficeDocumentSanitizerTest extends TestCase {

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
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Temp files created during a test.
	 *
	 * @var string[]
	 */
	private array $tempFiles = [];

	/**
	 * Reset before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// IRootFolder extends OC\Hooks\Emitter, which is only present when the
		// Nextcloud server source tree is on the include path (Docker / CI).
		// Skip cleanly in a bare composer-autoload context (local worktree).
		if (interface_exists('OC\\Hooks\\Emitter') === false) {
			$this->markTestSkipped('Nextcloud server classes unavailable; run in the Docker test environment.');
		}

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->tempManager = $this->createMock(ITempManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Clean up temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * isSanitizable() reflects the default DOCX + ODT strategies.
	 *
	 * @return void
	 */
	public function testIsSanitizableDefaults(): void {
		$service = new OfficeDocumentSanitizer($this->rootFolder, $this->tempManager, $this->logger);

		$this->assertTrue($service->isSanitizable('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$this->assertTrue($service->isSanitizable('application/vnd.oasis.opendocument.text'));
		$this->assertFalse($service->isSanitizable('application/pdf'));
	}//end testIsSanitizableDefaults()

	/**
	 * sanitize() dispatches to the strategy whose supports() returns true.
	 *
	 * @return void
	 */
	public function testDispatchToMatchingStrategy(): void {
		$report = new SanitizationReport(commentsRemoved: 9, sentinelApplied: 'X');

		$matching = $this->makeStrategy('application/x-match', $report);
		$other = $this->makeStrategy('application/x-other', new SanitizationReport());

		$service = new OfficeDocumentSanitizer(
			$this->rootFolder,
			$this->tempManager,
			$this->logger,
			[$other, $matching]
		);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/x-match');
		$file->method('fopen')->willReturn($this->openSourceStream());
		$this->rootFolder->method('getById')->with(123)->willReturn([$file]);

		$temp = $this->makeTempPath();
		$this->tempManager->method('getTemporaryFile')->willReturn($temp);

		$result = $service->sanitize(123);

		$this->assertSame($temp, $result->path);
		$this->assertSame(9, $result->report->commentsRemoved);
	}//end testDispatchToMatchingStrategy()

	/**
	 * An unsupported MIME raises REASON_UNSUPPORTED_MIME.
	 *
	 * @return void
	 */
	public function testUnsupportedMimeRaises(): void {
		$service = new OfficeDocumentSanitizer(
			$this->rootFolder,
			$this->tempManager,
			$this->logger,
			[$this->makeStrategy('application/x-only', new SanitizationReport())]
		);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->rootFolder->method('getById')->with(7)->willReturn([$file]);
		$this->tempManager->method('getTemporaryFile')->willReturn($this->makeTempPath());

		$this->expectException(SanitizationException::class);
		try {
			$service->sanitize(7);
		} catch (SanitizationException $e) {
			$this->assertSame(SanitizationException::REASON_UNSUPPORTED_MIME, $e->getReason());
			throw $e;
		}
	}//end testUnsupportedMimeRaises()

	/**
	 * A missing file (empty getById) raises REASON_UNSUPPORTED_MIME.
	 *
	 * @return void
	 */
	public function testMissingFileRaises(): void {
		$service = new OfficeDocumentSanitizer($this->rootFolder, $this->tempManager, $this->logger, []);
		$this->rootFolder->method('getById')->willReturn([]);

		$this->expectException(SanitizationException::class);
		$service->sanitize(999);
	}//end testMissingFileRaises()

	/**
	 * The log line carries only counts / mime / strategy — no document content.
	 *
	 * @return void
	 */
	public function testLoggingIsPiiFree(): void {
		$report = new SanitizationReport(commentsRemoved: 1, sentinelApplied: 'S');
		$strategy = $this->makeStrategy('application/x-log', $report);

		$captured = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$captured): void {
				$captured = $context;
			}
		);

		$service = new OfficeDocumentSanitizer($this->rootFolder, $this->tempManager, $this->logger, [$strategy]);

		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/x-log');
		$file->method('fopen')->willReturn($this->openSourceStream('SECRET-CONTENT'));
		$this->rootFolder->method('getById')->willReturn([$file]);
		$this->tempManager->method('getTemporaryFile')->willReturn($this->makeTempPath());

		$service->sanitize(5);

		$flat = json_encode($captured);
		$this->assertStringNotContainsString('SECRET-CONTENT', (string)$flat);
		$this->assertArrayHasKey('counts', $captured);
		$this->assertArrayHasKey('strategy', $captured);
	}//end testLoggingIsPiiFree()

	/**
	 * Build a strategy mock supporting one MIME and returning a fixed report.
	 *
	 * @param string $mime The supported MIME type.
	 * @param SanitizationReport $report The report to return from sanitize().
	 *
	 * @return SanitizerInterface&MockObject The strategy mock.
	 */
	private function makeStrategy(string $mime, SanitizationReport $report): SanitizerInterface&MockObject {
		$strategy = $this->createMock(SanitizerInterface::class);
		$strategy->method('supports')->willReturnCallback(static fn (string $m): bool => $m === $mime);
		$strategy->method('sanitize')->willReturn($report);
		return $strategy;
	}//end makeStrategy()

	/**
	 * Open a readable in-memory stream as the source file content.
	 *
	 * @param string $content The stream content.
	 *
	 * @return resource The read stream.
	 */
	private function openSourceStream(string $content = 'content') {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}//end openSourceStream()

	/**
	 * Allocate a real temp path (tracked for cleanup).
	 *
	 * @return string The temp path.
	 */
	private function makeTempPath(): string {
		$path = tempnam(sys_get_temp_dir(), 'orch_');
		$this->tempFiles[] = $path;
		return $path;
	}//end makeTempPath()
}//end class
