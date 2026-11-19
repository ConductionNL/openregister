<?php

/**
 * Each content-altering step names the file-processing class that does it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\PipelineStepImplementers;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;
use OCA\OpenRegister\Service\Anonymisation\PipelineSteps;
use OCA\OpenRegister\Service\File\ImageBurner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\PipelineStepImplementers
 * @uses   \OCA\OpenRegister\Service\Anonymisation\PipelineSteps
 */
final class PipelineStepImplementersTest extends TestCase {

	/**
	 * Every step has one implementing class, in pipeline order.
	 *
	 * @return void
	 */
	public function testEveryStepHasItsImplementingClass(): void {
		$implementers = new PipelineStepImplementers();
		$classes = [
			DocumentProcessingHandler::class,
			PdfTextReplacer::class,
			PdfMetadataSanitizer::class,
			DocxSanitizer::class,
			OdtSanitizer::class,
			ImageBurner::class,
		];

		$found = array_map(static fn (string $class): ?string => $implementers->stepImplementedBy(class: $class), $classes);

		$this->assertSame((new PipelineSteps())->ids(), $found);
	}//end testEveryStepHasItsImplementingClass()

	/**
	 * A leading backslash is ignored, and a class that is no step answers null.
	 *
	 * @return void
	 */
	public function testALeadingBackslashIsIgnoredAndAnUnknownClassIsNoStep(): void {
		$implementers = new PipelineStepImplementers();

		$this->assertSame(PipelineSteps::DOCX_SANITISER, $implementers->stepImplementedBy(class: '\\' . DocxSanitizer::class));
		$this->assertNull($implementers->stepImplementedBy(class: self::class));
	}//end testALeadingBackslashIsIgnoredAndAnUnknownClassIsNoStep()
}//end class
