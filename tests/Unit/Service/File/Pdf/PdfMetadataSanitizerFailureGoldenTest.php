<?php

/**
 * PdfMetadataSanitizerFailureGoldenTest
 *
 * Pins the two failures PdfMetadataSanitizer::sanitize() wraps: an /Info
 * reference that cannot be reached and an XMP stream that cannot be read,
 * each as an internal-error PdfAnonymisationException with its stage, message
 * and cause, and nothing logged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Pdf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Golden failure wraps of PdfMetadataSanitizer::sanitize().
 */
final class PdfMetadataSanitizerFailureGoldenTest extends TestCase {

	/**
	 * A document whose trailer names one reference under $key, and whose object lookup fails.
	 *
	 * @param string           $key   `Info` or `Root`.
	 * @param RuntimeException $cause What the object lookup throws.
	 *
	 * @return PDFDoc
	 */
	private function docFailingOn(string $key, RuntimeException $cause): PDFDoc {
		$doc = $this->getMockBuilder(PDFDoc::class)->disableOriginalConstructor()->onlyMethods(['get_object'])->getMock();
		$doc->method('get_object')->willThrowException($cause);
		$reference = new class {
			/**
			 * The referenced object id.
			 *
			 * @return int
			 */
			public function get_object_referenced(): int {
				return 6;
			}
		};
		(new ReflectionProperty(PDFDoc::class, '_pdf_trailer_object'))->setValue($doc, [$key => $reference]);
		return $doc;
	}//end docFailingOn()

	/**
	 * Run sanitize() and return what it throws.
	 *
	 * @param PDFDoc $doc The document.
	 *
	 * @return PdfAnonymisationException
	 */
	private function failure(PDFDoc $doc): PdfAnonymisationException {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		try {
			(new PdfMetadataSanitizer($logger))->sanitize($doc);
		} catch (PdfAnonymisationException $e) {
			return $e;
		}

		self::fail('no PdfAnonymisationException');
	}//end failure()

	/**
	 * An unreachable /Info reference.
	 *
	 * @return void
	 */
	public function testInfoFailure(): void {
		$cause = new RuntimeException('broken xref');
		$e = $this->failure($this->docFailingOn('Info', $cause));

		self::assertSame(PdfAnonymisationException::REASON_INTERNAL_ERROR, $e->getReason());
		self::assertSame('PdfMetadataSanitizer could not reach the trailer Info reference', $e->getMessage());
		self::assertSame(['stage' => 'metadata.locate_info'], $e->getDiagnostic());
		self::assertSame($cause, $e->getPrevious());
	}//end testInfoFailure()

	/**
	 * An unreadable XMP stream (the catalog lookup fails).
	 *
	 * @return void
	 */
	public function testXmpFailure(): void {
		$cause = new RuntimeException('broken catalog');
		$e = $this->failure($this->docFailingOn('Root', $cause));

		self::assertSame(PdfAnonymisationException::REASON_INTERNAL_ERROR, $e->getReason());
		self::assertSame('PdfMetadataSanitizer failed on XMP stream', $e->getMessage());
		self::assertSame(['stage' => 'metadata.xmp'], $e->getDiagnostic());
		self::assertSame($cause, $e->getPrevious());
	}//end testXmpFailure()
}//end class
