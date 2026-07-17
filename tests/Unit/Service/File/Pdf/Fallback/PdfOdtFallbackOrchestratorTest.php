<?php

/**
 * PdfOdtFallbackOrchestratorTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Pdf\Fallback\PdfOdtFallbackOrchestrator}.
 *
 * Covers the dormant-state guards (feature flag off → re-raise; encrypted
 * reason → re-raise; text-layer-missing → re-raise) and, when activated, the
 * round-trip success / per-stage failure surface.
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
use OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NullNcOfficeConverter;
use OCA\OpenRegister\Service\File\Pdf\Fallback\PdfOdtFallbackOrchestrator;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see PdfOdtFallbackOrchestrator}.
 */
class PdfOdtFallbackOrchestratorTest extends TestCase
{

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock NC Office converter.
     *
     * @var NcOfficeConverterInterface&MockObject
     */
    private NcOfficeConverterInterface&MockObject $converter;

    /**
     * Mock SAPP-side replacer.
     *
     * @var PdfTextReplacer&MockObject
     */
    private PdfTextReplacer&MockObject $pdfTextReplacer;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Reset before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->converter       = $this->createMock(NcOfficeConverterInterface::class);
        $this->pdfTextReplacer = $this->createMock(PdfTextReplacer::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Feature flag off → orchestrator re-raises the Path A exception unchanged.
     *
     * @return void
     */
    public function testFeatureFlagOffReraisesOriginal(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(false);

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        $orchestrator = $this->buildOrchestrator();

        $this->expectException(PdfAnonymisationException::class);
        $this->expectExceptionMessage('Path A failed');

        try {
            $orchestrator->attempt(pdfBytes: 'fake', substitutions: ['x' => 'y'], cause: $cause);
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(PdfAnonymisationException::REASON_VALIDATION_FAILED, $e->getReason());
            $this->assertSame($cause, $e, 'Orchestrator MUST re-raise the very same exception when dormant');
            throw $e;
        }
    }//end testFeatureFlagOffReraisesOriginal()

    /**
     * Feature flag on + converter unavailable → re-raise.
     *
     * @return void
     */
    public function testConverterUnavailableReraisesOriginal(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(false);

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        $orchestrator = $this->buildOrchestrator();

        $this->expectException(PdfAnonymisationException::class);

        try {
            $orchestrator->attempt(pdfBytes: 'fake', substitutions: ['x' => 'y'], cause: $cause);
        } catch (PdfAnonymisationException $e) {
            $this->assertSame($cause, $e);
            throw $e;
        }
    }//end testConverterUnavailableReraisesOriginal()

    /**
     * Encrypted-PDF reason → never trigger Path B, even when enabled.
     *
     * @return void
     */
    public function testEncryptedReasonReraisesEvenWhenEnabled(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->expects($this->never())->method('isAvailable');
        $this->converter->expects($this->never())->method('pdfToOdt');

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_ENCRYPTED_PDF,
            message: 'encrypted'
        );

        $this->expectException(PdfAnonymisationException::class);

        try {
            $this->buildOrchestrator()->attempt(pdfBytes: 'fake', substitutions: [], cause: $cause);
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(PdfAnonymisationException::REASON_ENCRYPTED_PDF, $e->getReason());
            throw $e;
        }
    }//end testEncryptedReasonReraisesEvenWhenEnabled()

    /**
     * Text-layer-missing reason → never trigger Path B (OCR is the right route).
     *
     * @return void
     */
    public function testTextLayerMissingReraisesEvenWhenEnabled(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->expects($this->never())->method('isAvailable');

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_TEXT_LAYER_MISSING,
            message: 'image only'
        );

        $this->expectException(PdfAnonymisationException::class);

        try {
            $this->buildOrchestrator()->attempt(pdfBytes: 'fake', substitutions: [], cause: $cause);
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(PdfAnonymisationException::REASON_TEXT_LAYER_MISSING, $e->getReason());
            throw $e;
        }
    }//end testTextLayerMissingReraisesEvenWhenEnabled()

    /**
     * Path B activated → happy path: PDF→ODT→PDF→replace produces clean bytes.
     *
     * @return void
     */
    public function testHappyPathReturnsCleanBytes(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(true);
        $this->converter->expects($this->once())->method('pdfToOdt')->with('fake-pdf')->willReturn('fake-odt');
        $this->converter->expects($this->once())->method('odtToPdf')->with('fake-odt')->willReturn('rebuilt-pdf');
        $this->pdfTextReplacer
            ->expects($this->once())
            ->method('replaceInPdf')
            ->with('rebuilt-pdf', ['Jan Jansen' => '[PERSON: 1]'], true)
            ->willReturn('clean-pdf');

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        $result = $this->buildOrchestrator()->attempt(
            pdfBytes: 'fake-pdf',
            substitutions: ['Jan Jansen' => '[PERSON: 1]'],
            cause: $cause
        );

        $this->assertSame('clean-pdf', $result);
    }//end testHappyPathReturnsCleanBytes()

    /**
     * PDF→ODT step failure → REASON_VALIDATION_FAILED_AFTER_FALLBACK.
     *
     * @return void
     */
    public function testPdfToOdtFailureRaisesAfterFallback(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(true);
        $this->converter->method('pdfToOdt')->willThrowException(new RuntimeException('NC Office 502'));

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        try {
            $this->buildOrchestrator()->attempt(pdfBytes: 'fake', substitutions: [], cause: $cause);
            $this->fail('Expected PdfAnonymisationException to be raised');
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(
                PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK,
                $e->getReason()
            );
        }
    }//end testPdfToOdtFailureRaisesAfterFallback()

    /**
     * ODT→PDF step failure → REASON_VALIDATION_FAILED_AFTER_FALLBACK.
     *
     * @return void
     */
    public function testOdtToPdfFailureRaisesAfterFallback(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(true);
        $this->converter->method('pdfToOdt')->willReturn('odt-bytes');
        $this->converter->method('odtToPdf')->willThrowException(new RuntimeException('NC Office 500'));

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        try {
            $this->buildOrchestrator()->attempt(pdfBytes: 'fake', substitutions: [], cause: $cause);
            $this->fail('Expected PdfAnonymisationException to be raised');
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(
                PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK,
                $e->getReason()
            );
        }
    }//end testOdtToPdfFailureRaisesAfterFallback()

    /**
     * Re-run replacer failure → REASON_VALIDATION_FAILED_AFTER_FALLBACK.
     *
     * @return void
     */
    public function testReplacerRerunFailureRaisesAfterFallback(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(true);
        $this->converter->method('pdfToOdt')->willReturn('odt-bytes');
        $this->converter->method('odtToPdf')->willReturn('rebuilt-pdf');
        $this->pdfTextReplacer
            ->method('replaceInPdf')
            ->willThrowException(new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
                message: 'still bad'
            ));

        $cause = new PdfAnonymisationException(
            reason: PdfAnonymisationException::REASON_VALIDATION_FAILED,
            message: 'Path A failed'
        );

        try {
            $this->buildOrchestrator()->attempt(pdfBytes: 'fake', substitutions: [], cause: $cause);
            $this->fail('Expected PdfAnonymisationException to be raised');
        } catch (PdfAnonymisationException $e) {
            $this->assertSame(
                PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK,
                $e->getReason()
            );
        }
    }//end testReplacerRerunFailureRaisesAfterFallback()

    /**
     * isEnabled() short-circuits on the flag.
     *
     * @return void
     */
    public function testIsEnabledShortCircuitsOnFlag(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(false);
        $this->converter->expects($this->never())->method('isAvailable');

        $this->assertFalse($this->buildOrchestrator()->isEnabled());
    }//end testIsEnabledShortCircuitsOnFlag()

    /**
     * isEnabled() requires both flag AND bridge ready.
     *
     * @return void
     */
    public function testIsEnabledRequiresBothFlagAndBridge(): void
    {
        $this->appConfig->method('getValueBool')->willReturn(true);
        $this->converter->method('isAvailable')->willReturn(true);

        $this->assertTrue($this->buildOrchestrator()->isEnabled());
    }//end testIsEnabledRequiresBothFlagAndBridge()

    /**
     * The default-shipped Null converter reports unavailable + throws on use.
     *
     * @return void
     */
    public function testNullConverterIsDormantByDefault(): void
    {
        $converter = new NullNcOfficeConverter();

        $this->assertFalse($converter->isAvailable());

        $this->expectException(RuntimeException::class);
        $converter->pdfToOdt('whatever');
    }//end testNullConverterIsDormantByDefault()

    /**
     * Build the orchestrator under test with the mocked collaborators.
     *
     * @return PdfOdtFallbackOrchestrator
     */
    private function buildOrchestrator(): PdfOdtFallbackOrchestrator
    {
        return new PdfOdtFallbackOrchestrator(
            appConfig: $this->appConfig,
            converter: $this->converter,
            pdfTextReplacer: $this->pdfTextReplacer,
            logger: $this->logger
        );
    }//end buildOrchestrator()
}//end class
