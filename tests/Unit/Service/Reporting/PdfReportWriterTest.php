<?php

declare(strict_types=1);

/**
 * PdfReportWriter tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Reporting;

use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Service\Reporting\HtmlReportWriter;
use OCA\OpenRegister\Service\Reporting\PdfReportWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for {@see PdfReportWriter}.
 */
class PdfReportWriterTest extends TestCase {

	/**
	 * A dashboard is written as a portrait ODT and converted by the office server.
	 *
	 * @return void
	 */
	public function testConvertsTheReportAsOdt(): void {
		$converter = $this->createMock(NcOfficeConverterInterface::class);
		$converter->method('isAvailable')->willReturn(true);
		$odt = '';
		$converter->expects($this->once())->method('odtToPdf')->willReturnCallback(
			static function (string $bytes) use (&$odt): string {
				$odt = $bytes;
				return '%PDF-1.7 report';
			}
		);

		$pdf = (new PdfReportWriter(htmlWriter: new HtmlReportWriter(), officeConverter: $converter))->write(
			dashboard: ['titel' => 'Kwartaal'],
			resolvedWidgets: [['widget' => ['title' => 'Open zaken'], 'data' => ['value' => 7]]]
		);

		$this->assertSame('%PDF-1.7 report', $pdf);

		$file = tempnam(sys_get_temp_dir(), 'odt');
		file_put_contents($file, $odt);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($file));
		$content = (string) $zip->getFromName('content.xml');
		$styles = (string) $zip->getFromName('styles.xml');
		$zip->close();
		unlink($file);

		$this->assertStringContainsString('Open zaken', $content);
		$this->assertStringContainsString('style:print-orientation="portrait"', $styles);
	}//end testConvertsTheReportAsOdt()

	/**
	 * Without an office server the report refuses with a clear error.
	 *
	 * @return void
	 */
	public function testWithoutOfficeServerItRefuses(): void {
		$converter = $this->createMock(NcOfficeConverterInterface::class);
		$converter->method('isAvailable')->willReturn(false);
		$converter->expects($this->never())->method('odtToPdf');

		$this->expectException(OfficeServerRequiredException::class);
		(new PdfReportWriter(htmlWriter: new HtmlReportWriter(), officeConverter: $converter))->write(dashboard: [], resolvedWidgets: []);
	}//end testWithoutOfficeServerItRefuses()
}//end class
