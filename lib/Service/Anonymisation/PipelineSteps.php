<?php

/**
 * The content-altering steps of the anonymisation pipeline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
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

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;

/**
 * Every step that changes a document's content, the class that does it, the
 * file types it applies to, and the outcome of each step in one run.
 *
 * A new step is added here; the unit suite fails for a sanitiser class that
 * has no entry.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
 */
final class PipelineSteps {
	public const ANONYMISER = 'anonymiser';
	public const PDF_TEXT_REPLACER = 'pdf-text-replacer';
	public const PDF_METADATA_SANITISER = 'pdf-metadata-sanitiser';
	public const DOCX_SANITISER = 'docx-sanitiser';
	public const ODT_SANITISER = 'odt-sanitiser';

	public const OUTCOME_RAN = 'ran';
	public const OUTCOME_SKIPPED = 'skipped';
	public const OUTCOME_NOT_APPLICABLE = 'not-applicable';

	private const PDF = 'application/pdf';
	private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	private const ODT = 'application/vnd.oasis.opendocument.text';

	/**
	 * The steps in pipeline order: label, implementing class, and the MIME types it applies to (null: every type).
	 *
	 * @var array<string, array{label: string, implementedBy: class-string, mimeTypes: list<string>|null}>
	 */
	private const STEPS = [
		self::ANONYMISER => [
			'label' => 'Anonymiser (detected entities replaced by placeholders)',
			'implementedBy' => DocumentProcessingHandler::class,
			'mimeTypes' => null,
		],
		self::PDF_TEXT_REPLACER => [
			'label' => 'PDF text replacer',
			'implementedBy' => PdfTextReplacer::class,
			'mimeTypes' => [self::PDF],
		],
		self::PDF_METADATA_SANITISER => [
			'label' => 'PDF metadata sanitiser',
			'implementedBy' => PdfMetadataSanitizer::class,
			'mimeTypes' => [self::PDF],
		],
		self::DOCX_SANITISER => [
			'label' => 'DOCX sanitiser',
			'implementedBy' => DocxSanitizer::class,
			'mimeTypes' => [self::DOCX],
		],
		self::ODT_SANITISER => [
			'label' => 'ODT sanitiser',
			'implementedBy' => OdtSanitizer::class,
			'mimeTypes' => [self::ODT],
		],
	];

	/**
	 * The step ids, in pipeline order.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function ids(): array {
		return array_keys(self::STEPS);
	}//end ids()

	/**
	 * A step's label.
	 *
	 * @param string $step The step id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function label(string $step): string {
		return self::STEPS[$step]['label'] ?? $step;
	}//end label()

	/**
	 * The MIME types a step applies to; null for every type.
	 *
	 * @param string $step The step id.
	 *
	 * @return list<string>|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function mimeTypes(string $step): ?array {
		return self::STEPS[$step]['mimeTypes'] ?? null;
	}//end mimeTypes()

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
		foreach (self::STEPS as $step => $definition) {
			if ($definition['implementedBy'] === ltrim($class, '\\')) {
				return $step;
			}
		}

		return null;
	}//end stepImplementedBy()

	/**
	 * The outcome of every step in one run.
	 *
	 * A step that does not apply to the file type is `not-applicable`; one
	 * that applies and ran is `ran`; one that applies and did not run is
	 * `skipped`, with the reason.
	 *
	 * @param list<string> $ran      The steps that ran.
	 * @param string       $mimeType The file's MIME type.
	 *
	 * @return array<string, array{outcome: string, reason?: string}>
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	public function outcomes(array $ran, string $mimeType): array {
		$outcomes = [];
		foreach (self::STEPS as $step => $definition) {
			if ($definition['mimeTypes'] !== null && in_array($mimeType, $definition['mimeTypes'], true) === false) {
				$outcomes[$step] = ['outcome' => self::OUTCOME_NOT_APPLICABLE];
				continue;
			}

			if (in_array($step, $ran, true) === true) {
				$outcomes[$step] = ['outcome' => self::OUTCOME_RAN];
				continue;
			}

			$outcomes[$step] = ['outcome' => self::OUTCOME_SKIPPED, 'reason' => 'the step did not run for this file'];
		}

		return $outcomes;
	}//end outcomes()
}//end class
