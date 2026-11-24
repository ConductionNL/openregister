<?php

declare(strict_types=1);

/**
 * OfficeHtmlPdfConverter tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Export\Odt;

use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\HtmlPdfConverterInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Export\Odt\OdtWriterInterface;
use OCA\OpenRegister\Service\Export\Odt\OfficeHtmlPdfConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see OfficeHtmlPdfConverter}.
 *
 * @covers \OCA\OpenRegister\Service\Export\Odt\OfficeHtmlPdfConverter
 */
class OfficeHtmlPdfConverterTest extends TestCase {

	/**
	 * The HTML is written as an ODT in the asked orientation and that ODT is converted.
	 *
	 * @return void
	 */
	public function testTheHtmlIsWrittenAsAnOdtAndThatOdtIsConverted(): void {
		$odtWriter = $this->createMock(OdtWriterInterface::class);
		$odtWriter->expects($this->once())->method('fromHtml')->with('<p>Kwartaal</p>', true)->willReturn('odt-bytes');
		$office = $this->createMock(NcOfficeConverterInterface::class);
		$office->expects($this->once())->method('odtToPdf')->with('odt-bytes')->willReturn('%PDF-1.7');

		$converter = new OfficeHtmlPdfConverter(officeConverter: $office, odtWriter: $odtWriter);

		$this->assertSame('%PDF-1.7', $converter->htmlToPdf(html: '<p>Kwartaal</p>', landscape: true));
	}//end testTheHtmlIsWrittenAsAnOdtAndThatOdtIsConverted()

	/**
	 * Availability is the office server's.
	 *
	 * @return void
	 */
	public function testAvailabilityIsTheOfficeServers(): void {
		foreach ([true, false] as $available) {
			$office = $this->createMock(NcOfficeConverterInterface::class);
			$office->method('isAvailable')->willReturn($available);
			$converter = new OfficeHtmlPdfConverter(officeConverter: $office, odtWriter: $this->createMock(OdtWriterInterface::class));

			$this->assertSame($available, $converter->isAvailable());
		}
	}//end testAvailabilityIsTheOfficeServers()

	/**
	 * The report writer's port is answered by this converter.
	 *
	 * @return void
	 */
	public function testItAnswersTheReportWritersPort(): void {
		$this->assertSame(OfficeHtmlPdfConverter::class, ContractBindings::BINDINGS[HtmlPdfConverterInterface::class]);
		$this->assertTrue(is_subclass_of(OfficeHtmlPdfConverter::class, HtmlPdfConverterInterface::class));
	}//end testItAnswersTheReportWritersPort()
}//end class
