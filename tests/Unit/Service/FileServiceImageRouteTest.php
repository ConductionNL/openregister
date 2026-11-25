<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Tests\Unit\Service\File\PdfAnonymiseHarness;
use OCA\OpenRegister\Tests\Unit\Service\File\PdfImageFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * A caller of FileService::anonymizeDocument() gets the image regions burned too.
 *
 * opencatalogi's DocumentRedactor calls the service directly with the detected
 * entities rather than through `POST /api/files/{id}/anonymize`; both reach the
 * same handler, so both burn the decided regions in the output that replaces the text.
 *
 * @covers \OCA\OpenRegister\Service\FileService
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-every-redaction-path-reaches-the-seam-req-ais-004
 */
final class FileServiceImageRouteTest extends TestCase {
	use PdfAnonymiseHarness;

	protected function setUp(): void {
		$this->startHarness();
	}//end setUp()

	private function service(): FileService {
		$service = (new ReflectionClass(FileService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($service, $this->pdfHandler());

		return $service;
	}//end service()

	private function text(): string {
		return (new PdfExtractor($this->createMock(LoggerInterface::class)))->extractText(pdfBytes: (string)$this->output);
	}//end text()

	public function testADirectServiceCallBurnsImageRegions(): void {
		$this->decidedRegion(page: 1);
		$node = $this->pdfNode(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'));

		$this->service()->anonymizeDocument(node: $node, entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]);

		$this->assertStringNotContainsString('Jan Jansen', $this->text(), 'The text is replaced.');
		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]), 'The region is burned in the same file.');
		$this->assertStringNotContainsString(PdfImageFixture::jpeg(), (string)$this->output);
	}//end testADirectServiceCallBurnsImageRegions()

	public function testTheReleasesPositionalEntityListBurnsTheRegionsToo(): void {
		$this->decidedRegion(page: 1);
		$node = $this->pdfNode(PdfImageFixture::onePage(PdfImageFixture::jpegImage(), 'Geachte Jan Jansen'));

		$this->service()->anonymizeDocument($node, [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]);

		$this->assertSame(["\0\0\0"], PdfImageFixture::pixels($this->outputSamples(), 3, [0, 0, 15, 15]));
	}//end testTheReleasesPositionalEntityListBurnsTheRegionsToo()
}//end class
