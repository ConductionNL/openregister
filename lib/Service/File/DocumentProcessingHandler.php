<?php

/**
 * DocumentProcessingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
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
 */
class DocumentProcessingHandler
{

    /**
     * The enumerated entity-type labels that get localised in the placeholder.
     *
     * Canonical source = the `EntityRecognitionHandler::ENTITY_TYPE_*`
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
     * Residual entities from the most recent anonymisation (best-effort policy).
     *
     * Each record: {text: string, type: string, id: string} for an entity whose
     * text could not be fully removed from the output (e.g. the recognition
     * backend over-captured across table cells, so the value is not contiguous
     * in the PDF). Empty when the last run was complete. Consumed by the
     * controller to surface a warning so the operator can iterate (manual/skip).
     *
     * @var array<int, array{text: string, type: string, id: string}>
     */
    private array $lastResidualEntities = [];

    /**
     * Constructor for DocumentProcessingHandler.
     *
     * @param IRootFolder                               $rootFolder           Root folder for file access.
     * @param IUserSession                              $userSession          User session for getting current user.
     * @param LoggerInterface                           $logger               Logger for logging operations.
     * @param \OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper Used to honour skip-anonymization
     *                                                                        flags during the redaction pass
     *                                                                        (see `entity-relation-grondslagen`).
     * @param IL10N|null                                $l10n                 Acting-user localisation, used to
     *                                                                        translate the placeholder TYPE label
     *                                                                        (PERSON → PERSOON on a Dutch instance).
     *                                                                        Nullable: when absent the raw English
     *                                                                        label is emitted (construct-safe).
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly \OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
        private readonly ?IL10N $l10n=null
    ) {
    }//end __construct()

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
     * Residual entities from the most recent anonymisation run.
     *
     * Best-effort policy: when some entity text could not be removed from the
     * output (e.g. recognition over-capture across table cells), the file is
     * still produced and these records describe what remains so the caller can
     * warn the operator. Empty when the last run fully redacted everything.
     *
     * @return array<int, array{text: string, type: string, id: string}> Residual records.
     */
    public function getLastResidualEntities(): array
    {
        return $this->lastResidualEntities;
    }//end getLastResidualEntities()

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
     */
    public function replaceWords(Node $node, array $replacements, ?string $outputName=null): File
    {
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
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
            return $this->replaceWordsInPdfDocument(node: $node, replacements: $replacements, outputName: $outputName);
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
     *
     * @throws Exception If anonymization fails.
     *
     * @phpstan-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
     *
     * @psalm-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
     *
     * @return File The anonymized document file.
     */
    public function anonymizeDocument(
        Node $node,
        array $entities,
        string $scope='document',
        ?string $dossierKey=null
    ): File {
        // Reset residuals from a prior call on this (potentially reused) handler.
        $this->lastResidualEntities = [];

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

        // Scope-local placeholder numbering (anonymisation-placeholder-id-scope):
        // translate the internal global `e.id` to a number local to this scope so
        // the emitted `[<TYPE>: <number>]` never links a person across
        // documents/publications. Per-document (default) numbers lazily by first
        // appearance; per-dossier seeds the translator with a map deterministically
        // recomputed from the whole dossier folder's stored rows.
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
            // "06-12345678 "), which the document content stream never
            // contains verbatim — leaving the value unredacted while the
            // (whitespace-normalising) validation gate still flags it as
            // residual. Trim for the map KEY; keep $originalText for the
            // skip-list and stable-id lookups (those are keyed by the stored value).
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
            // (PERSON → PERSOON on a Dutch instance); unknown types fall
            // back to the raw label.
            $localizedType = $this->localizeEntityType(entityType: $entityType);

            if (isset($entityIdMap[$originalText]) === true) {
                // Translate the internal `e.id` to the scope-local number.
                $localNumber           = $translator->translate(entityId: $entityIdMap[$originalText]['id']);
                $replacements[$needle] = '['.$localizedType.': '.$localNumber.']';
            } else {
                $key = $entity['key'] ?? substr(\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 0, 8);
                $replacements[$needle] = '['.$localizedType.': '.$key.']';
            }
        }//end foreach

        // Generate anonymized file name.
        $fileName      = $node->getName();
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileBaseName  = pathinfo($fileName, PATHINFO_FILENAME);

        $anonymizedFileName = $fileBaseName.'_anonymized';
        if (empty($fileExtension) === false) {
            $anonymizedFileName .= '.'.$fileExtension;
        }

        $anonymizedFile = $this->replaceWords(node: $node, replacements: $replacements, outputName: $anonymizedFileName);

        // Flip the source's EntityRelation rows to `anonymized = 1` so the
        // anonymised state is queryable downstream. `markAsAnonymized`
        // skips rows where `skip_anonymization = 1` per the
        // `entity-relation-grondslagen` contract — operator skips are
        // preserved. The placeholder value is a generic "[REDACTED]"
        // because each entity got its own per-row replacement key earlier
        // in this method; the column stores a single representative value
        // per anonymise call, not the full per-entity placeholder list.
        if ($fileId > 0 && empty($replacements) === false) {
            try {
                $this->entityRelationMapper->markAsAnonymized($fileId, '[REDACTED]');
            } catch (\Throwable $e) {
                // Persistence-side failure on the audit flag MUST NOT mask
                // the successful redaction; the file is already written.
                // Surface via warning log; downstream summary reports
                // simply won't see this file until the next anonymise
                // call retries the mark.
                $this->logger->warning(
                    'DocumentProcessingHandler: markAsAnonymized failed after redaction',
                    ['fileId' => $fileId, 'error' => $e->getMessage()]
                );
            }
        }

        return $anonymizedFile;
    }//end anonymizeDocument()

    /**
     * Localise an entity-type label to the acting user's language for the
     * placeholder. Only the enumerated entity-type set
     * (`LOCALIZABLE_ENTITY_TYPES`, sourced from the
     * `EntityRecognitionHandler::ENTITY_TYPE_*` constants) is translated; an
     * unknown / free-form type is returned unchanged (no translation, no
     * error). When no `IL10N` is injected the raw label is returned (the
     * `en` / untranslated behaviour).
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
     * entity-relation rows (no table, no migration).
     *
     * Resolves the dossier folder from $dossierKey (a stable folder id) or,
     * when absent, the file's parent folder. Enumerates the folder's
     * descendant files (recursively), loads their entity rows in one query
     * (`findEntityIdsByValueForFiles`), and ranks distinct `entity_id`s by
     * first appearance under the total order `(file_id, position_start,
     * entity_id)`. The result is a pure function of the stored rows, so every
     * per-file call within the dossier derives the same map. Any failure to
     * resolve/enumerate the folder degrades to a per-document translator
     * (fail-safe — never throws out of numbering). Nothing here logs the
     * entity value alongside its number.
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
            'DocumentProcessingHandler: recomputed per-dossier placeholder numbering',
            [
                'dossierKey' => $dossierKey,
                'folderId'   => $folder->getId(),
                'fileCount'  => count($fileIds),
                'rowCount'   => count($rows),
            ]
        );

        return PlaceholderIdTranslator::forDossier(rows: $rows);
    }//end recomputeDossierTranslator()

    /**
     * Resolve the dossier folder for per-dossier numbering: prefer the
     * explicit $dossierKey (a stable folder id), else fall back to the file's
     * parent folder. Returns null when neither resolves to a usable folder
     * (caller then degrades to per-document).
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
            } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            unset($e);
        }

        return null;
    }//end resolveDossierFolder()

    /**
     * Enumerate the descendant file ids of a dossier folder (recursive), via
     * the Nextcloud Node API. Sub-folders are walked; only file nodes
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
        } catch (\Throwable $e) {
            // Best-effort enumeration; partial/empty list degrades numbering
            // gracefully rather than failing the anonymise run.
            unset($e);
        }

        return array_values(array_unique($fileIds));
    }//end collectDescendantFileIds()

    /**
     * Replace words in a Word document.
     *
     * This method uses PHPWord to load a Word document, recursively process all elements
     * (including headers, footers, tables, lists), apply text replacements, and save
     * the result as a new file in the same parent folder.
     *
     * @param Node   $node         The file node to process.
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
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          IOFactory::load is standard PhpWord pattern
     * @SuppressWarnings(PHPMD.NPathComplexity)       Document processing requires many conditional transformations
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Document processing requires many conditional branches
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex document processing requires extensive code
     */
    private function replaceWordsInWordDocument(
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        // Get the file content as a stream and save to a temp file (@var File $fileNode).
        $fileNode = $node;
        $stream   = $fileNode->fopen('r');
        $tempFile = tempnam(sys_get_temp_dir(), 'openregister_word_');
        if ($tempFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        $tempStream = fopen($tempFile, 'w');
        if ($tempStream === false) {
            unlink($tempFile);
            throw new Exception('Failed to open temporary file for writing');
        }

        stream_copy_to_stream($stream, $tempStream);
        fclose($tempStream);
        fclose($stream);

        try {
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
            //   1. Shared\XMLReader::getAttribute() at line 187 normalises
            //      every empty-string attribute value to null:
            //          return ($return == '') ? null : $return;
            //   2. Reader\Word2007\Numbering at line 53 stores that null
            //      verbatim into $abstract['type'] (the readLevel() helper
            //      filters nulls; the abstract-level reader does not).
            //   3. Style::addNumberingStyle dispatches the array through
            //      AbstractStyle::setStyleByArray, which calls setType($value)
            //      on each entry.
            //   4. Style\Numbering::setType has a strict `string` typehint
            //      since PhpWord 1.x; null → TypeError.
            //   5. Writer\Word2007\Part\Numbering lines 68–70 unconditionally
            //      emits <w:multiLevelType> via writeAttribute('w:val',
            //      $style->getType()). When getType() is null PHP coerces
            //      to "", so the writer poisons its own output:
            //      <w:multiLevelType w:val=""/>. Re-loading that file then
            //      triggers (1)–(4) and the read crashes.
            //
            // The fix here works at the OpenRegister boundary: before
            // calling the writer we walk every Numbering style PhpWord
            // currently knows about and ensure its $type is one of the
            // valid enum values ('singleLevel'|'multilevel'|'hybridMultilevel').
            // Word emits hybridMultilevel for almost every list it
            // produces, so that's the safest default — Word readers
            // (including PhpWord's own re-read) will treat the resulting
            // numbering identically to what they would have for an
            // unspecified type. Upstream fix tracked separately:
            // the writer should gate the <w:multiLevelType> emit the
            // same way it already gates other properties (lines
            // 116–124 of Writer/Word2007/Part/Numbering.php).
            foreach (\PhpOffice\PhpWord\Style::getStyles() as $style) {
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
     * @param Node   $node         The file node to process.
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
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        // Get file content (@var File $fileNode).
        $fileNode = $node;
        $content  = $fileNode->getContent();
        if ($content === false) {
            throw new Exception('Failed to get content from file: '.$node->getPath());
        }

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
     * XMP stripping). The output is re-extracted post-replacement and a
     * PII-free warning is logged if any substitution-map key remains —
     * the partial PDF is still written, matching the docx path's
     * behaviour. (REASON_VALIDATION_FAILED is no longer raised by the
     * pipeline; the constant is retained for backwards compatibility.)
     *
     * Pre-dispatch: if smalot/pdfparser cannot extract any text from the
     * input, the call defers to the `ocr-document-scanning` capability
     * via `REASON_TEXT_LAYER_MISSING` (caller's responsibility to route).
     *
     * @param Node   $node         The PDF file node to process.
     * @param array  $replacements Map: entity-text => placeholder.
     * @param string $outputName   Output file name.
     *
     * @throws Exception                  If node content is unreadable.
     * @throws PdfAnonymisationException  On encrypted PDF, missing text
     *                                    layer, validation gate failure,
     *                                    or internal pipeline errors.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return File The anonymised PDF.
     *
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
     */
    private function replaceWordsInPdfDocument(
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        $content = $node->getContent();
        if ($content === false) {
            throw new Exception('Failed to get content from file: '.$node->getPath());
        }

        // Pre-dispatch text-layer probe — image-only scans must defer
        // to the `ocr-document-scanning` capability rather than producing
        // an empty no-op output via the byte-replace path.
        try {
            $parser    = new \Smalot\PdfParser\Parser();
            $parsedPdf = $parser->parseContent($content);
            $extracted = $parsedPdf->getText();
        } catch (\Throwable $e) {
            // Parsing failure on input itself — surface as internal error.
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'smalot/pdfparser failed on input PDF',
                diagnostic: ['stage' => 'input.text_layer_probe'],
                previous: $e
            );
        }

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
        // Best-effort: replaceInPdf does not fail closed on residual entity
        // text — it returns the residual needles so we can produce the file
        // and warn instead of discarding it.
        $residualNeedles = [];
        $replacedBytes   = $replacer->replaceInPdf(
            pdfBytes: $content,
            substitutions: $replacements,
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
            $doc = \ddn\sapp\PDFDoc::from_string(buffer: $replacedBytes);
            if ($doc !== false) {
                $sanitizer->sanitize(doc: $doc);
                $serialised = $doc->to_pdf_file_s(rebuild: true);
                if ($serialised !== false && $serialised !== '') {
                    $replacedBytes = $serialised;
                }
            }
        } catch (PdfAnonymisationException $e) {
            // Sanitiser-raised — surface to the caller.
            throw $e;
        } catch (\Throwable $e) {
            throw new PdfAnonymisationException(
                reason: PdfAnonymisationException::REASON_INTERNAL_ERROR,
                message: 'Metadata sanitisation stage failed',
                diagnostic: ['stage' => 'sanitize'],
                previous: $e
            );
        }

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
