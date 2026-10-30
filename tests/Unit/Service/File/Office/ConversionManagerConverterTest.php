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
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\Office\ConversionManagerConverter;
use OCA\OpenRegister\Service\File\Office\ConversionWorkFolder;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see ConversionManagerConverter} and {@see ConversionWorkFolder}.
 */
class ConversionManagerConverterTest extends TestCase {

	private const ODT = 'application/vnd.oasis.opendocument.text';

	/** @var IConversionManager&MockObject */
	private IConversionManager $manager;

	/** @var Folder&MockObject */
	private Folder $work;

	/** @var Folder&MockObject */
	private Folder $home;

	protected function setUp(): void {
		$this->manager = $this->createMock(IConversionManager::class);
		$this->work = $this->createMock(Folder::class);
		$this->work->method('getPath')->willReturn('/openregister/files/.office-conversion');
		$this->home = $this->createMock(Folder::class);
	}//end setUp()

	/**
	 * The converter, with the work folder in a mocked home of openregister's account.
	 *
	 * @return ConversionManagerConverter
	 */
	private function converter(): ConversionManagerConverter {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('openregister');
		$ownership = $this->createMock(FileOwnershipHandler::class);
		$ownership->method('getUser')->willReturn($user);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('openregister')->willReturn($this->home);

		return new ConversionManagerConverter(
			conversionManager: $this->manager,
			workFolder: new ConversionWorkFolder(rootFolder: $root, ownership: $ownership)
		);
	}//end converter()

	/**
	 * Let the home hold the work folder already.
	 *
	 * @return void
	 */
	private function workFolderExists(): void {
		$this->home->method('get')->with('.office-conversion')->willReturn($this->work);
	}//end workFolderExists()

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
		$converter = $this->converter();

		$this->assertFalse($converter->isAvailable());

		$this->manager->expects($this->never())->method('convert');
		$this->expectException(OfficeServerRequiredException::class);
		$this->expectExceptionMessage('requires an office server (Collabora or Euro-Office)');
		$converter->odtToPdf(odtBytes: 'odt');
	}//end testWithoutProviderItRequiresAnOfficeServer()

	/**
	 * With a provider the ODT goes through a work file in openregister's home, and both files are removed.
	 *
	 * @return void
	 */
	public function testConvertsThroughTheConversionManager(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);
		$this->workFolderExists();

		$source = $this->createMock(File::class);
		$source->expects($this->once())->method('delete');
		$this->work->expects($this->once())->method('newFile')
			->with($this->matchesRegularExpression('/^[0-9a-f]{32}\.odt$/'), 'odt bytes')
			->willReturn($source);
		$this->work->method('get')->with('out.pdf')->willReturn($this->pdfNode());
		$this->manager->expects($this->once())->method('convert')
			->with($source, 'application/pdf', $this->matchesRegularExpression('#^/openregister/files/\.office-conversion/[0-9a-f]{32}\.pdf$#'))
			->willReturn('/openregister/files/.office-conversion/out.pdf');

		$converter = $this->converter();

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
		$this->workFolderExists();

		$source = $this->createMock(File::class);
		$source->expects($this->once())->method('delete');
		$this->work->method('newFile')->willReturn($source);
		$this->manager->method('convert')->willThrowException(new RuntimeException('server down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The office server could not convert the document');
		$this->converter()->odtToPdf(odtBytes: 'odt');
	}//end testFailedConversionIsReportedAndCleanedUp()

	/**
	 * PDF to ODT needs its own provider.
	 *
	 * @return void
	 */
	public function testPdfToOdtNeedsItsOwnProvider(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);

		$this->expectException(OfficeServerRequiredException::class);
		$this->converter()->pdfToOdt(pdfBytes: '%PDF');
	}//end testPdfToOdtNeedsItsOwnProvider()

	/**
	 * The work folder is created on first use.
	 *
	 * @return void
	 */
	public function testCreatesTheWorkFolder(): void {
		$this->offers(pairs: [[self::ODT, 'application/pdf']]);
		$this->home->method('get')->willThrowException(new NotFoundException('missing'));
		$this->home->expects($this->once())->method('newFolder')->with('.office-conversion')->willReturn($this->work);

		$this->work->method('newFile')->willReturn($this->createMock(File::class));
		$this->work->method('get')->willReturn($this->pdfNode());
		$this->manager->method('convert')->willReturn('/openregister/files/.office-conversion/out.pdf');

		$this->assertSame('%PDF-1.7 converted', $this->converter()->odtToPdf(odtBytes: 'odt'));
	}//end testCreatesTheWorkFolder()
}//end class
