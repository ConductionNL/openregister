<?php

/**
 * The failure paths of PdfDocumentSanitizer: a PDF that cannot be read, bytes
 * the PDF library cannot parse, a scrub step that throws and a temporary file
 * that cannot be made. Each one is an internal SanitizationException.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfPayloadStripper;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfReachableSerialiser;
use OCA\OpenRegister\Files\Service\File\PdfDocumentSanitizer;
use OCA\OpenRegister\Files\Service\File\PdfSanitizationReport;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(PdfDocumentSanitizer::class)]
#[UsesClass(SanitizationException::class)]
#[UsesClass(PdfPayloadStripper::class)]
#[UsesClass(PdfReachableSerialiser::class)]
#[UsesClass(PdfSanitizationReport::class)]
class PdfDocumentSanitizerFailureTest extends TestCase {

	/** @var IRootFolder&MockObject */
	private IRootFolder $root;

	/** @var ITempManager&MockObject */
	private ITempManager $temp;

	/** @var PdfMetadataSanitizer&MockObject */
	private PdfMetadataSanitizer $metadata;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var PdfDocumentSanitizer The subject under test. */
	private PdfDocumentSanitizer $sanitizer;

	protected function setUp(): void {
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$this->root      = $this->createMock(IRootFolder::class);
		$this->temp      = $this->createMock(ITempManager::class);
		$this->metadata  = $this->createMock(PdfMetadataSanitizer::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->sanitizer = new PdfDocumentSanitizer($this->root, $this->temp, $this->metadata, $this->logger);
	}//end setUp()

	/**
	 * A one-page PDF with a classic cross-reference table.
	 *
	 * @return string
	 */
	private static function pdf(): string {
		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] >>',
		];
		$out     = "%PDF-1.7\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($out);
			$out          .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref = strlen($out);
		$out .= "xref\n0 4\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$out .= sprintf("%010d 00000 n \n", $offset);
		}

		return $out . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}//end pdf()

	/**
	 * A stored PDF file with id 41.
	 *
	 * @return File&MockObject
	 */
	private function storedPdf(): File {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$this->root->method('getById')->with(41)->willReturn([$file]);

		return $file;
	}//end storedPdf()

	/**
	 * Run the call and return the SanitizationException it throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return SanitizationException
	 */
	private function refusal(callable $call): SanitizationException {
		try {
			$call();
		} catch (SanitizationException $e) {
			return $e;
		}

		$this->fail('Expected a SanitizationException');
	}//end refusal()

	public function testAPdfWhoseContentCannotBeReadIsAnInternalFailureKeepingTheCause(): void {
		$cause = new RuntimeException('storage offline');
		$this->storedPdf()->method('getContent')->willThrowException($cause);
		$this->temp->expects($this->never())->method('getTemporaryFile');

		$e = $this->refusal(fn () => $this->sanitizer->sanitize(fileId: 41));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Could not read the PDF', $e->getMessage());
		$this->assertSame($cause, $e->getPrevious());
	}//end testAPdfWhoseContentCannotBeReadIsAnInternalFailureKeepingTheCause()

	public function testBytesWithAPdfHeaderThatCannotBeParsedAreAnInternalFailure(): void {
		$this->metadata->expects($this->never())->method('sanitize');

		$e = $this->refusal(fn () => $this->sanitizer->sanitizeBytes(bytes: "%PDF-1.7\nno objects, no xref, no trailer"));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('The PDF could not be parsed', $e->getMessage());
	}//end testBytesWithAPdfHeaderThatCannotBeParsedAreAnInternalFailure()

	public function testAScrubStepThatThrowsIsAnInternalFailureKeepingTheCause(): void {
		$cause = new RuntimeException('broken info dictionary');
		$this->metadata->expects($this->once())->method('sanitize')->willThrowException($cause);

		$e = $this->refusal(fn () => $this->sanitizer->sanitizeBytes(bytes: self::pdf()));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('PDF sanitisation failed', $e->getMessage());
		$this->assertSame($cause, $e->getPrevious());
	}//end testAScrubStepThatThrowsIsAnInternalFailureKeepingTheCause()

	public function testNoTemporaryFileMeansAnInternalFailureAndNothingIsLogged(): void {
		$this->storedPdf()->method('getContent')->willReturn(self::pdf());
		$this->metadata->method('sanitize')->willReturn(['info_fields_stripped' => 0, 'xmp_stripped' => false]);
		$this->temp->expects($this->once())->method('getTemporaryFile')->with('.pdf')->willReturn(false);
		$this->logger->expects($this->never())->method('info');

		$e = $this->refusal(fn () => $this->sanitizer->sanitize(fileId: 41));

		$this->assertSame(SanitizationException::REASON_INTERNAL, $e->getReason());
		$this->assertSame('Could not write the sanitised PDF to a temporary file', $e->getMessage());
	}//end testNoTemporaryFileMeansAnInternalFailureAndNothingIsLogged()
}//end class
