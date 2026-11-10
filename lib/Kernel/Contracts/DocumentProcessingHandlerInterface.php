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
use OCP\Files\File;
use OCP\Files\Node;

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
	 * Per-entity placeholder map from the most recent anonymizeDocument() call.
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
	 * @param Node $node The file node to process.
	 * @param array $replacements Array of replacement mappings (search => replace).
	 * @param string|null $outputName Optional name for the output file.
	 * @param bool $strict PDF only: when true (entity anonymisation), an
	 *                     output that cannot be re-read for the residual
	 *                     check is refused instead of written unchecked.
	 * @param bool|null $preserveStructure PDF only (REQ-ORTPR-004): tri-state structure-
	 *                                     preservation option — null/absent = auto (preserve
	 *                                     iff the input is a tagged PDF), true = attempt,
	 *                                     false = skip but still measure. Ignored for
	 *                                     DOCX/ODT/text (no PDF structure tree involved).
	 *
	 * @throws Exception If node is not a file or replacement fails.
	 *
	 * @phpstan-param array<string, string> $replacements
	 *
	 * @psalm-param array<string, string> $replacements
	 *
	 * @return File
	 *
	 * @phpstan-return File
	 *
	 * @psalm-return File
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects strict vs lenient validation per the entity-anonymisation contract.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function replaceWords(
		Node $node,
		array $replacements,
		?string $outputName = null,
		bool $strict = false,
		?bool $preserveStructure = null,
	): File;

	/**
	 * Anonymize a document by replacing entity values.
	 *
	 * This method anonymizes a document by replacing detected entities with placeholders
	 * in the format [ENTITY_TYPE: key]. It builds a replacement mapping from entity detection
	 * results and applies them using the replaceWords method.
	 *
	 * @param Node $node The file node to anonymize.
	 * @param array $entities Array of detected entities with 'text', 'entityType', and 'key' fields.
	 * @param string $scope Placeholder-numbering scope: 'document' (default — counter restarts
	 *                      per run, no persistence) or 'dossier' (counter consistent across all
	 *                      files in the dossier folder, recomputed deterministically).
	 * @param string|null $fileKey Stable folder id identifying the dossier when $scope='dossier'.
	 *                             When absent (and scope='dossier') the file's parent folder is used.
	 *                             Ignored for the per-document scope.
	 * @param bool|null $preserveStructure PDF only (REQ-ORTPR-004): tri-state structure-preservation
	 *                                     option forwarded to {@see replaceWords()} — null/absent = auto
	 *                                     (preserve iff the input is a tagged PDF), true = attempt,
	 *                                     false = skip but still measure.
	 *
	 * @throws Exception If anonymization fails.
	 *
	 * @phpstan-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
	 *
	 * @psalm-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
	 *
	 * @return File The anonymized document file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md
	 */
	public function anonymizeDocument(
		Node $node,
		array $entities,
		string $scope = 'document',
		?string $fileKey = null,
		?bool $preserveStructure = null,
	): File;
}//end interface
