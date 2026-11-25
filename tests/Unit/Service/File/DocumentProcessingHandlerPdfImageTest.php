<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\File\ImageRegionNotBurnableException;
use PHPUnit\Framework\TestCase;

/**
 * A PDF's page images go through the image seam in the same anonymisation as its text.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-every-redaction-path-reaches-the-seam-req-ais-004
 */
final class DocumentProcessingHandlerPdfImageTest extends TestCase {
	use PdfAnonymiseHarness;

	private const ENTITIES = [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']];

	protected function setUp(): void {
		$this->startHarness();
	}//end setUp()

	private function anonymise(string $pdf): void {
		$this->pdfHandler()->anonymize(new DocumentAnonymisationRequest(node: $this->pdfNode($pdf), entities: self::ENTITIES));
	}//end anonymise()

	public function testAPdfRunReplacesTheTextAndBurnsItsPageImageInOneOutput(): void {
		$this->decidedRegion(page: 1);

		$this->anonymise(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'));

		$text = (new PdfExtractor($this->createMock(\Psr\Log\LoggerInterface::class)))->extractText(pdfBytes: (string)$this->output);
		$this->assertStringNotContainsString('Jan Jansen', $text, 'The text is replaced.');
		$this->assertStringContainsString('Geachte', $text);
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]), 'The region is burned in the same output.');
		$this->assertStringNotContainsString(PdfImageFixture::jpeg(), (string)$this->output);
		$this->assertFalse($this->outputDeleted);
		$this->assertSame('ran', $this->logged->getStepsArray()['image-burner']['outcome']);
	}//end testAPdfRunReplacesTheTextAndBurnsItsPageImageInOneOutput()

	public function testAPdfWithoutDecidedRegionsKeepsItsPageImage(): void {
		$this->decidedRegion(page: null);

		$this->anonymise(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'));

		$this->assertStringContainsString(PdfImageFixture::jpeg(), (string)$this->output, 'Without a region the image is left as the text path wrote it.');
		$this->assertSame('skipped', $this->logged->getStepsArray()['image-burner']['outcome']);
	}//end testAPdfWithoutDecidedRegionsKeepsItsPageImage()

	public function testAScannedPdfWithoutATextLayerIsBurnedRatherThanRefused(): void {
		$this->decidedRegion(page: 1);

		$this->anonymise(PdfImageFixture::onePage(PdfImageFixture::jpegImage()));

		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]));
		$this->assertSame('ran', $this->logged->getStepsArray()['image-burner']['outcome']);
	}//end testAScannedPdfWithoutATextLayerIsBurnedRatherThanRefused()

	public function testAScannedPdfWithoutDecidedRegionsIsStillRefusedForItsMissingTextLayer(): void {
		$this->decidedRegion(page: null);

		try {
			$this->anonymise(PdfImageFixture::onePage(PdfImageFixture::jpegImage()));
			$this->fail('A scan with nothing decided must not be reported as anonymised.');
		} catch (PdfAnonymisationException $e) {
			$this->assertSame(PdfAnonymisationException::REASON_TEXT_LAYER_MISSING, $e->getReason());
		}

		$this->assertNull($this->output);
	}//end testAScannedPdfWithoutDecidedRegionsIsStillRefusedForItsMissingTextLayer()

	public function testAPdfRegionThatCannotBeBurnedDeletesTheTextOutput(): void {
		$this->decidedRegion(page: 4);

		try {
			$this->anonymise(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'));
			$this->fail('A region on a page the PDF does not have must be refused.');
		} catch (ImageRegionNotBurnableException $e) {
			$this->assertStringContainsString('scan.pdf could not be redacted', $e->getMessage());
		}

		$this->assertTrue($this->outputDeleted, 'No half-redacted _anonymized copy is left behind.');
	}//end testAPdfRegionThatCannotBeBurnedDeletesTheTextOutput()
}//end class
