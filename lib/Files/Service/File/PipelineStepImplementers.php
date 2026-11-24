<?php

/**
 * The class that does each content-altering step of the anonymisation pipeline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;
use OCA\OpenRegister\Service\Anonymisation\PipelineSteps;
use OCA\OpenRegister\Service\File\ImageBurner;

/**
 * Names the class that does each step the anonymisation pipeline reports.
 *
 * The steps, their labels and file types are the pipeline's vocabulary
 * ({@see PipelineSteps}); which file-processing class carries out a step is
 * the file pipeline's own knowledge, so it is kept here. A content-altering
 * class without an entry fails the unit suite.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */
final class PipelineStepImplementers {

	/**
	 * The implementing class of each step, in pipeline order.
	 *
	 * @var array<string, class-string>
	 */
	private const IMPLEMENTED_BY = [
		PipelineSteps::ANONYMISER => DocumentProcessingHandler::class,
		PipelineSteps::PDF_TEXT_REPLACER => PdfTextReplacer::class,
		PipelineSteps::PDF_METADATA_SANITISER => PdfMetadataSanitizer::class,
		PipelineSteps::DOCX_SANITISER => DocxSanitizer::class,
		PipelineSteps::ODT_SANITISER => OdtSanitizer::class,
		PipelineSteps::IMAGE_BURNER => ImageBurner::class,
	];

	/**
	 * The step a class implements, or null when it is no registered step.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function stepImplementedBy(string $class): ?string {
		$step = array_search(ltrim($class, '\\'), self::IMPLEMENTED_BY, true);
		if ($step === false) {
			return null;
		}

		return $step;
	}//end stepImplementedBy()
}//end class
