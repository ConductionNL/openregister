<?php

/**
 * DocumentProcessingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use Exception;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Files\Service\File\Pdf\RedactionPlan;
use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;
use OCA\OpenRegister\Service\Anonymisation\PipelineSteps;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\WordReplacementRequest;

/**
 * Handles document processing operations.
 *
 * This handler is responsible for:
 * - Replacing words in documents (Word, text files)
 * - Anonymizing documents by replacing entities
 * - Processing document transformations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrates the Word/PDF/text replacement pipelines.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   File / PDF / DOCX / sanitiser collaborators are required by design.
 */
class DocumentProcessingHandler implements DocumentProcessingHandlerInterface {

	/**
	 * The enumerated entity-type labels that get localised in the placeholder.
	 *
	 * The canonical source is the `EntityRecognitionHandler::ENTITY_TYPE_*`
	 * constants. Each value is registered as a translatable string in `l10n/`
	 * (en + nl), so `IL10N::t()` returns the localised label on a Dutch
	 * instance (`PERSON` → `PERSOON`). A type NOT in this set falls back to its
	 * raw string (no translation, no error).
	 *
	 * @var array<int, string>
	 */
	private const LOCALIZABLE_ENTITY_TYPES = [
		EntityRecognitionHandler::ENTITY_TYPE_PERSON,
		EntityRecognitionHandler::ENTITY_TYPE_ORGANIZATION,
		EntityRecognitionHandler::ENTITY_TYPE_LOCATION,
		EntityRecognitionHandler::ENTITY_TYPE_EMAIL,
		EntityRecognitionHandler::ENTITY_TYPE_PHONE,
		EntityRecognitionHandler::ENTITY_TYPE_ADDRESS,
		EntityRecognitionHandler::ENTITY_TYPE_DATE,
		EntityRecognitionHandler::ENTITY_TYPE_IBAN,
		EntityRecognitionHandler::ENTITY_TYPE_SSN,
		EntityRecognitionHandler::ENTITY_TYPE_IP_ADDRESS,
	];

	/**
	 * The most recent sanitisation report produced during anonymisation.
	 *
	 * Office (DOCX / ODT) anonymisation runs the sanitiser ahead of the
	 * entity walker; the resulting audit report is retained here so the
	 * caller can persist or surface it (there is no dedicated log table).
	 * Null when the last anonymisation did not involve a sanitisable format.
	 *
	 * @var SanitizationReport|null
	 */
	private ?SanitizationReport $lastSanitizationReport = null;

	/**
	 * Residual entities from the most recent anonymisation (best-effort policy).
	 *
	 * Each record: {text: string, type: string, id: string} for an entity whose
	 * text could not be fully removed from the output (e.g. the ExApp NER
	 * over-captured across table cells, so the value is not contiguous in the
	 * PDF). Empty when the last run was complete. Consumed by the controller to
	 * surface a warning so the operator can iterate (manual/skip entities).
	 *
	 * @var array<int, array{text: string, type: string, id: string}>
	 */
	private array $lastResidualEntities = [];

	/**
	 * The `structurePreservation` result block from the most recent PDF
	 * redaction (best-effort accessibility-structure preservation).
	 *
	 * Populated only when {@see anonymize()} / {@see replaceWords()}
	 * took the PDF branch; null for DOCX/ODT/text redactions (no PDF
	 * structure tree is involved) and before the first PDF run.
	 *
	 * @var StructurePreservation|null
	 */
	private ?StructurePreservation $lastStructurePreservation = null;

	/**
	 * Per-entity placeholder map from the most recent anonymisation.
	 *
	 * Maps the internal global entity id (`openregister_entities.id`, stringified)
	 * to the EXACT placeholder string emitted into the document for that entity
	 * (e.g. `"7" => "[PERSOON: 1]"`). Lets downstream consumers (DocuDesk's
	 * grondslagen-summary) render the SAME placeholder the document carries
	 * — scope-local number + localized TYPE label — instead of re-deriving
	 * `[<TYPE>: <entity_id>]` from the global id. Empty when the last run
	 * matched no catalogue entity.
	 *
	 * Keys are int|string, not string: entity ids are canonical numeric strings,
	 * which PHP coerces to int array keys.
	 *
	 * @var array<int|string, string>
	 */
	private array $lastPlaceholderMap = [];

	/**
	 * The content-altering steps that ran in the current anonymisation run.
	 *
	 * @var list<string>
	 */
	private array $stepsRan = [];

	/**
	 * Constructor for DocumentProcessingHandler.
	 *
	 * @param DocumentProcessingPlatform $platform  Root folder, user session and the optional acting-user localisation.
	 * @param DocumentRedactionServices  $redaction Entity relation mapper, office sanitiser and the optional anonymisation log mapper.
	 * @param LoggerInterface            $logger    Logger for logging operations.
	 */
	public function __construct(
		private readonly DocumentProcessingPlatform $platform,
		private readonly DocumentRedactionServices $redaction,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the sanitisation report from the most recent anonymisation, if any.
	 *
	 * @return SanitizationReport|null The report, or null when the last
	 *                                 anonymisation did not sanitise an Office document.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function getLastSanitizationReport(): ?SanitizationReport {
		return $this->lastSanitizationReport;
	}//end getLastSanitizationReport()

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
	public function getLastResidualEntities(): array {
		return $this->lastResidualEntities;
	}//end getLastResidualEntities()

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
	public function getLastStructurePreservation(): ?StructurePreservation {
		return $this->lastStructurePreservation;
	}//end getLastStructurePreservation()

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
	public function getLastPlaceholderMap(): array {
		return $this->lastPlaceholderMap;
	}//end getLastPlaceholderMap()

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
	 * @spec openspec/specs/tag-preserving-redaction/spec.md
	 */
	public function replaceWords(WordReplacementRequest $request): File {
		$file = $this->requireFile(node: $request->node);
		$job = new DocumentReplacement(
			node: $file,
			replacements: $request->replacements,
			outputName: $request->outputName ?? $this->replacedNameFor(file: $file)
		);
		return $this->replaceDocument(job: $job->withPdfOptions(strict: $request->strict, preserveStructure: $request->preserveStructure));
	}//end replaceWords()

	/**
	 * Replace words in a document described by one replacement run.
	 *
	 * Word documents go through PHPWord, PDFs through the byte-level pipeline,
	 * everything else through plain string replacement.
	 *
	 * @param DocumentReplacement $job The replacement run.
	 *
	 * @throws Exception If the replacement fails.
	 *
	 * @return File The written output file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function replaceDocument(DocumentReplacement $job): File {
		$fileExtension = strtolower(pathinfo($job->node->getName(), PATHINFO_EXTENSION));
		if (in_array($fileExtension, ['doc', 'docx'], true) === true) {
			return $this->replaceWordsInWordDocument(job: $job);
		}

		if ($fileExtension === 'pdf') {
			return $this->replaceWordsInPdfDocument(job: $job);
		}

		return $this->replaceWordsInTextDocument(job: $job);
	}//end replaceDocument()

	/**
	 * The node as a file, or a refusal when it is not one.
	 *
	 * @param Node $node The node to check.
	 *
	 * @throws Exception If the node is not a file.
	 *
	 * @return File The node as a file.
	 */
	private function requireFile(Node $node): File {
		if (($node instanceof File) === false) {
			throw new Exception('Node must be a file');
		}

		return $node;
	}//end requireFile()

	/**
	 * The default output name of a replacement: `<base>_replaced[.<ext>]`.
	 *
	 * @param File $file The source file.
	 *
	 * @return string The output name.
	 */
	private function replacedNameFor(File $file): string {
		$fileName = $file->getName();
		$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		$outputName = pathinfo($fileName, PATHINFO_FILENAME) . '_replaced';
		if (empty($fileExtension) === false) {
			$outputName .= '.' . $fileExtension;
		}

		return $outputName;
	}//end replacedNameFor()

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
	 * @spec openspec/specs/tag-preserving-redaction/spec.md
	 */
	public function anonymize(DocumentAnonymisationRequest $request): File {
		$node = $request->node;
		$this->resetRunState();

		// Resolve the source file id once — the substitution placeholder
		// format and the post-redaction audit flag both key off it.
		$fileId = $this->sourceFileId(node: $node);

		// Skipped relations are never redacted, whatever the caller passes
		// (entity-relation-grondslagen). The file's entity rows give stable
		// `[<TYPE>: <entity_id>]` placeholders, so a re-run is idempotent.
		$skippedValues = [];
		$entityRows = [];
		if ($fileId > 0) {
			$skippedValues = $this->redaction->entityRelationMapper->findSkippedEntityValuesForFile($fileId);
			$entityRows = $this->redaction->entityRelationMapper->findEntityIdsByValueForFile($fileId);
		}

		// Scope-local placeholder numbering (Decision 1 + 3): per document by
		// first appearance, or per dossier from the whole folder's stored rows,
		// so the same person is the same number across the dossier.
		$translator = PlaceholderIdTranslator::perDocument();
		if ($request->options->scope === 'dossier') {
			$translator = $this->recomputeFileTranslator(node: $node, fileKey: $request->options->fileKey);
		}

		$replacements = $this->replacementsFor(
			entities: $request->entities,
			lookup: new PlaceholderLookup(skippedValues: $skippedValues, entityRows: $entityRows, translator: $translator)
		);
		$anonymizedFileName = $this->anonymizedNameFor(node: $node);

		// Office (DOCX / ODT) documents carry PII in non-text structures the
		// walker cannot reach (comments, tracked changes, metadata, custom XML,
		// person field codes, hyperlink URLs). Sanitise to a clean derivative
		// BEFORE the entity walker pass. The original NC file is untouched.
		// Entity anonymisation runs strict: residual entity text is still
		// written and reported back, but a PDF output that cannot be re-read
		// for that check is refused rather than written as '_anonymized'.
		$anonymizedFile = $this->replaceWords(
			request: new WordReplacementRequest(
				node: $node,
				replacements: $replacements,
				outputName: $anonymizedFileName,
				strict: true,
				preserveStructure: $request->options->preserveStructure
			)
		);
		$this->markStepRan(step: PipelineSteps::ANONYMISER);
		if (($node instanceof File) === true
			&& $this->redaction->sanitizer->isSanitizable($node->getMimeType()) === true
		) {
			$anonymizedFile = $this->anonymizeSanitizableDocument(
				job: new DocumentReplacement(node: $node, replacements: $replacements, outputName: $anonymizedFileName)
			);
		}

		// Flip the source's EntityRelation rows to `anonymized = 1` so the
		// anonymised state is queryable downstream. Skip-aware (rows where
		// `skip_anonymization = 1` are preserved per the
		// `entity-relation-grondslagen` contract). Each relation's
		// `anonymized_value` is set to the EXACT placeholder emitted for its
		// entity (scope-local number + localized label, from
		// `lastPlaceholderMap`) — the only durable record of the scope-local
		// number, which isn't recoverable from the stored rows later. This
		// lets the grondslagen-summary render the same placeholder the
		// document carries without re-deriving from the global id. The
		// persistence lives exactly as long as the relation (overwritten on
		// re-anonymise, gone on delete). Relations whose entity is absent from
		// the map keep the legacy "[REDACTED]" marker.
		if ($fileId > 0 && empty($replacements) === false) {
			$this->markAnonymized(fileId: $fileId);
		}

		// Persist a per-run anonymisation log row carrying the sanitisation
		// report when a sanitisable Office document was processed. PDF /
		// plain-text runs intentionally leave `sanitization = null` per
		// spec `office-document-sanitization`. The log write is best-effort
		// — a persistence failure MUST NOT mask a successful redaction.
		$this->persistAnonymisationLog(node: $node, replacements: $replacements);

		return $anonymizedFile;
	}//end anonymize()

	/**
	 * Reset any report/residuals/placeholder-map/structure-result from a
	 * prior call on this (potentially reused) handler.
	 *
	 * @return void
	 */
	private function resetRunState(): void {
		$this->lastSanitizationReport = null;
		$this->lastResidualEntities = [];
		$this->lastPlaceholderMap = [];
		$this->lastStructurePreservation = null;
		$this->stepsRan = [];
	}//end resetRunState()

	/**
	 * Note that a content-altering step ran in this run.
	 *
	 * @param string $step One of the PipelineSteps ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-content-altering-step-reports-its-state-req-adi-006
	 */
	private function markStepRan(string $step): void {
		if (in_array($step, $this->stepsRan, true) === false) {
			$this->stepsRan[] = $step;
		}
	}//end markStepRan()

	/**
	 * The replacement map of an anonymisation: trimmed entity text to its
	 * placeholder, longest needle first.
	 *
	 * Empty and skipped values are left out. The needle is the trimmed text,
	 * because recognisers capture spans with surrounding whitespace the
	 * document never contains verbatim. Longest-first ordering keeps a short
	 * needle ("Amsterdam") from rewriting a longer one ("gemeente Amsterdam")
	 * before it matches; equal lengths tie-break bytewise so a re-run is
	 * identical. The comparator is untyped because PHP turns numeric needles
	 * ("2026", a BSN) into int keys.
	 *
	 * @param array<int, array{text?: string, entityType?: string, key?: string}> $entities The detected entities.
	 * @param PlaceholderLookup                                                   $lookup   Skips, entity rows and numbering.
	 *
	 * @return array<string, string> Needle to placeholder.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function replacementsFor(array $entities, PlaceholderLookup $lookup): array {
		$replacements = [];
		foreach ($entities as $entity) {
			$originalText = $entity['text'] ?? '';
			if (empty($originalText) === true || $lookup->isSkipped(text: $originalText) === true) {
				continue;
			}

			$needle = trim($originalText);
			if ($needle === '') {
				continue;
			}

			$replacements[$needle] = $this->placeholderFor(entity: $entity, lookup: $lookup);
		}

		uksort(
			$replacements,
			static function ($left, $right): int {
				$left = (string)$left;
				$right = (string)$right;
				return [mb_strlen($right), $left] <=> [mb_strlen($left), $right];
			}
		);

		return $replacements;
	}//end replacementsFor()

	/**
	 * The placeholder for one entity, its TYPE label localised (Decision 6).
	 *
	 * An entity with a stable row on the file gets its scope-local number,
	 * recorded in the placeholder map against the global entity id; any other
	 * entity gets its key, or a random UUID prefix when it has none.
	 *
	 * @param array{text?: string, entityType?: string, key?: string} $entity The entity.
	 * @param PlaceholderLookup                                       $lookup Entity rows and numbering.
	 *
	 * @return string The placeholder, e.g. `[PERSOON: 3]`.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function placeholderFor(array $entity, PlaceholderLookup $lookup): string {
		$localizedType = $this->localizeEntityType(entityType: ($entity['entityType'] ?? 'UNKNOWN'));
		$row = $lookup->rowFor(text: ($entity['text'] ?? ''));
		if ($row === null) {
			$key = $entity['key'] ?? substr(\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 0, 8);
			return '[' . $localizedType . ': ' . $key . ']';
		}

		$placeholder = '[' . $localizedType . ': ' . $lookup->translator->translate(entityId: $row['id']) . ']';
		$this->lastPlaceholderMap[(string)$row['id']] = $placeholder;

		return $placeholder;
	}//end placeholderFor()

	/**
	 * The output name of an anonymised copy: `<name>_anonymized.<ext>`.
	 *
	 * @param Node $node The source file.
	 *
	 * @return string The output name.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function anonymizedNameFor(Node $node): string {
		$fileName = $node->getName();
		$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);

		$anonymizedFileName = pathinfo($fileName, PATHINFO_FILENAME) . '_anonymized';
		if (empty($fileExtension) === false) {
			$anonymizedFileName .= '.' . $fileExtension;
		}

		return $anonymizedFileName;
	}//end anonymizedNameFor()

	/**
	 * Flip the source's entity relations to anonymised, each with the exact
	 * placeholder emitted for it (skip-aware). A failure here must not mask
	 * the redaction, which is already written, so it is only logged.
	 *
	 * @param int $fileId The source file id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function markAnonymized(int $fileId): void {
		try {
			$this->redaction->entityRelationMapper->markAsAnonymizedWithPlaceholders($fileId, $this->lastPlaceholderMap);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DocumentProcessingHandler: markAsAnonymizedWithPlaceholders failed after redaction',
				['fileId' => $fileId, 'error' => $e->getMessage()]
			);
		}
	}//end markAnonymized()

	/**
	 * Localise an entity-type label to the acting user's language for the
	 * placeholder (Decision 6). Only the enumerated entity-type set
	 * (`LOCALIZABLE_ENTITY_TYPES`, sourced from the
	 * `EntityRecognitionHandler::ENTITY_TYPE_*` constants) is translated; an
	 * unknown / free-form type is returned unchanged (no translation, no
	 * error). When no `IL10N` is injected the raw label is returned, which is
	 * also the `en` / untranslated behaviour.
	 *
	 * @param string $entityType The raw entity type (e.g. 'PERSON').
	 *
	 * @return string The localised label (e.g. 'PERSOON' on nl), or the raw type.
	 */
	private function localizeEntityType(string $entityType): string {
		if ($this->platform->l10n === null
			|| in_array($entityType, self::LOCALIZABLE_ENTITY_TYPES, true) === false
		) {
			return $entityType;
		}

		return $this->platform->l10n->t($entityType);
	}//end localizeEntityType()

	/**
	 * Build a per-dossier numbering translator by deterministically
	 * recomputing the `e.id → local_number` map from the dossier's stored
	 * entity-relation rows (Decision 3 — no table, no migration).
	 *
	 * Resolves the dossier folder from $fileKey (a stable folder id) or,
	 * when absent, the file's parent folder (Decision 2 fallback). Enumerates
	 * the folder's descendant files (recursively), loads their entity rows in
	 * one query (`findEntityIdsByValueForFiles`), and ranks distinct
	 * `entity_id`s by first appearance under the total order
	 * `(file_id, position_start, entity_id)`. The result is a pure function of
	 * the stored rows, so every per-file call within the dossier derives the
	 * same map. Any failure to resolve/enumerate the folder degrades to a
	 * per-document translator (fail-safe — never throws out of numbering).
	 *
	 * Per ADR-005 nothing here logs the entity value alongside its number.
	 *
	 * @param Node $node The file being anonymised.
	 * @param string|null $fileKey Stable folder id, or null to use the parent folder.
	 *
	 * @return PlaceholderIdTranslator Seeded with the dossier map.
	 */
	private function recomputeFileTranslator(Node $node, ?string $fileKey): PlaceholderIdTranslator {
		$folder = $this->resolveFileFolder(node: $node, fileKey: $fileKey);
		if ($folder === null) {
			return PlaceholderIdTranslator::perDocument();
		}

		$fileIds = $this->collectDescendantFileIds(folder: $folder);
		if ($fileIds === []) {
			return PlaceholderIdTranslator::perDocument();
		}

		$rows = $this->redaction->entityRelationMapper->findEntityIdsByValueForFiles(fileIds: $fileIds);

		// PII-free diagnostic (ADR-005): ids/counts only, never value → number.
		$this->logger->debug(
			message: '[DocumentProcessingHandler] Recomputed per-dossier placeholder numbering',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'dossier_key' => $fileKey,
				'folder_id' => $folder->getId(),
				'file_count' => count($fileIds),
				'row_count' => count($rows),
			]
		);

		return PlaceholderIdTranslator::forDossier(rows: $rows);
	}//end recomputeDossierTranslator()

	/**
	 * Resolve the dossier folder for per-dossier numbering: prefer the
	 * explicit $fileKey (a stable folder id), else fall back to the file's
	 * parent folder (Decision 2). Returns null when neither resolves to a
	 * usable folder (caller then degrades to per-document).
	 *
	 * @param Node $node The file being anonymised.
	 * @param string|null $fileKey Stable folder id, or null for the parent-folder fallback.
	 *
	 * @return Folder|null The dossier folder, or null when unresolved.
	 */
	private function resolveFileFolder(Node $node, ?string $fileKey): ?Folder {
		// Explicit, authoritative signal: the folder id.
		if ($fileKey !== null && trim($fileKey) !== '' && ctype_digit(trim($fileKey)) === true) {
			try {
				$matches = $this->platform->rootFolder->getById((int)trim($fileKey));
				foreach ($matches as $candidate) {
					if ($candidate instanceof Folder) {
						return $candidate;
					}
				}
			} catch (Throwable $e) {
				// Fall through to the parent-folder fallback below.
				unset($e);
			}
		}

		// Forgiving fallback: the file's parent folder IS the dossier.
		try {
			$parent = $node->getParent();
			if ($parent instanceof Folder) {
				return $parent;
			}
		} catch (Throwable $e) {
			unset($e);
		}

		return null;
	}//end resolveDossierFolder()

	/**
	 * Enumerate the descendant file ids of a dossier folder (recursive),
	 * via the Nextcloud Node API. Sub-folders are walked; only file nodes
	 * contribute ids.
	 *
	 * @param Folder $folder The dossier folder.
	 *
	 * @return array<int, int> Distinct descendant file ids.
	 */
	private function collectDescendantFileIds(Folder $folder): array {
		$fileIds = [];
		try {
			foreach ($folder->getDirectoryListing() as $child) {
				if ($child instanceof Folder) {
					foreach ($this->collectDescendantFileIds(folder: $child) as $nestedId) {
						$fileIds[] = $nestedId;
					}

					continue;
				}

				$childId = $child->getId();
				if (is_int($childId) === true && $childId > 0) {
					$fileIds[] = $childId;
				}
			}
		} catch (Throwable $e) {
			// Best-effort enumeration; partial/empty list degrades numbering
			// gracefully rather than failing the anonymise run.
			unset($e);
		}

		return array_values(array_unique($fileIds));
	}//end collectDescendantFileIds()

	/**
	 * Persist a per-run anonymisation log row.
	 *
	 * Best-effort: the file is already written; a DB-side failure is logged
	 * (PII-free) and swallowed. The row carries the JSON-serialised
	 * sanitisation report when an Office document was sanitised; non-Office
	 * runs leave `sanitization = null` (spec invariant).
	 *
	 * @param Node $node The source file node; its id (0 when absent) keys the row.
	 * @param array<string, string> $replacements The substitution map applied.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function persistAnonymisationLog(Node $node, array $replacements): void {
		if ($this->redaction->anonymisationLogMapper === null) {
			return;
		}

		$fileId = $this->sourceFileId(node: $node);
		$entity = new AnonymisationLog();
		$entity->setFileId($fileId);

		$mimeType = $this->logMimeType(node: $node);
		$entity->setMimeType($mimeType);
		$entity->setEngine($this->resolveEngineName(mimeType: $mimeType));
		$entity->setStatus(AnonymisationLog::STATUS_SUCCESS);
		$entity->setReplacements(count($replacements));

		// Every content-altering step: ran, skipped with the reason, or not-applicable (REQ-ADI-006).
		$steps = json_encode((new PipelineSteps())->outcomes(ran: $this->stepsRan, mimeType: $mimeType));
		if (is_string($steps) === true) {
			$entity->setSteps($steps);
		}

		if ($this->lastSanitizationReport !== null) {
			$encoded = json_encode($this->lastSanitizationReport->jsonSerialize());
			if (is_string($encoded) === true) {
				$entity->setSanitization($encoded);
			}
		}

		$entity->setCreated(new DateTime());

		try {
			$this->redaction->anonymisationLogMapper->insert(entity: $entity);
		} catch (Throwable $e) {
			$this->logger->warning(
				'DocumentProcessingHandler: AnonymisationLog persistence failed',
				['fileId' => $fileId, 'error' => $e->getMessage()]
			);
		}
	}//end persistAnonymisationLog()

	/**
	 * The source file id of a run, 0 when the node has none.
	 *
	 * The substitution placeholder format, the post-redaction audit flag and
	 * the anonymisation log row all key off it.
	 *
	 * @param Node $node The source file node.
	 *
	 * @return int The NC file id, or 0.
	 */
	private function sourceFileId(Node $node): int {
		// OCP\Files\Node declares getId(): int, so no method_exists() probe.
		$candidate = $node->getId();
		if ($candidate > 0) {
			return $candidate;
		}

		return 0;
	}//end sourceFileId()

	/**
	 * The MIME type recorded on the anonymisation log row, '' when unknown.
	 *
	 * @param Node $node The source file node.
	 *
	 * @return string The MIME type, or '' for a non-file node or a lookup failure.
	 */
	private function logMimeType(Node $node): string {
		if (($node instanceof File) === false) {
			return '';
		}

		try {
			return (string)$node->getMimeType();
		} catch (Throwable $ignored) {
			return '';
		}
	}//end logMimeType()

	/**
	 * Resolve a stable engine label for the anonymisation log row.
	 *
	 * @param string $mimeType The MIME type of the source file (when known).
	 *
	 * @return string The engine class short name used for the run.
	 */
	private function resolveEngineName(string $mimeType): string {
		if ($this->redaction->sanitizer->isSanitizable($mimeType) === true) {
			return 'OfficeDocumentSanitizer';
		}

		if ($mimeType === 'application/pdf') {
			return 'PdfTextReplacer';
		}

		return 'TextReplacer';
	}//end resolveEngineName()

	/**
	 * Anonymise a sanitisable Office document (DOCX / ODT).
	 *
	 * Runs the document sanitiser to produce a cleaned derivative, then runs
	 * the DOCX word replacement over the cleaned bytes. The replacement's
	 * output is written to the original file's parent folder under the run's
	 * output name. The sanitisation audit report is retained on
	 * {@see getLastSanitizationReport}.
	 *
	 * @param DocumentReplacement $job The Office file, replacement map and output name.
	 *
	 * @throws Exception If sanitisation or the walker pass fails.
	 *
	 * @return File The anonymised document.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function anonymizeSanitizableDocument(DocumentReplacement $job): File {
		try {
			$result = $this->redaction->sanitizer->sanitize($job->node->getId());
		} catch (SanitizationException $e) {
			if ($e->getReason() === SanitizationException::REASON_ENCRYPTED) {
				// Caller-correctable: cannot anonymise an encrypted document.
				throw new Exception('Cannot anonymise an encrypted document', 0, $e);
			}

			$this->logger->error(
				message: '[DocumentProcessingHandler] Office document sanitisation failed: ' . $e->getReason(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'reason' => $e->getReason(),
				]
			);
			throw new Exception('Document sanitisation failed', 0, $e);
		}//end try

		$this->lastSanitizationReport = $result->report;
		$this->markStepRan(step: $this->sanitiserStep(node: $job->node));

		$fileExtension = strtolower(pathinfo($job->node->getName(), PATHINFO_EXTENSION));

		// ODT and other non-DOCX office text route through the text replacer;
		// DOCX runs the DOCX word replacement. Both read the sanitised temp file.
		if ($fileExtension === 'docx') {
			return $this->replaceWordsInWordDocument(job: $job->withSanitizedSource(path: $result->path));
		}

		return $this->replaceWordsInOfficeContainer(job: $job->withSanitizedSource(path: $result->path));
	}//end anonymizeSanitizableDocument()

	/**
	 * The sanitiser step of an Office document: DOCX by its MIME type, else ODT.
	 *
	 * @param Node $node The source file.
	 *
	 * @return string The step id.
	 */
	private function sanitiserStep(Node $node): string {
		if ($this->logMimeType(node: $node) === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
			return PipelineSteps::DOCX_SANITISER;
		}

		return PipelineSteps::ODT_SANITISER;
	}//end sanitiserStep()

	/**
	 * Replace words inside a sanitised Office ZIP container's text parts.
	 *
	 * Used for the ODT path: the sanitised derivative is a valid ODT ZIP, so
	 * entity replacement is applied to the textual content parts in-place and
	 * the container is written to the original file's parent folder. This
	 * avoids the legacy raw-string-on-ZIP corruption path.
	 *
	 * @param DocumentReplacement $job The run; its sanitised source path is the ZIP container.
	 *
	 * @throws Exception If replacement fails.
	 *
	 * @return File The anonymised document.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	private function replaceWordsInOfficeContainer(DocumentReplacement $job): File {
		$sanitizedSourcePath = (string)$job->sanitizedSourcePath();
		$zip = new ZipArchive();
		if ($zip->open($sanitizedSourcePath) !== true) {
			throw new Exception('Failed to open sanitised Office container');
		}

		// ODT text content lives in content.xml; styles/headers may also carry
		// visible text. Apply replacements to the text-bearing parts.
		$textParts = ['content.xml', 'styles.xml'];
		foreach ($textParts as $part) {
			$xml = $zip->getFromName($part);
			if ($xml === false) {
				continue;
			}

			foreach ($job->replacements as $original => $replacement) {
				$xml = str_ireplace((string)$original, $replacement, $xml);
			}

			$zip->addFromString($part, $xml);
		}

		$zip->close();

		$this->removeExistingOutput(job: $job);

		$outputStream = fopen($sanitizedSourcePath, 'r');
		if ($outputStream === false) {
			throw new Exception('Failed to read sanitised Office container');
		}

		$newFile = $job->node->getParent()->newFile(path: $job->outputName, content: $outputStream);

		$this->logger->info(
			message: '[DocumentProcessingHandler] Office container anonymised',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'outputFile' => $newFile->getPath(),
				'replacements' => count($job->replacements),
			]
		);

		return $newFile;
	}//end replaceWordsInOfficeContainer()

	/**
	 * Delete an existing file with the run's output name next to the source.
	 *
	 * @param DocumentReplacement $job The run.
	 *
	 * @return void
	 */
	private function removeExistingOutput(DocumentReplacement $job): void {
		$parentFolder = $job->node->getParent();
		if ($parentFolder->nodeExists($job->outputName) === true) {
			$parentFolder->get($job->outputName)->delete();
		}
	}//end removeExistingOutput()

	/**
	 * Replace words in a Word document.
	 *
	 * Copies the DOCX to a temporary file, lets {@see DocxTextReplacer} edit
	 * its text parts (body, headers, footers, notes, comments) in place, and
	 * saves the result as a new file in the same parent folder. When the run
	 * carries a sanitised source path, those cleaned bytes are edited instead
	 * of the original node content.
	 *
	 * @param DocumentReplacement $job The run.
	 *
	 * @return File The new file node with replaced content.
	 *
	 * @throws Exception If replacement fails.
	 */
	private function replaceWordsInWordDocument(DocumentReplacement $job): File {
		$tempFile = tempnam(sys_get_temp_dir(), 'openregister_word_');
		if ($tempFile === false) {
			throw new Exception('Failed to create temporary file');
		}

		try {
			if ($job->sanitizedSourcePath() === null) {
				$this->copyNodeTo(node: $job->node, target: $tempFile);
			} elseif (copy($job->sanitizedSourcePath(), $tempFile) === false) {
				throw new Exception('Failed to copy sanitised source for processing');
			}

			(new DocxTextReplacer())->replaceInFile(path: $tempFile, replacements: $job->replacements);

			$this->removeExistingOutput(job: $job);

			// Nextcloud closes the stream once the file is written.
			$newFile = $job->node->getParent()->newFile(path: $job->outputName, content: fopen($tempFile, 'r'));

			$this->logger->debug(
				message: '[DocumentProcessingHandler] Words replaced in Word document',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'originalFile' => $job->node->getPath(),
					'outputFile' => $newFile->getPath(),
					'replacements' => count($job->replacements),
				]
			);

			return $newFile;
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[DocumentProcessingHandler] Failed to replace words in Word document: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e,
				]
			);
			throw new Exception('Failed to replace words in Word document: ' . $e->getMessage(), 0, $e);
		} finally {
			if (file_exists($tempFile) === true) {
				// NOSEMGREP: php.lang.security.unlink-use.unlink-use -- The path is the tempnam() file this method created.
				unlink($tempFile);
			}
		}//end try
	}//end replaceWordsInWordDocument()

	/**
	 * Copy a file node's bytes into a local file.
	 *
	 * @param Node   $node   The source file node.
	 * @param string $target The local file to fill.
	 *
	 * @return void
	 *
	 * @throws Exception When the source cannot be read or the target written.
	 */
	private function copyNodeTo(Node $node, string $target): void {
		$stream = $node->fopen('r');
		$tempStream = fopen($target, 'w');
		if ($stream === false || $tempStream === false) {
			throw new Exception('Failed to open the document for processing');
		}

		stream_copy_to_stream($stream, $tempStream);
		fclose($tempStream);
		fclose($stream);
	}//end copyNodeTo()

	/**
	 * Replace words in a text-based document.
	 *
	 * This method reads the content of a text file, applies string replacements,
	 * and saves the result as a new file in the same parent folder. This works
	 * for any text-based file format (.txt, .md, .html, etc.).
	 *
	 * @param DocumentReplacement $job The run.
	 *
	 * @return File The new file node with replaced content.
	 *
	 * @throws Exception If replacement fails.
	 */
	private function replaceWordsInTextDocument(DocumentReplacement $job): File {
		// File::getContent() returns the content string and throws on failure
		// (NotPermitted/Locked) — it never returns false, so no false-check.
		$modifiedContent = $job->node->getContent();
		foreach ($job->replacements as $original => $replacement) {
			$modifiedContent = str_ireplace((string)$original, $replacement, $modifiedContent);
		}

		$this->removeExistingOutput(job: $job);
		$newFile = $job->node->getParent()->newFile(path: $job->outputName, content: $modifiedContent);

		$this->logger->debug(
			message: '[DocumentProcessingHandler] Words replaced in text document',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'originalFile' => $job->node->getPath(),
				'outputFile' => $newFile->getPath(),
				'replacements' => count($job->replacements),
			]
		);

		return $newFile;
	}//end replaceWordsInTextDocument()

	/**
	 * Replace words in a PDF document via the SAPP byte-level pipeline.
	 *
	 * Routes to {@see PdfTextReplacer} (text replacement with font switch
	 * + Helvetica fallback, then /Info and XMP stripping via
	 * {@see PdfTextReplacer::sanitizeMetadata()}). The output is re-extracted post-replacement; residual
	 * substitution-map keys are logged as a PII-free warning, the partial PDF
	 * is written in both modes, and the residuals are kept for
	 * {@see getLastResidualEntities()} (best-effort, pdf-anonymisation spec).
	 * When the output cannot be re-read at all, a strict run (entity
	 * anonymisation) is refused with `REASON_VALIDATION_FAILED`: no file is
	 * written and an existing output is left alone. A lenient run (ad-hoc
	 * replace) writes it unchecked.
	 *
	 * Pre-dispatch: encrypted PDFs are rejected with `REASON_ENCRYPTED_PDF`
	 * (caller-correctable, HTTP 422); if smalot/pdfparser cannot extract any
	 * text from the input, the call defers to the `ocr-document-scanning`
	 * capability via `REASON_TEXT_LAYER_MISSING` (caller's responsibility to route).
	 *
	 * @param DocumentReplacement $job The PDF file, replacement map (entity-text =>
	 *                                 placeholder), output name, strict flag (an output
	 *                                 that cannot be re-read is refused instead of
	 *                                 written unchecked) and tri-state structure-
	 *                                 preservation option (REQ-ORTPR-004), forwarded to
	 *                                 {@see PdfTextReplacer::replaceInPdf()}.
	 *
	 * @throws Exception If node content is unreadable.
	 * @throws PdfAnonymisationException On encrypted PDF, missing text
	 *                                   layer, unreadable output (strict),
	 *                                   or internal pipeline errors.
	 *
	 * @return File The anonymised PDF.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 * @spec openspec/specs/tag-preserving-redaction/spec.md
	 */
	private function replaceWordsInPdfDocument(DocumentReplacement $job): File {
		// File::getContent() returns the content string and throws on failure
		// (NotPermitted/Locked) — it never returns false.
		$content = $job->node->getContent();
		$this->assertPdfTextLayer(content: $content);

		$replacer = new PdfTextReplacer(logger: $this->logger);

		// Run text replacement first; metadata sanitisation operates on
		// the result so /Info / XMP changes survive the rebuild.
		// Best-effort: replaceInPdf returns the residual needles so the file
		// is still produced with a warning. It throws before anything is
		// written when a strict run's output cannot be re-read.
		$redaction = $replacer->replaceInPdf(
			pdfBytes: $content,
			plan: new RedactionPlan(
				substitutions: $job->replacements,
				strict: $job->isStrict(),
				preserveStructure: $job->preserveStructure()
			)
		);

		// Expose the `structurePreservation` result block (REQ-ORTPR-003).
		// `PdfMetadataSanitizer::sanitize()` below strips ONLY /Info + XMP —
		// it never touches /StructTreeRoot, /MarkInfo or /StructElem objects
		// (ADR-022, consumed unchanged) — so the measurement taken inside
		// `replaceInPdf` already reflects the accessibility structure of the
		// bytes this method goes on to write.
		$this->lastStructurePreservation = $redaction->structurePreservation;
		$this->markStepRan(step: PipelineSteps::PDF_TEXT_REPLACER);

		if (empty($redaction->residualEntities) === false) {
			$this->lastResidualEntities = $this->residualEntityRecords(
				needles: $redaction->residualEntities,
				replacements: $job->replacements
			);
		}

		$replacedBytes = $this->sanitizePdfMetadata(replacer: $replacer, pdfBytes: $redaction->bytes);
		$this->markStepRan(step: PipelineSteps::PDF_METADATA_SANITISER);

		$this->removeExistingOutput(job: $job);
		$newFile = $job->node->getParent()->newFile(path: $job->outputName, content: $replacedBytes);

		$this->logger->info(
			message: '[DocumentProcessingHandler] PDF anonymised via SAPP',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'originalFile' => $job->node->getPath(),
				'outputFile' => $newFile->getPath(),
				'replacements' => count($job->replacements),
			]
		);

		return $newFile;
	}//end replaceWordsInPdfDocument()

	/**
	 * Refuse PDFs that cannot be anonymised by byte replacement.
	 *
	 * Encrypted PDFs carry an `/Encrypt N G R` indirect reference in the
	 * trailer dictionary. SAPP cannot byte-replace, and smalot cannot
	 * extract, an encrypted PDF without the password, which is out of scope
	 * for v1, so it is refused up front with the caller-correctable
	 * REASON_ENCRYPTED_PDF (HTTP 422). The `\s+\d+\s+\d+\s+R` form matches the
	 * trailer's indirect reference and avoids false positives from the
	 * literal string inside a content stream. Image-only scans defer to the
	 * `ocr-document-scanning` capability rather than producing an empty
	 * no-op output.
	 *
	 * @param string $content The PDF bytes.
	 *
	 * @throws PdfAnonymisationException When the PDF is encrypted, unparsable or has no text layer.
	 *
	 * @return void
	 */
	private function assertPdfTextLayer(string $content): void {
		if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $content) === 1) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_ENCRYPTED_PDF,
				message: 'PDF is encrypted; decryption is out of scope (v1)',
				diagnostic: ['stage' => 'input.encryption_probe']
			);
		}

		if (trim($this->extractPdfText(content: $content)) === '') {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_TEXT_LAYER_MISSING,
				message: 'PDF has no extractable text layer; defer to OCR',
				diagnostic: ['stage' => 'input.text_layer_probe']
			);
		}
	}//end assertPdfTextLayer()

	/**
	 * Extract the text layer of a PDF for the pre-dispatch probe.
	 *
	 * The smalot parser rejects encrypted/secured PDFs with a message rather
	 * than a typed exception; that maps to the caller-correctable 422.
	 * Everything else is a genuine input-parse failure (500).
	 *
	 * @param string $content The PDF bytes.
	 *
	 * @throws PdfAnonymisationException When the parser fails.
	 *
	 * @return string The extracted text.
	 */
	private function extractPdfText(string $content): string {
		try {
			return (new PdfExtractor(logger: $this->logger))->extractText(pdfBytes: $content);
		} catch (\Throwable $e) {
			if (preg_match('/secured|encrypt/i', $e->getMessage()) === 1) {
				throw new PdfAnonymisationException(
					reason: PdfAnonymisationException::REASON_ENCRYPTED_PDF,
					message: 'PDF is encrypted; decryption is out of scope (v1)',
					diagnostic: ['stage' => 'input.encryption_probe'],
					previous: $e
				);
			}

			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'smalot/pdfparser failed on input PDF',
				diagnostic: ['stage' => 'input.text_layer_probe'],
				previous: $e
			);
		}//end try
	}//end extractPdfText()

	/**
	 * Map residual needles back to entity records via the placeholder map.
	 *
	 * Placeholders have the form `[<TYPE>: <id>]`; anything else yields type
	 * `UNKNOWN` and an empty id. Logs stay PII-free (ADR-005); the text is
	 * carried only to the authenticated anonymise response for the
	 * operator's review/iterate UI.
	 *
	 * @param array<int, string> $needles Residual substitution-map keys.
	 * @param array<string, string> $replacements Map: entity-text => placeholder.
	 *
	 * @return array<int, array{text: string, type: string, id: string}> The records.
	 */
	private function residualEntityRecords(array $needles, array $replacements): array {
		$records = [];
		foreach ($needles as $needle) {
			$placeholder = $replacements[$needle] ?? '';
			$type = 'UNKNOWN';
			$id = '';
			if (preg_match('/^\[([^:\]]+):\s*([^\]]*)\]$/', $placeholder, $m) === 1) {
				$type = trim($m[1]);
				$id = trim($m[2]);
			}

			$records[] = ['text' => (string)$needle, 'type' => $type, 'id' => $id];
		}

		return $records;
	}//end residualEntityRecords()

	/**
	 * Strip /Info and XMP metadata from the replaced PDF bytes.
	 *
	 * @param PdfTextReplacer $replacer The replacer of this run.
	 * @param string $pdfBytes The replaced PDF bytes.
	 *
	 * @throws PdfAnonymisationException When sanitisation fails.
	 *
	 * @return string The sanitised bytes.
	 */
	private function sanitizePdfMetadata(PdfTextReplacer $replacer, string $pdfBytes): string {
		try {
			return $replacer->sanitizeMetadata(pdfBytes: $pdfBytes);
		} catch (PdfAnonymisationException $e) {
			// Sanitiser-raised (or the fail-closed guards above) — surface to the caller.
			throw $e;
		} catch (\Throwable $e) {
			throw new PdfAnonymisationException(
				reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
				message: 'Metadata sanitisation stage failed',
				diagnostic: ['stage' => 'sanitize'],
				previous: $e
			);
		}//end try
	}//end sanitizePdfMetadata()
}//end class
