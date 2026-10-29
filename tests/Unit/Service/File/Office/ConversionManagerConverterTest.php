<?php

declare(strict_types=1);

/**
 * ConversionManagerConverter tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Office
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File\Office;

use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Service\File\Office\ConversionManagerConverter;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see ConversionManagerConverter}.
 */
class ConversionManagerConverterTest extends TestCase {

	private const ODT = 'application/vnd.oasis.opendocument.text';

	/** @var IConversionManager&MockObject */
	private IConversionManager $manager;

	/** @var IRootFolder&MockObject */
	private IRootFolder $root;

	/** @var Folder&MockObject */
	private Folder $work;

	protected function setUp(): void {
		$this->manager = $this->createMock(IConversionManager::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->work = $this->createMock(Folder::class);
		$this->work->method('getPath')->willReturn('/appdata_x/openregister/office-conversion');

		$appData = $this->createMock(Folder::class);
		$appData->method('get')->with('openregister/office-conversion')->willReturn($this->work);
		$this->root->method('getAppDataDirectoryName')->willReturn('appdata_x');
		$this->root->method('get')->willReturnCallback(
			fn (string $path) => match ($path) {
				'appdata_x' => $appData,
				'/appdata_x/openregister/office-conversion/out.pdf' => $this->pdfNode(),
				default => throw new NotFoundException($path),
			}
		);
	}//end setUp()

	/**
	 * A converted file node holding PDF bytes, expected to be deleted.
	 *
	 * @return File
	 */
	private function pdfNode(): File {
		$pdf = $this->createMock(File::class);
		$pdf->method('getContent')->willReturn('%PDF-1.7 converted');
		$pdf->expects($this->once())->method('delete');

		return $pdf;
	}//end pdfNode()

	/**
	 * Let the manager offer the given conversions.
	 *
	 * @param list<array{0: string, 1: string}> $pairs From and to MIME types.
	 *
	 * @return void
	 */
	private function offers(array $pairs): void {
		$this->manager->method('getProviders')->willReturn(array_map(
			static fn (array $pair) => new ConversionMimeProvider($pair[0], $pair[1], 'pdf', 'PDF'),
			$pairs
		));
	}//end offers()

	/**
	 * Without a provider for ODT to PDF the converter is unavailable and refuses with a clear error.
	 *
	 * @return void
	 */
	public function testWithoutProviderItRequiresAnOfficeServer(): void {
		$this->offers(pairs: [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/pdf']]);
		$converter = new ConversionManagerConverter(conversionManager: $this->manager, rootFolder: $this->root);

		$this->assertFalse($converter->isAvailable());

		$this->manager->expects($this->never())->method('convert');
		$this->expectException(OfficeServerRequiredException::class);
		$this->expectExceptionMessage('requires an office server (Collabora or Euro-Office)');
		$converter->odtToPdf(odtBytes: 'odt');
	}//end testWithoutProviderItRequiresAnOfficeServer()

	/**
	 * With a provider the ODT goes through a work file in app data, and both files are removed.
	 *
	 * @return void
	 */
	public function testConvertsThroughTheConversionManager(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);

		$source = $this->createMock(File::class);
		$source->expects($this->once())->method('delete');
		$this->work->expects($this->once())->method('newFile')
			->with($this->matchesRegularExpression('/^[0-9a-f]{32}\.odt$/'), 'odt bytes')
			->willReturn($source);
		$this->manager->expects($this->once())->method('convert')
			->with($source, 'application/pdf', $this->matchesRegularExpression('#^/appdata_x/openregister/office-conversion/[0-9a-f]{32}\.pdf$#'))
			->willReturn('/appdata_x/openregister/office-conversion/out.pdf');

		$converter = new ConversionManagerConverter(conversionManager: $this->manager, rootFolder: $this->root);

		$this->assertTrue($converter->isAvailable());
		$this->assertSame('%PDF-1.7 converted', $converter->odtToPdf(odtBytes: 'odt bytes'));
	}//end testConvertsThroughTheConversionManager()

	/**
	 * A failing conversion is reported, and the work file is still removed.
	 *
	 * @return void
	 */
	public function testFailedConversionIsReportedAndCleanedUp(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);

		$source = $this->createMock(File::class);
		$source->expects($this->once())->method('delete');
		$this->work->method('newFile')->willReturn($source);
		$this->manager->method('convert')->willThrowException(new RuntimeException('server down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The office server could not convert the document');
		(new ConversionManagerConverter(conversionManager: $this->manager, rootFolder: $this->root))->odtToPdf(odtBytes: 'odt');
	}//end testFailedConversionIsReportedAndCleanedUp()

	/**
	 * PDF to ODT needs its own provider.
	 *
	 * @return void
	 */
	public function testPdfToOdtNeedsItsOwnProvider(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);

		$this->expectException(OfficeServerRequiredException::class);
		(new ConversionManagerConverter(conversionManager: $this->manager, rootFolder: $this->root))->pdfToOdt(pdfBytes: '%PDF');
	}//end testPdfToOdtNeedsItsOwnProvider()

	/**
	 * The work folder is created on first use.
	 *
	 * @return void
	 */
	public function testCreatesTheWorkFolder(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);

		$appData = $this->createMock(Folder::class);
		$appData->method('get')->willThrowException(new NotFoundException('missing'));
		$appData->expects($this->once())->method('newFolder')->with('openregister/office-conversion')->willReturn($this->work);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getAppDataDirectoryName')->willReturn('appdata_x');
		$root->method('get')->willReturnCallback(
			fn (string $path) => $path === 'appdata_x' ? $appData : $this->pdfNode()
		);

		$source = $this->createMock(File::class);
		$this->work->method('newFile')->willReturn($source);
		$this->manager->method('convert')->willReturn('/appdata_x/openregister/office-conversion/out.pdf');

		$this->assertSame('%PDF-1.7 converted', (new ConversionManagerConverter(conversionManager: $this->manager, rootFolder: $root))->odtToPdf(odtBytes: 'odt'));
	}//end testCreatesTheWorkFolder()
}//end class
