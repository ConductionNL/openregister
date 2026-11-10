<?php

/**
 * DocumentProcessingHandlerInterface: the public surface of DocumentProcessingHandler, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;
use OCA\OpenRegister\Files\Service\File\DocumentAnonymisationRequest;
use OCA\OpenRegister\Files\Service\File\WordReplacementRequest;
use OCP\Files\File;

/**
 * Every public method of DocumentProcessingHandler. Callers depend on this interface, not on the class.
 */
interface DocumentProcessingHandlerInterface {

	/**
	 * Residual entities from the most recent anonymisation run.
	 *
	 * Best-effort policy: when some entity text could not be removed from the
	 * output (e.g. ExApp NER over-capture across table cells), the file is still
	 * produced and these records describe what remains so the caller can warn
	 * the operator. Empty when the last run fully redacted everything.
	 *
	 * @return array<int, array{text: string, type: string, id: string}> Residual records.
	 */
	public function getLastResidualEntities(): array;

	/**
	 * The `structurePreservation` result block from the most recent PDF
	 * redaction, mirroring the {@see getLastResidualEntities()} /
	 * {@see getLastSanitizationReport()} accessor pattern.
	 *
	 * @return StructurePreservation|null The result block, or null when the
	 *                                    last redaction did not take the PDF
	 *                                    branch (DOCX/ODT/text — no PDF
	 *                                    structure tree is involved).
	 *
	 * @spec openspec/specs/tag-preserving-redaction/spec.md#every-pdf-redaction-must-return-the-structurepreservation-result-block-with-the-exact-contracted-fields-req-ortpr-003
	 */
	public function getLastStructurePreservation(): ?StructurePreservation;

	/**
	 * Per-entity placeholder map from the most recent anonymize() call.
	 *
	 * Maps the internal global entity id (stringified) to the exact placeholder
	 * string emitted into the document (e.g. `"7" => "[PERSOON: 1]"`), so the
	 * grondslagen-summary can render the SAME placeholder the document carries
	 * (scope-local number + localized label) rather than re-deriving it from the
	 * global id. Empty when the last run matched no catalogue entity.
	 *
	 * @return array<string, string> Map of global entity id → emitted placeholder.
	 */
	public function getLastPlaceholderMap(): array;

	/**
	 * Replace words in a document.
	 *
	 * This method replaces specified words/phrases in a document file. It supports
	 * Word documents (.doc, .docx) using PHPWord and text files using simple string replacement.
	 * For Word documents, replacements are applied recursively across all sections, headers,
	 * footers, tables, and lists.
	 *
	 * @param WordReplacementRequest $request The node, the replacements (search => replace), the output
	 *                                         name and the PDF validation options.
	 *
	 * @throws Exception If node is not a file or replacement fails.
	 *
	 * @return File
	 *
	 * @phpstan-return File
	 *
	 * @psalm-return File
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function replaceWords(WordReplacementRequest $request): File;

	/**
	 * Anonymize a document by replacing entity values with placeholders.
	 *
	 * Detected entities are replaced with placeholders in the format
	 * [ENTITY_TYPE: key], built from the entity detection results and applied
	 * through {@see replaceWords()}. Placeholder numbering follows the options'
	 * scope: 'document' (default, the counter restarts per run, no persistence)
	 * or 'dossier' (consistent across all files in the dossier folder named by
	 * the file key, or the file's parent folder, recomputed deterministically).
	 * The PDF-only structure-preservation option (REQ-ORTPR-004) is tri-state:
	 * null = auto (preserve iff the input is a tagged PDF), true = attempt,
	 * false = skip but still measure.
	 *
	 * @param DocumentAnonymisationRequest $request The node, the detected entities
	 *                                              (`text`, `entityType`, `key`) and the options.
	 *
	 * @throws Exception If anonymization fails.
	 *
	 * @return File The anonymized document file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function anonymize(DocumentAnonymisationRequest $request): File;
}//end interface
