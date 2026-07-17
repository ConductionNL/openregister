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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AnonymisationLog;
use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use Throwable;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrates the Word/PDF/text replacement pipelines plus SAPP + PhpWord integration.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   File / PDF / PhpWord / sanitiser collaborators are required by design.
 */
class DocumentProcessingHandler
{

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
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

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
     * @var array<string, string>
     */
    private array $lastPlaceholderMap = [];

    /**
     * Constructor for DocumentProcessingHandler.
     *
     * @param IRootFolder                               $rootFolder             Root folder for file access.
     * @param IUserSession                              $userSession            User session for getting current user.
     * @param LoggerInterface                           $logger                 Logger for logging operations.
     * @param \OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper   Used to honour skip-anonymization
     *                                                                          flags during the redaction pass
     *                                                                          (see
     *                                                                          `entity-relation-grondslagen`).
     * @param OfficeDocumentSanitizer                   $sanitizer              Office document sanitiser (DOCX / ODT).
     * @param AnonymisationLogMapper|null               $anonymisationLogMapper Mapper for persisting per-run anonymisation
     *                                                                          log rows (carries the sanitisation report).
     *                                                                          Nullable so the handler stays construct-safe
     *                                                                          for tests that do not need persistence.
     * @param IL10N|null                                $l10n                   Acting-user localisation, used to translate
     *                                                                          the placeholder TYPE label (e.g. PERSON →
     *                                                                          PERSOON on a Dutch instance). Nullable: when
     *                                                                          absent the raw English label is emitted
     *                                                                          (construct-safe for tests / non-localised
     *                                                                          callers).
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly \OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
        private readonly OfficeDocumentSanitizer $sanitizer,
        private readonly ?AnonymisationLogMapper $anonymisationLogMapper=null,
        private readonly ?IL10N $l10n=null
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
    public function getLastSanitizationReport(): ?SanitizationReport
    {
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
    public function getLastResidualEntities(): array
    {
        return $this->lastResidualEntities;
    }//end getLastResidualEntities()

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
    public function getLastPlaceholderMap(): array
    {
        return $this->lastPlaceholderMap;
    }//end getLastPlaceholderMap()

    /**
     * Set the FileService instance for cross-handler coordination.
     *
     * @param FileService $fileService The file service instance.
     *
     * @return void
     */
    public function setFileService(FileService $fileService): void
    {
        $this->fileService = $fileService;
    }//end setFileService()

    /**
     * Replace words in a document.
     *
     * This method replaces specified words/phrases in a document file. It supports
     * Word documents (.doc, .docx) using PHPWord and text files using simple string replacement.
     * For Word documents, replacements are applied recursively across all sections, headers,
     * footers, tables, and lists.
     *
     * @param Node        $node         The file node to process.
     * @param array       $replacements Array of replacement mappings (search => replace).
     * @param string|null $outputName   Optional name for the output file.
     * @param bool        $strict       PDF only: when true (entity anonymisation),
     *                                  residual entity text in the output fails
     *                                  closed instead of being logged as partial.
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $strict selects fail-closed vs lenient validation per the entity-anonymisation contract.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function replaceWords(Node $node, array $replacements, ?string $outputName=null, bool $strict=false): File
    {
        if (($node instanceof File) === false) {
            throw new Exception('Node must be a file');
        }

        $fileName      = $node->getName();
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileBaseName  = pathinfo($fileName, PATHINFO_FILENAME);

        // Generate output file name if not provided.
        if ($outputName === null) {
            $outputName = $fileBaseName.'_replaced';
            if (empty($fileExtension) === false) {
                $outputName .= '.'.$fileExtension;
            }
        }

        // Process based on file type.
        if (in_array($fileExtension, ['doc', 'docx'], true) === true) {
            return $this->replaceWordsInWordDocument(node: $node, replacements: $replacements, outputName: $outputName);
        }

        if ($fileExtension === 'pdf') {
            return $this->replaceWordsInPdfDocument(node: $node, replacements: $replacements, outputName: $outputName, strict: $strict);
        }

        return $this->replaceWordsInTextDocument(node: $node, replacements: $replacements, outputName: $outputName);
    }//end replaceWords()

    /**
     * Anonymize a document by replacing entity values.
     *
     * This method anonymizes a document by replacing detected entities with placeholders
     * in the format [ENTITY_TYPE: key]. It builds a replacement mapping from entity detection
     * results and applies them using the replaceWords method.
     *
     * @param Node        $node       The file node to anonymize.
     * @param array       $entities   Array of detected entities with 'text', 'entityType', and 'key' fields.
     * @param string      $scope      Placeholder-numbering scope: 'document' (default — counter restarts
     *                                per run, no persistence) or 'dossier' (counter consistent across all
     *                                files in the dossier folder, recomputed deterministically).
     * @param string|null $dossierKey Stable folder id identifying the dossier when $scope='dossier'.
     *                                When absent (and scope='dossier') the file's parent folder is used.
     *                                Ignored for the per-document scope.
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
     */
    public function anonymizeDocument(
        Node $node,
        array $entities,
        string $scope='document',
        ?string $dossierKey=null
    ): File {
        // Reset any report/residuals/placeholder-map from a prior call on this
        // (potentially reused) handler.
        $this->lastSanitizationReport = null;
        $this->lastResidualEntities   = [];
        $this->lastPlaceholderMap     = [];

        // Resolve the source file id once — the substitution placeholder
        // format and the post-redaction audit flag both key off it.
        $fileId = 0;
        if (method_exists($node, 'getId') === true) {
            $candidate = $node->getId();
            if (is_int($candidate) === true && $candidate > 0) {
                $fileId = $candidate;
            }
        }

        // Defensive filter — per the `entity-relation-grondslagen` change,
        // the DI anonymise path MUST honour the operator's skip decisions
        // even when the caller's entities[] array includes flagged
        // occurrences. The OR contract is "skipped relations are never
        // redacted, full stop", regardless of caller filtering behaviour.
        $skippedValues = [];
        if ($fileId > 0) {
            $skippedValues = $this->entityRelationMapper->findSkippedEntityValuesForFile($fileId);
        }

        // Resolve the existing entity-id map for this file so substitutions
        // use the stable `[<TYPE>: <entity_id>]` placeholder format —
        // matches what DocuDesk's grondslagen-summary report shows and
        // makes re-runs of anonymise on the same document idempotent
        // (the previous UUID-prefix fallback produced a fresh placeholder
        // per call, so re-anonymising the same file produced byte-divergent
        // output despite identical inputs).
        $entityIdMap = [];
        if ($fileId > 0) {
            $entityIdMap = $this->entityRelationMapper->findEntityIdsByValueForFile($fileId);
        }

        // Scope-local placeholder numbering (Decision 1 + 3): translate the
        // internal global `e.id` to a number that is local to this scope, so
        // the emitted `[<TYPE>: <number>]` never links a person across
        // documents/publications. Per-document (default) numbers lazily by
        // first appearance; per-dossier seeds the translator with a map
        // deterministically recomputed from the whole dossier's stored rows,
        // so the same person is the same number across every file in the
        // folder.
        if ($scope === 'dossier') {
            $translator = $this->recomputeDossierTranslator(node: $node, dossierKey: $dossierKey);
        } else {
            $translator = PlaceholderIdTranslator::perDocument();
        }

        // Build replacements array from entities.
        $replacements = [];
        foreach ($entities as $entity) {
            $originalText = $entity['text'] ?? '';
            $entityType   = $entity['entityType'] ?? 'UNKNOWN';

            if (empty($originalText) === true
                || in_array($originalText, $skippedValues, true) === true
            ) {
                continue;
            }

            // The needle actually matched/replaced in the document is the
            // trimmed text: some recognition backends (and the regex pass)
            // capture entity spans with surrounding whitespace (e.g.
            // "06-12345678 "), which the document's content stream never
            // contains verbatim — leaving the value unredacted while the
            // (whitespace-normalising) validation gate still flags it as
            // residual. Trim for the map KEY; keep $originalText for the
            // skip-list and stable-id lookups (those are keyed by the stored
            // value).
            $needle = trim($originalText);
            if ($needle === '') {
                continue;
            }

            // Prefer stable per-entity placeholder. Fall back to the
            // legacy UUID-prefix only when there is no matching entity
            // row on the file (shouldn't happen in the normal
            // extract → review → anonymise flow, but defensive against
            // direct DI callers that bypass extraction).
            // Localise the TYPE label to the acting user's language
            // (Decision 6): PERSON → PERSOON on a Dutch instance. Unknown
            // types fall back to the raw label.
            $localizedType = $this->localizeEntityType(entityType: $entityType);

            $key = $entity['key'] ?? substr(\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 0, 8);
            $replacements[$needle] = '['.$localizedType.': '.$key.']';
            if (isset($entityIdMap[$originalText]) === true) {
                // Translate the internal `e.id` to the scope-local number.
                $localNumber           = $translator->translate(entityId: $entityIdMap[$originalText]['id']);
                $replacements[$needle] = '['.$localizedType.': '.$localNumber.']';
                // Record the EXACT emitted placeholder against the global e.id
                // so consumers (DocuDesk's grondslagen-summary) can render the
                // same string (scope-local number + localized label) instead of
                // re-deriving from the global id.
                $this->lastPlaceholderMap[(string) $entityIdMap[$originalText]['id']] = $replacements[$needle];
            }
        }//end foreach

        // Order needles longest-first so overlapping entities cannot clobber
        // each other: with insertion order, a bare "Amsterdam" [LOCATION]
        // earlier in the map rewrites "De gemeente Amsterdam" to
        // "De gemeente [LOCATION: …]" before the longer needle
        // "gemeente Amsterdam" gets a chance to match, leaving it
        // unmatched and mis-typed. Longest-first guarantees every needle
        // sees the still-untouched text it was detected in for the
        // str_ireplace branches (docx/odt/txt), which consume the map in
        // insertion order; the PDF branch re-asserts the same ordering
        // inside PdfTextReplacer::replaceInPdf so the guarantee survives
        // SAPP-side changes. Equal lengths tie-break bytewise for
        // deterministic (idempotent re-run) output. Params deliberately
        // untyped: PHP coerces purely-numeric needle text ("2026", a
        // spaceless BSN) to INT array keys, which would fatal a
        // string-typed closure.
        uksort(
            $replacements,
            static function ($left, $right): int {
                $left  = (string) $left;
                $right = (string) $right;
                return [mb_strlen($right), $left] <=> [mb_strlen($left), $right];
            }
        );

        // Generate anonymized file name.
        $fileName      = $node->getName();
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileBaseName  = pathinfo($fileName, PATHINFO_FILENAME);

        $anonymizedFileName = $fileBaseName.'_anonymized';
        if (empty($fileExtension) === false) {
            $anonymizedFileName .= '.'.$fileExtension;
        }

        // Office (DOCX / ODT) documents carry PII in non-text structures the
        // walker cannot reach (comments, tracked changes, metadata, custom XML,
        // person field codes, hyperlink URLs). Sanitise to a clean derivative
        // BEFORE the entity walker pass. The original NC file is untouched.
        // Entity anonymisation is GDPR-critical: fail closed (strict) if any
        // original entity text survives in the output rather than writing a
        // file marked '_anonymized' that still contains it.
        $anonymizedFile = $this->replaceWords(node: $node, replacements: $replacements, outputName: $anonymizedFileName, strict: true);
        if (($node instanceof File) === true
            && $this->sanitizer->isSanitizable($node->getMimeType()) === true
        ) {
            $anonymizedFile = $this->anonymizeSanitizableDocument(
                node: $node,
                replacements: $replacements,
                outputName: $anonymizedFileName
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
            try {
                $this->entityRelationMapper->markAsAnonymizedWithPlaceholders($fileId, $this->lastPlaceholderMap);
            } catch (\Throwable $e) {
                // Persistence-side failure on the audit flag MUST NOT mask
                // the successful redaction; the file is already written.
                // Surface via warning log; downstream summary reports
                // simply won't see this file until the next anonymise
                // call retries the mark.
                $this->logger->warning(
                    'DocumentProcessingHandler: markAsAnonymizedWithPlaceholders failed after redaction',
                    ['fileId' => $fileId, 'error' => $e->getMessage()]
                );
            }
        }

        // Persist a per-run anonymisation log row carrying the sanitisation
        // report when a sanitisable Office document was processed. PDF /
        // plain-text runs intentionally leave `sanitization = null` per
        // spec `office-document-sanitization`. The log write is best-effort
        // — a persistence failure MUST NOT mask a successful redaction.
        $this->persistAnonymisationLog(
            node: $node,
            fileId: $fileId,
            replacements: $replacements
        );

        return $anonymizedFile;
    }//end anonymizeDocument()

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
    private function localizeEntityType(string $entityType): string
    {
        if ($this->l10n === null
            || in_array($entityType, self::LOCALIZABLE_ENTITY_TYPES, true) === false
        ) {
            return $entityType;
        }

        return $this->l10n->t($entityType);

    }//end localizeEntityType()

    /**
     * Build a per-dossier numbering translator by deterministically
     * recomputing the `e.id → local_number` map from the dossier's stored
     * entity-relation rows (Decision 3 — no table, no migration).
     *
     * Resolves the dossier folder from $dossierKey (a stable folder id) or,
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
     * @param Node        $node       The file being anonymised.
     * @param string|null $dossierKey Stable folder id, or null to use the parent folder.
     *
     * @return PlaceholderIdTranslator Seeded with the dossier map.
     */
    private function recomputeDossierTranslator(Node $node, ?string $dossierKey): PlaceholderIdTranslator
    {
        $folder = $this->resolveDossierFolder(node: $node, dossierKey: $dossierKey);
        if ($folder === null) {
            return PlaceholderIdTranslator::perDocument();
        }

        $fileIds = $this->collectDescendantFileIds(folder: $folder);
        if ($fileIds === []) {
            return PlaceholderIdTranslator::perDocument();
        }

        $rows = $this->entityRelationMapper->findEntityIdsByValueForFiles(fileIds: $fileIds);

        // PII-free diagnostic (ADR-005): ids/counts only, never value → number.
        $this->logger->debug(
            message: '[DocumentProcessingHandler] Recomputed per-dossier placeholder numbering',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'dossier_key' => $dossierKey,
                'folder_id'   => $folder->getId(),
                'file_count'  => count($fileIds),
                'row_count'   => count($rows),
            ]
        );

        return PlaceholderIdTranslator::forDossier(rows: $rows);

    }//end recomputeDossierTranslator()

    /**
     * Resolve the dossier folder for per-dossier numbering: prefer the
     * explicit $dossierKey (a stable folder id), else fall back to the file's
     * parent folder (Decision 2). Returns null when neither resolves to a
     * usable folder (caller then degrades to per-document).
     *
     * @param Node        $node       The file being anonymised.
     * @param string|null $dossierKey Stable folder id, or null for the parent-folder fallback.
     *
     * @return Folder|null The dossier folder, or null when unresolved.
     */
    private function resolveDossierFolder(Node $node, ?string $dossierKey): ?Folder
    {
        // Explicit, authoritative signal: the folder id.
        if ($dossierKey !== null && trim($dossierKey) !== '' && ctype_digit(trim($dossierKey)) === true) {
            try {
                $matches = $this->rootFolder->getById((int) trim($dossierKey));
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
    private function collectDescendantFileIds(Folder $folder): array
    {
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
     * @param Node                  $node         The source file node.
     * @param int                   $fileId       The NC file id (0 when absent).
     * @param array<string, string> $replacements The substitution map applied.
     *
     * @return void
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function persistAnonymisationLog(Node $node, int $fileId, array $replacements): void
    {
        if ($this->anonymisationLogMapper === null) {
            return;
        }

        $entity = new AnonymisationLog();
        $entity->setFileId($fileId);

        $mimeType = '';
        if ($node instanceof File) {
            try {
                $mimeType = (string) $node->getMimeType();
            } catch (Throwable $ignored) {
                $mimeType = '';
            }
        }

        $entity->setMimeType($mimeType);
        $entity->setEngine($this->resolveEngineName(mimeType: $mimeType));
        $entity->setStatus(AnonymisationLog::STATUS_SUCCESS);
        $entity->setReplacements(count($replacements));

        if ($this->lastSanitizationReport !== null) {
            $encoded = json_encode($this->lastSanitizationReport->jsonSerialize());
            if (is_string($encoded) === true) {
                $entity->setSanitization($encoded);
            }
        }

        $entity->setCreated(new DateTime());

        try {
            $this->anonymisationLogMapper->insert(entity: $entity);
        } catch (Throwable $e) {
            $this->logger->warning(
                'DocumentProcessingHandler: AnonymisationLog persistence failed',
                ['fileId' => $fileId, 'error' => $e->getMessage()]
            );
        }
    }//end persistAnonymisationLog()

    /**
     * Resolve a stable engine label for the anonymisation log row.
     *
     * @param string $mimeType The MIME type of the source file (when known).
     *
     * @return string The engine class short name used for the run.
     */
    private function resolveEngineName(string $mimeType): string
    {
        if ($this->sanitizer->isSanitizable($mimeType) === true) {
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
     * the existing PhpWord entity walker over the cleaned bytes. The walker's
     * output is written to the original file's parent folder under $outputName.
     * The sanitisation audit report is retained on {@see getLastSanitizationReport}.
     *
     * @param File   $node         The Office file to anonymise.
     * @param array  $replacements Entity-text => placeholder replacement map.
     * @param string $outputName   Name for the anonymised output file.
     *
     * @throws Exception If sanitisation or the walker pass fails.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return File The anonymised document.
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function anonymizeSanitizableDocument(File $node, array $replacements, string $outputName): File
    {
        try {
            $result = $this->sanitizer->sanitize($node->getId());
        } catch (SanitizationException $e) {
            if ($e->getReason() === SanitizationException::REASON_ENCRYPTED) {
                // Caller-correctable: cannot anonymise an encrypted document.
                throw new Exception('Cannot anonymise an encrypted document', 0, $e);
            }

            $this->logger->error(
                message: '[DocumentProcessingHandler] Office document sanitisation failed: '.$e->getReason(),
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'reason' => $e->getReason(),
                ]
            );
            throw new Exception('Document sanitisation failed', 0, $e);
        }//end try

        $this->lastSanitizationReport = $result->report;

        $fileExtension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));

        // ODT and other non-DOCX office text route through the text replacer;
        // DOCX runs the PhpWord walker. Both read the sanitised temp file.
        if ($fileExtension === 'docx') {
            return $this->replaceWordsInWordDocument(
                node: $node,
                replacements: $replacements,
                outputName: $outputName,
                sanitizedSourcePath: $result->path
            );
        }

        return $this->replaceWordsInOfficeContainer(
            node: $node,
            replacements: $replacements,
            outputName: $outputName,
            sanitizedSourcePath: $result->path
        );
    }//end anonymizeSanitizableDocument()

    /**
     * Replace words inside a sanitised Office ZIP container's text parts.
     *
     * Used for the ODT path: the sanitised derivative is a valid ODT ZIP, so
     * entity replacement is applied to the textual content parts in-place and
     * the container is written to the original file's parent folder. This
     * avoids the legacy raw-string-on-ZIP corruption path.
     *
     * @param File   $node                The original Office file (for parent folder).
     * @param array  $replacements        Entity-text => placeholder replacement map.
     * @param string $outputName          Name for the output file.
     * @param string $sanitizedSourcePath Path to the sanitised ZIP container.
     *
     * @throws Exception If replacement fails.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return File The anonymised document.
     *
     * @spec openspec/specs/office-document-sanitization/spec.md
     */
    private function replaceWordsInOfficeContainer(
        File $node,
        array $replacements,
        string $outputName,
        string $sanitizedSourcePath
    ): File {
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

            foreach ($replacements as $original => $replacement) {
                $xml = str_ireplace((string) $original, $replacement, $xml);
            }

            $zip->addFromString($part, $xml);
        }

        $zip->close();

        $parentFolder = $node->getParent();
        if ($parentFolder->nodeExists($outputName) === true) {
            $parentFolder->get($outputName)->delete();
        }

        $outputStream = fopen($sanitizedSourcePath, 'r');
        if ($outputStream === false) {
            throw new Exception('Failed to read sanitised Office container');
        }

        $newFile = $parentFolder->newFile(path: $outputName, content: $outputStream);

        $this->logger->info(
            message: '[DocumentProcessingHandler] Office container anonymised',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'outputFile'   => $newFile->getPath(),
                'replacements' => count($replacements),
            ]
        );

        return $newFile;
    }//end replaceWordsInOfficeContainer()

    /**
     * Replace words in a Word document.
     *
     * This method uses PHPWord to load a Word document, recursively process all elements
     * (including headers, footers, tables, lists), apply text replacements, and save
     * the result as a new file in the same parent folder.
     *
     * @param Node        $node                The file node to process.
     * @param array       $replacements        Array of replacement mappings (search => replace).
     * @param string      $outputName          Name for the output file.
     * @param string|null $sanitizedSourcePath Optional pre-sanitised source file path; when
     *                                         provided, the walker reads these cleaned bytes
     *                                         instead of the original node content.
     *
     * @return File The new file node with replaced content.
     *
     * @throws Exception If replacement fails.
     *
     * @phpstan-param  array<string, string> $replacements
     * @psalm-param    array<string, string> $replacements
     * @phpstan-return File
     * @psalm-return   File
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          IOFactory::load is standard PhpWord pattern
     * @SuppressWarnings(PHPMD.NPathComplexity)       Document processing requires many conditional transformations
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Document processing requires many conditional branches
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex document processing requires extensive code
     */
    private function replaceWordsInWordDocument(
        Node $node,
        array $replacements,
        string $outputName,
        ?string $sanitizedSourcePath=null
    ): File {
        $tempFile = tempnam(sys_get_temp_dir(), 'openregister_word_');
        if ($tempFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        if ($sanitizedSourcePath !== null) {
            // Walker reads the sanitised derivative, not the original bytes.
            if (copy($sanitizedSourcePath, $tempFile) === false) {
                unlink($tempFile);
                throw new Exception('Failed to copy sanitised source for processing');
            }
        }//end if

        if ($sanitizedSourcePath === null) {
            // Get the file content as a stream and save to a temp file.
            $stream     = $node->fopen('r');
            $tempStream = fopen($tempFile, 'w');
            if ($tempStream === false) {
                unlink($tempFile);
                throw new Exception('Failed to open temporary file for writing');
            }

            stream_copy_to_stream($stream, $tempStream);
            fclose($tempStream);
            fclose($stream);
        }//end if

        try {
            // Snapshot the process-static PhpWord style names BEFORE loading
            // this document. PhpWord\Style is a process-static collection that
            // the Word2007 reader APPENDS to on every IOFactory::load() (it is
            // never reset), so in a long-lived PHP-FPM worker it accumulates
            // styles from previously-processed documents. We diff against this
            // snapshot below so the Numbering workaround only touches THIS
            // document's styles and never mutates a prior document's — which
            // would otherwise be a cross-request state bleed.
            $preLoadStyleNames = array_keys(\PhpOffice\PhpWord\Style::getStyles());

            // Load the document.
            $phpWord = IOFactory::load($tempFile);

            // Helper: Replace text in all elements recursively.
            $replaceInElements = function (array $elements, array $replacements) use (&$replaceInElements): void {
                foreach ($elements as $element) {
                    // Replace in text runs.
                    if (method_exists($element, 'getText') === true && method_exists($element, 'setText') === true) {
                        $text = $element->getText();
                        foreach ($replacements as $original => $replacement) {
                            $text = str_ireplace($original, $replacement, $text);
                        }

                        $element->setText($text);
                    }

                    // Replace in tables.
                    if (method_exists($element, 'getRows') === true) {
                        foreach ($element->getRows() as $row) {
                            foreach ($row->getCells() as $cell) {
                                $replaceInElements($cell->getElements(), $replacements);
                            }
                        }
                    }

                    // Replace in lists.
                    if (method_exists($element, 'getItems') === true) {
                        foreach ($element->getItems() as $item) {
                            $replaceInElements($item->getElements(), $replacements);
                        }
                    }

                    // Replace in nested elements.
                    if (method_exists($element, 'getElements') === true) {
                        $replaceInElements($element->getElements(), $replacements);
                    }
                }//end foreach
            };

            // Replace in headers.
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getHeaders() as $header) {
                    $replaceInElements($header->getElements(), $replacements);
                }
            }

            // Replace in main content.
            foreach ($phpWord->getSections() as $section) {
                $replaceInElements($section->getElements(), $replacements);
            }

            // Replace in footers.
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getFooters() as $footer) {
                    $replaceInElements($footer->getElements(), $replacements);
                }
            }

            // PhpWord roundtrip-safety workaround for the Word2007 Numbering
            // bug. Chain of events upstream:
            // 1. Shared\XMLReader::getAttribute() at line 187 normalises
            // every empty-string attribute value to null:
            // return ($return == '') ? null : $return;
            // 2. Reader\Word2007\Numbering at line 53 stores that null
            // verbatim into $abstract['type'] (the readLevel() helper
            // filters nulls; the abstract-level reader does not).
            // 3. Style::addNumberingStyle dispatches the array through
            // AbstractStyle::setStyleByArray, which calls setType($value)
            // on each entry.
            // 4. Style\Numbering::setType has a strict `string` typehint
            // since PhpWord 1.x; null → TypeError.
            // 5. Writer\Word2007\Part\Numbering lines 68–70 unconditionally
            // emits <w:multiLevelType> via writeAttribute('w:val',
            // $style->getType()). When getType() is null PHP coerces
            // to "", so the writer poisons its own output:
            // <w:multiLevelType w:val=""/>. Re-loading that file then
            // triggers (1)–(4) and the read crashes.
            //
            // The fix here works at the OpenRegister boundary: before
            // calling the writer we walk THIS document's Numbering styles
            // (scoped via the pre-load snapshot so we never mutate a prior
            // document's styles still resident in the static collection) and
            // ensure each $type is one of the valid enum values
            // ('singleLevel'|'multilevel'|'hybridMultilevel'). We only coerce
            // the null/'' case (the TypeError trigger); a legitimately-typed
            // 'multilevel' style — e.g. from a LibreOffice-native list — is
            // left untouched so its rendering semantics are preserved. Word
            // emits hybridMultilevel for almost every list it produces, so
            // that's the safest default for the unspecified case — Word
            // readers (including PhpWord's own re-read) treat the result
            // identically to an unspecified type. Upstream fix tracked
            // separately: the writer should gate the <w:multiLevelType> emit
            // the same way it already gates other properties (lines 116–124
            // of Writer/Word2007/Part/Numbering.php).
            $thisDocStyleNames = array_diff(
                array_keys(\PhpOffice\PhpWord\Style::getStyles()),
                $preLoadStyleNames
            );
            foreach (\PhpOffice\PhpWord\Style::getStyles() as $styleName => $style) {
                if (in_array($styleName, $thisDocStyleNames, true) === false) {
                    // Belongs to a previously-processed document — leave it alone.
                    continue;
                }

                if ($style instanceof \PhpOffice\PhpWord\Style\Numbering === false) {
                    continue;
                }

                $currentType = $style->getType();
                if ($currentType === null || $currentType === '') {
                    $style->setType('hybridMultilevel');
                }
            }

            // Save the modified document to a new temp file.
            $outputTempFile = tempnam(sys_get_temp_dir(), 'openregister_word_output_');
            IOFactory::createWriter($phpWord, 'Word2007')->save($outputTempFile);

            // Get the parent folder and create the new file.
            $parentFolder = $node->getParent();
            if ($parentFolder->nodeExists($outputName) === true) {
                $parentFolder->get($outputName)->delete();
            }

            $outputStream = fopen($outputTempFile, 'r');
            $newFile      = $parentFolder->newFile(path: $outputName, content: $outputStream);
            // Do NOT call fclose($outputStream) here; Nextcloud handles the stream lifecycle internally.
            // Clean up temp files.
            unlink($tempFile);
            unlink($outputTempFile);

            $this->logger->debug(
                message: '[DocumentProcessingHandler] Words replaced in Word document',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'originalFile' => $node->getPath(),
                    'outputFile'   => $newFile->getPath(),
                    'replacements' => count($replacements),
                ]
            );

            return $newFile;
        } catch (Exception $e) {
            // Clean up temp file if it exists.
            if (isset($tempFile) === true && file_exists($tempFile) === true) {
                unlink($tempFile);
            }

            $this->logger->error(
                message: '[DocumentProcessingHandler] Failed to replace words in Word document: '.$e->getMessage(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e,
                ]
            );
            throw new Exception('Failed to replace words in Word document: '.$e->getMessage(), 0, $e);
        }//end try
    }//end replaceWordsInWordDocument()

    /**
     * Replace words in a text-based document.
     *
     * This method reads the content of a text file, applies string replacements,
     * and saves the result as a new file in the same parent folder. This works
     * for any text-based file format (.txt, .md, .html, etc.).
     *
     * @param File   $node         The file node to process.
     * @param array  $replacements Array of replacement mappings (search => replace).
     * @param string $outputName   Name for the output file.
     *
     * @return File The new file node with replaced content.
     *
     * @throws Exception If replacement fails.
     *
     * @phpstan-param  array<string, string> $replacements
     * @psalm-param    array<string, string> $replacements
     * @phpstan-return File
     * @psalm-return   File
     */
    private function replaceWordsInTextDocument(
        File $node,
        array $replacements,
        string $outputName
    ): File {
        // File::getContent() returns the content string and throws on failure
        // (NotPermitted/Locked) — it never returns false, so no false-check.
        $content = $node->getContent();

        // Apply replacements.
        $modifiedContent = $content;
        foreach ($replacements as $original => $replacement) {
            $modifiedContent = str_ireplace((string) $original, $replacement, $modifiedContent);
        }

        // Create output file.
        $parentFolder = $node->getParent();
        if ($parentFolder->nodeExists($outputName) === true) {
            $parentFolder->get($outputName)->delete();
        }

        $newFile = $parentFolder->newFile(path: $outputName, content: $modifiedContent);

        $this->logger->debug(
            message: '[DocumentProcessingHandler] Words replaced in text document',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'originalFile' => $node->getPath(),
                'outputFile'   => $newFile->getPath(),
                'replacements' => count($replacements),
            ]
        );

        return $newFile;
    }//end replaceWordsInTextDocument()

    /**
     * Replace words in a PDF document via the SAPP byte-level pipeline.
     *
     * Routes to {@see PdfTextReplacer} (text replacement with font switch
     * + Helvetica fallback) and {@see PdfMetadataSanitizer} (/Info and
     * XMP stripping). The output is re-extracted post-replacement; residual
     * substitution-map keys are always logged as a PII-free warning, and the
     * `$strict` flag then decides the outcome: lenient (default, ad-hoc
     * replace) writes the partial PDF for docx parity, strict (entity
     * anonymisation) fails closed with `REASON_VALIDATION_FAILED`.
     *
     * Pre-dispatch: encrypted PDFs are rejected with `REASON_ENCRYPTED_PDF`
     * (caller-correctable, HTTP 422); if smalot/pdfparser cannot extract any
     * text from the input, the call defers to the `ocr-document-scanning`
     * capability via `REASON_TEXT_LAYER_MISSING` (caller's responsibility to route).
     *
     * @param File   $node         The PDF file node to process.
     * @param array  $replacements Map: entity-text => placeholder.
     * @param string $outputName   Output file name.
     * @param bool   $strict       When true (entity anonymisation), residual
     *                             entity text fails closed instead of being
     *                             written as a partial result.
     *
     * @throws Exception                  If node content is unreadable.
     * @throws PdfAnonymisationException  On encrypted PDF, missing text
     *                                    layer, validation gate failure (strict),
     *                                    or internal pipeline errors.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return File The anonymised PDF.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   $strict selects fail-closed vs lenient validation.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear probe → replace → sanitise → write pipeline; splitting obscures the flow.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Each guard maps a distinct SAPP failure mode to a typed reason.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Same — sequential fail-closed guards, not nested branching.
     *
     * @spec openspec/specs/pdf-anonymisation/spec.md
     */
    private function replaceWordsInPdfDocument(
        File $node,
        array $replacements,
        string $outputName,
        bool $strict=false
    ): File {
        // File::getContent() returns the content string and throws on failure
        // (NotPermitted/Locked) — it never returns false.
        $content = $node->getContent();

        // Encryption probe — encrypted PDFs carry an `/Encrypt N G R` indirect
        // reference in the trailer dictionary. SAPP cannot byte-replace, and
        // smalot cannot extract, an encrypted PDF without the password, which
        // is out of scope for v1. Detect it up front and raise the caller-
        // correctable REASON_ENCRYPTED_PDF (→ HTTP 422) rather than letting it
        // surface downstream as a generic 500. The `\s+\d+\s+\d+\s+R` form
        // matches the trailer's indirect reference and avoids false positives
        // from the literal string appearing inside a content stream.
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $content) === 1) {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_ENCRYPTED_PDF,
                message: 'PDF is encrypted; decryption is out of scope (v1)',
                diagnostic: ['stage' => 'input.encryption_probe']
            );
        }

        // Pre-dispatch text-layer probe — image-only scans must defer
        // to the `ocr-document-scanning` capability rather than producing
        // an empty no-op output via the byte-replace path.
        try {
            $parser    = new PdfParser();
            $parsedPdf = $parser->parseContent($content);
            $extracted = $parsedPdf->getText();
        } catch (\Throwable $e) {
            // The smalot parser rejects encrypted/secured PDFs with a message
            // rather than a typed exception; map that to the caller-correctable 422.
            // Everything else is a genuine input-parse failure → 500.
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

        if (trim($extracted) === '') {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_TEXT_LAYER_MISSING,
                message: 'PDF has no extractable text layer; defer to OCR',
                diagnostic: ['stage' => 'input.text_layer_probe']
            );
        }

        $replacer  = new PdfTextReplacer(logger: $this->logger);
        $sanitizer = new PdfMetadataSanitizer(logger: $this->logger);

        // Run text replacement first; metadata sanitisation operates on
        // the result so /Info / XMP changes survive the rebuild.
        // Best-effort: replaceInPdf no longer fails closed on residual entity
        // text — it returns the residual needles so we can produce the file
        // and warn instead of discarding it.
        $residualNeedles = [];
        $replacedBytes   = $replacer->replaceInPdf(
            pdfBytes: $content,
            substitutions: $replacements,
            strict: $strict,
            residualEntities: $residualNeedles
        );

        // Map residual needles back to entity records {text, type, id} via the
        // placeholder map (`[<TYPE>: <id>]`). Logs stay PII-free (ADR-005); the
        // text is carried only to the authenticated anonymise response for the
        // operator's review/iterate UI.
        if (empty($residualNeedles) === false) {
            $records = [];
            foreach ($residualNeedles as $needle) {
                $placeholder = $replacements[$needle] ?? '';
                $type        = 'UNKNOWN';
                $id          = '';
                if (preg_match('/^\[([^:\]]+):\s*([^\]]*)\]$/', $placeholder, $m) === 1) {
                    $type = trim($m[1]);
                    $id   = trim($m[2]);
                }

                $records[] = ['text' => (string) $needle, 'type' => $type, 'id' => $id];
            }

            $this->lastResidualEntities = $records;
        }

        try {
            // Fail CLOSED: if SAPP cannot reparse the replaced bytes (returns
            // false rather than throwing) we must NOT fall through and write
            // the un-sanitised `$replacedBytes` — that still carries the
            // original `/Info` dict + XMP stream (document PII), defeating the
            // sanitiser. Treat a false/empty result as a hard pipeline error.
            $doc = \ddn\sapp\PDFDoc::from_string(buffer: $replacedBytes);
            if ($doc === false) {
                throw new PdfAnonymisationException(
                    reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                    message: 'SAPP failed to reparse replaced PDF for metadata sanitisation',
                    diagnostic: ['stage' => 'sanitize.reparse']
                );
            }

            $sanitizer->sanitize(doc: $doc);
            $serialised = $doc->to_pdf_file_s(rebuild: true);
            if ($serialised === false || $serialised === '') {
                throw new PdfAnonymisationException(
                    reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                    message: 'SAPP serialise after metadata sanitisation returned empty',
                    diagnostic: ['stage' => 'sanitize.serialise']
                );
            }

            $replacedBytes = $serialised;
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

        // Create output file.
        $parentFolder = $node->getParent();
        if ($parentFolder->nodeExists($outputName) === true) {
            $parentFolder->get($outputName)->delete();
        }

        $newFile = $parentFolder->newFile(path: $outputName, content: $replacedBytes);

        $this->logger->info(
            message: '[DocumentProcessingHandler] PDF anonymised via SAPP',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'originalFile' => $node->getPath(),
                'outputFile'   => $newFile->getPath(),
                'replacements' => count($replacements),
            ]
        );

        return $newFile;
    }//end replaceWordsInPdfDocument()
}//end class
