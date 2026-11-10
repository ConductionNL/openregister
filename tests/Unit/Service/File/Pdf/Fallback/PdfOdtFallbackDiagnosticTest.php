<?php

/**
 * PdfOdtFallbackDiagnosticTest
 *
 * Characterisation of the exception PdfOdtFallbackOrchestrator raises when
 * a Path B stage fails: reason, message, diagnostic and previous.
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

namespace Unit\Service\File\Pdf\Fallback;

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\OdtRoundTripRequest;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\PdfOdtFallbackOrchestrator;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stage diagnostics of {@see PdfOdtFallbackOrchestrator}.
 */
class PdfOdtFallbackDiagnosticTest extends TestCase {

	/**
	 * Converter mock.
	 *
	 * @var NcOfficeConverterInterface&MockObject
	 */
	private NcOfficeConverterInterface&MockObject $converter;

	/**
	 * Replacer mock.
	 *
	 * @var PdfTextReplacer&MockObject
	 */
	private PdfTextReplacer&MockObject $replacer;

	/**
	 * Enable Path B.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->converter = $this->createMock(NcOfficeConverterInterface::class);
		$this->converter->method('isAvailable')->willReturn(true);
		$this->replacer = $this->createMock(PdfTextReplacer::class);
	}//end setUp()

	/**
	 * Run the fallback for a validation failure and return what it throws.
	 *
	 * @return Throwable
	 */
	private function failure(): Throwable {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(true);
		$orchestrator = new PdfOdtFallbackOrchestrator(
			appConfig: $config,
			converter: $this->converter,
			pdfTextReplacer: $this->replacer,
			logger: $this->createMock(LoggerInterface::class)
		);
		$cause = new PdfAnonymisationException(reason: PdfAnonymisationException::REASON_VALIDATION_FAILED, message: 'Path A failed');

		try {
			$orchestrator->attempt(request: new OdtRoundTripRequest(pdfBytes: 'fake', substitutions: ['a' => 'b'], cause: $cause));
		} catch (Throwable $e) {
			return $e;
		}

		self::fail('Expected the fallback to throw');
	}//end failure()

	/**
	 * Assert the wrapped exception of one stage.
	 *
	 * @param Throwable $e        The thrown exception.
	 * @param string    $stage    The failing stage.
	 * @param Throwable $previous The stage's own failure.
	 *
	 * @return void
	 */
	private function assertWrapped(Throwable $e, string $stage, Throwable $previous): void {
		self::assertInstanceOf(PdfAnonymisationException::class, $e);
		self::assertSame(PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK, $e->getReason());
		self::assertSame('Path B fallback failed at stage ' . $stage, $e->getMessage());
		self::assertSame(
			[
				'pathB_stage' => $stage,
				'pathA_reason' => PdfAnonymisationException::REASON_VALIDATION_FAILED,
				'pathB_previous' => $previous::class,
			],
			$e->getDiagnostic()
		);
		self::assertSame($previous, $e->getPrevious());
	}//end assertWrapped()

	/**
	 * PDF to ODT failure.
	 *
	 * @return void
	 */
	public function testPdfToOdtStage(): void {
		$previous = new RuntimeException('502');
		$this->converter->method('pdfToOdt')->willThrowException($previous);

		$this->assertWrapped($this->failure(), 'pdf_to_odt', $previous);
	}//end testPdfToOdtStage()

	/**
	 * ODT to PDF failure.
	 *
	 * @return void
	 */
	public function testOdtToPdfStage(): void {
		$previous = new RuntimeException('500');
		$this->converter->method('pdfToOdt')->willReturn('odt');
		$this->converter->method('odtToPdf')->willThrowException($previous);

		$this->assertWrapped($this->failure(), 'odt_to_pdf', $previous);
	}//end testOdtToPdfStage()

	/**
	 * Replacer re-run failure.
	 *
	 * @return void
	 */
	public function testRerunStage(): void {
		$previous = new PdfAnonymisationException(reason: PdfAnonymisationException::REASON_VALIDATION_FAILED, message: 'still bad');
		$this->converter->method('pdfToOdt')->willReturn('odt');
		$this->converter->method('odtToPdf')->willReturn('pdf');
		$this->replacer->method('replaceInPdf')->willThrowException($previous);

		$this->assertWrapped($this->failure(), 'rerun_replace', $previous);
	}//end testRerunStage()

	/**
	 * Any other failure of the re-run is not wrapped.
	 *
	 * @return void
	 */
	public function testRerunOtherFailurePropagates(): void {
		$previous = new RuntimeException('parser crash');
		$this->converter->method('pdfToOdt')->willReturn('odt');
		$this->converter->method('odtToPdf')->willReturn('pdf');
		$this->replacer->method('replaceInPdf')->willThrowException($previous);

		self::assertSame($previous, $this->failure());
	}//end testRerunOtherFailurePropagates()
}//end class
