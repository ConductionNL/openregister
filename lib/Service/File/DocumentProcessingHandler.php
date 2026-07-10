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
use OCA\OpenRegister\Service\TextExtraction\EmlAttachment;
use OCA\OpenRegister\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Service\TextExtraction\EmlStructure;
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
     * ODF `text:` XML namespace URI, used to locate paragraph containers
     * (`text:p`, `text:h`) and text nodes when redacting ODT parts in place.
     */
    private const ODF_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /**
     * Per-entity placeholder map from the most recent anonymisation.
     *
     * Maps the internal global entity id (`openregister_entities.id`, stringified)
     * to the EXACT placeholder string emitted into the document for that entity
     * — e.g. `"7" => "[PERSOON: 1]"`. Lets downstream consumers (DocuDesk's
     * grondslagen-summary) render the SAME placeholder the document carries,
     * including the scope-local number and localized TYPE label, instead of
     * re-deriving `[<TYPE>: <entity_id>]` from the global id. Empty when the
     * last run matched no catalogue entity.
     *
     * @var array<string, string>
     */
    private array $lastPlaceholderMap = [];

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
     * @param EmlParser                                 $emlParser            Structured EML parser, used by the
     *                                                                        message/rfc822 anonymise path to
     *                                                                        redact decoded body parts, headers
     *                                                                        and attachments (anonymise-eml-structured).
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly \OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
        private readonly ?IL10N $l10n=null,
        private readonly ?EmlParser $emlParser=null
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
     * Replace words in a document.
     *
     * This method replaces specified words/phrases in a document file. It supports
     * Word documents (.doc, .docx) using PHPWord, OpenDocument Text (.odt) via in-place XML
     * replacement, and text files using simple string replacement.
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

        // ODT (OpenDocument Text) is a ZIP container whose text lives in
        // content.xml (body/tables) and styles.xml (headers/footers). It is
        // redacted by rewriting those XML parts in place (Strategy B). It MUST
        // NOT reach the raw-byte str_ireplace fallback (which matches nothing
        // in the compressed case → silent PII leak, or breaks the ZIP in the
        // stored case → corrupt file), and it MUST NOT use PhpWord's ODText
        // reader (which silently drops tables, headers, and footers on load,
        // destroying that content). See the `odt-anonymisation-writeback` change.
        if ($fileExtension === 'odt') {
            return $this->replaceWordsInOdtDocument(node: $node, replacements: $replacements, outputName: $outputName);
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
        // EML must NEVER fall through to the raw-byte text fallback (which
        // leaks base64/quoted-printable body parts and attachments). It has
        // its own decoded-redaction path that returns an AnonymisedEmlStructure
        // (not a File), so callers MUST use anonymizeEmlStructured() instead
        // (the anonymise-eml-structured change).
        if ($this->isEmlNode(node: $node) === true) {
            throw new Exception(
                'EML inputs must be anonymised via anonymizeEmlStructured(); anonymizeDocument() does not support message/rfc822.'
            );
        }

        // Reset residuals + placeholder map from a prior call on this
        // (potentially reused) handler.
        $this->lastResidualEntities = [];
        $this->lastPlaceholderMap   = [];

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

        // Build the entity → placeholder replacement map (stable scope-local
        // numbering, skip-aware, localised TYPE label). Shared with the
        // message/rfc822 anonymise path so a person gets the SAME placeholder
        // in a document and in an EML body/attachment.
        $replacements = $this->buildEntityReplacements(
            node: $node,
            fileId: $fileId,
            entities: $entities,
            skippedValues: $skippedValues,
            scope: $scope,
            dossierKey: $dossierKey
        );

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

        return $anonymizedFile;
    }//end anonymizeDocument()

    /**
     * Build the entity → placeholder replacement map for a file.
     *
     * Extracted from {@see anonymizeDocument()} so the message/rfc822
     * anonymise path reuses the EXACT same logic — stable scope-local
     * numbering (`PlaceholderIdTranslator`), skip-awareness, and localised
     * TYPE labels — guaranteeing one entity yields the same placeholder in a
     * document and across an EML's body, headers and attachments. Populates
     * `$this->lastPlaceholderMap` as a side effect (callers reset it first).
     *
     * @param Node                                                                $node          The source file node.
     * @param int                                                                 $fileId        Resolved source file id (0 when unknown).
     * @param array<int, array{text?: string, entityType?: string, key?: string}> $entities      Detected entities.
     * @param array<int, string>                                                  $skippedValues Operator-skipped entity values.
     * @param string                                                              $scope         'document' or 'dossier'.
     * @param string|null                                                         $dossierKey    Stable dossier folder id, or null.
     *
     * @return array<string, string> Map of entity-text needle => `[<TYPE>: <number>]` placeholder.
     */
    private function buildEntityReplacements(
        Node $node,
        int $fileId,
        array $entities,
        array $skippedValues,
        string $scope,
        ?string $dossierKey
    ): array {
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
                // Record the EXACT emitted placeholder against the global e.id so
                // the grondslagen-summary can render the same string (scope-local
                // number + localized label) instead of re-deriving from e.id.
                $this->lastPlaceholderMap[(string) $entityIdMap[$originalText]['id']] = $replacements[$needle];
            } else {
                $key = $entity['key'] ?? substr(\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 0, 8);
                $replacements[$needle] = '['.$localizedType.': '.$key.']';
            }
        }//end foreach

        return $replacements;
    }//end buildEntityReplacements()

    /**
     * Whether a node is an EML (`message/rfc822`) message.
     *
     * Checks the MIME type when resolvable, falling back to the `.eml`
     * filename extension. Used to keep EML off the raw-byte text fallback.
     *
     * @param Node $node The node to test.
     *
     * @return bool True when the node is an EML message.
     */
    private function isEmlNode(Node $node): bool
    {
        if (method_exists($node, 'getMimeType') === true
            && (string) $node->getMimeType() === 'message/rfc822'
        ) {
            return true;
        }

        return strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) === 'eml';
    }//end isEmlNode()

    /**
     * Anonymise an EML (`message/rfc822`) message into a redacted structure.
     *
     * Parses the message via {@see EmlParser}, then redacts on DECODED content
     * (so base64 / quoted-printable parts are never leaked): the body parts,
     * the display headers, and each attachment (materialised and run through
     * the matching per-format redactor). Nested EML attachments recurse on the
     * already-parsed structure within the parser's depth cap. Unsupported
     * attachment formats are flagged and their content dropped. OpenRegister
     * produces NO PDF — the DocuDesk consumer assembles the result.
     *
     * @param Node                                                                $node       The EML file node.
     * @param array<int, array{text?: string, entityType?: string, key?: string}> $entities   Detected entities.
     * @param string                                                              $scope      'document' (default) or 'dossier'.
     * @param string|null                                                         $dossierKey Stable dossier folder id, or null.
     *
     * @throws Exception When EmlParser is unavailable or parsing fails irrecoverably.
     *
     * @return AnonymisedEmlStructure The redacted structure (contract.md shape).
     *
     * @spec openspec/changes/anonymise-eml-structured/specs/eml-anonymisation/spec.md
     *       "Anonymising a message/rfc822 file MUST redact on decoded content"
     */
    public function anonymizeEmlStructured(
        Node $node,
        array $entities,
        string $scope='document',
        ?string $dossierKey=null
    ): AnonymisedEmlStructure {
        if ($this->emlParser === null) {
            throw new Exception('EML anonymisation requires EmlParser; none injected.');
        }

        // Reset per-run state (mirrors anonymizeDocument).
        $this->lastResidualEntities = [];
        $this->lastPlaceholderMap   = [];

        $fileId = 0;
        if (method_exists($node, 'getId') === true) {
            $candidate = $node->getId();
            if (is_int($candidate) === true && $candidate > 0) {
                $fileId = $candidate;
            }
        }

        $skippedValues = [];
        if ($fileId > 0) {
            $skippedValues = $this->entityRelationMapper->findSkippedEntityValuesForFile($fileId);
        }

        // ONE replacement map for the whole message — body, headers and every
        // attachment share it, so a person yields the same placeholder
        // everywhere (the consumer's grondslagen cross-reference holds).
        $replacements = $this->buildEntityReplacements(
            node: $node,
            fileId: $fileId,
            entities: $entities,
            skippedValues: $skippedValues,
            scope: $scope,
            dossierKey: $dossierKey
        );

        if (($node instanceof File) === false) {
            throw new Exception('EML anonymisation requires a file node.');
        }

        $structure = $this->emlParser->parse(file: $node);

        // Working folder for materialising attachment bytes (the per-format
        // redactors are Node-based). Created beside the source EML and removed
        // in finally — never left behind.
        $workFolder = $this->createEmlWorkFolder(node: $node, fileId: $fileId);
        try {
            $anonymised = $this->redactEmlStructure(
                structure: $structure,
                replacements: $replacements,
                workFolder: $workFolder,
                depth: 0
            );
        } finally {
            $this->deleteEmlWorkFolder(folder: $workFolder);
        }

        // Flip the source's EntityRelation rows to `anonymized = 1` and persist
        // each relation's scope-local placeholder — mirrors anonymizeDocument()
        // so DocuDesk's grondslagen-summary can query this EML's redacted
        // entities the same way it does for every other format. Without this the
        // rows stay `anonymized = 0`, the summary query returns nothing, and the
        // report is empty even though the message was redacted.
        if ($fileId > 0 && empty($replacements) === false) {
            try {
                $this->entityRelationMapper->markAsAnonymizedWithPlaceholders($fileId, $this->lastPlaceholderMap);
            } catch (\Throwable $e) {
                // Persistence-side failure on the audit flag MUST NOT mask the
                // successful redaction; the assembled PDF is already the
                // authoritative output. Surface via warning; the next
                // anonymise call retries the mark.
                $this->logger->warning(
                    'DocumentProcessingHandler: markAsAnonymizedWithPlaceholders failed after EML redaction',
                    ['fileId' => $fileId, 'error' => $e->getMessage()]
                );
            }
        }

        return $anonymised;
    }//end anonymizeEmlStructured()

    /**
     * Redact a parsed EmlStructure (recursively for nested EML) into an
     * AnonymisedEmlStructure. Pure of side effects beyond temp-file churn in
     * $workFolder.
     *
     * @param EmlStructure          $structure    The parsed structure.
     * @param array<string, string> $replacements Shared entity → placeholder map.
     * @param Folder                $workFolder   Scratch folder for attachment materialisation.
     * @param int                   $depth        Current nesting depth (root = 0).
     *
     * @return AnonymisedEmlStructure
     */
    private function redactEmlStructure(
        EmlStructure $structure,
        array $replacements,
        Folder $workFolder,
        int $depth
    ): AnonymisedEmlStructure {
        $body = new AnonymisedEmlBody(
            plain: $this->redactText(text: $structure->body->plainText, replacements: $replacements),
            html: $this->redactText(text: $structure->body->html, replacements: $replacements)
        );

        $headers = $this->redactHeaders(headers: $structure->headers, replacements: $replacements);

        $attachments  = [];
        $inlineImages = [];
        foreach ($structure->attachments as $index => $attachment) {
            $redacted      = $this->redactAttachment(
                attachment: $attachment,
                replacements: $replacements,
                workFolder: $workFolder,
                depth: $depth,
                index: (int) $index
            );
            $attachments[] = $redacted;

            // Inline images referenced by the HTML body via cid: — only carry
            // bytes the consumer can use, and only when actually redacted.
            if ($attachment->isInline === true
                && $attachment->contentId !== null
                && $redacted->redactedContent !== null
            ) {
                $inlineImages[$attachment->contentId] = $redacted->redactedContent;
            }
        }

        return new AnonymisedEmlStructure(
            headers: $headers,
            body: $body,
            attachments: $attachments,
            inlineImages: $inlineImages
        );
    }//end redactEmlStructure()

    /**
     * Apply the replacement map to a decoded text string (null-safe).
     * Mirrors the text redactor's case-insensitive pass.
     *
     * @param string|null           $text         The decoded text, or null.
     * @param array<string, string> $replacements Entity → placeholder map.
     *
     * @return string|null The redacted text, or null when input was null.
     */
    private function redactText(?string $text, array $replacements): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach ($replacements as $needle => $placeholder) {
            $text = str_ireplace((string) $needle, $placeholder, $text);
        }

        return $text;
    }//end redactText()

    /**
     * Redact the display-header subset (from / to[] / cc[] / replyTo / subject)
     * and normalise the date. Header values can carry PII (names, addresses).
     *
     * @param array<string, mixed>  $headers      The parsed headers.
     * @param array<string, string> $replacements Entity → placeholder map.
     *
     * @return array<string, mixed> Redacted display headers.
     */
    private function redactHeaders(array $headers, array $replacements): array
    {
        $redactList = function (array $values) use ($replacements): array {
            $out = [];
            foreach ($values as $value) {
                $out[] = (string) $this->redactText(text: (string) $value, replacements: $replacements);
            }

            return $out;
        };

        $date     = ($headers['date'] ?? null);
        $dateText = '';
        if ($date instanceof \DateTimeInterface) {
            $dateText = $date->format('c');
        } else if (is_string($date) === true) {
            $dateText = $date;
        }

        return [
            'from'    => (string) $this->redactText(text: (string) ($headers['from'] ?? ''), replacements: $replacements),
            'to'      => $redactList(($headers['to'] ?? [])),
            'cc'      => $redactList(($headers['cc'] ?? [])),
            'replyTo' => (string) $this->redactText(text: (string) ($headers['replyTo'] ?? ''), replacements: $replacements),
            'subject' => (string) $this->redactText(text: (string) ($headers['subject'] ?? ''), replacements: $replacements),
            'date'    => $dateText,
        ];
    }//end redactHeaders()

    /**
     * Redact one EML attachment. Nested EML recurses on the parser-provided
     * structure (depth cap owned by the parser); supported document formats
     * are materialised and run through the matching redactor; everything else
     * is flagged unsupported with no content. A redactor failure is fail-safe:
     * the attachment is flagged unsupported, NEVER emitted unredacted.
     *
     * @param EmlAttachment         $attachment   The source attachment.
     * @param array<string, string> $replacements Shared entity → placeholder map.
     * @param Folder                $workFolder   Scratch folder.
     * @param int                   $depth        Current depth.
     * @param int                   $index        Attachment index (for unique temp names + PII-free logs).
     *
     * @return AnonymisedEmlAttachment
     */
    private function redactAttachment(
        EmlAttachment $attachment,
        array $replacements,
        Folder $workFolder,
        int $depth,
        int $index
    ): AnonymisedEmlAttachment {
        $filename = $attachment->filename;
        $mimeType = $attachment->mimeType;
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Nested EML → recurse on the already-parsed nested structure. The
        // parser only populates nestedEml within its depth cap; beyond it,
        // nestedEml is null and we flag the attachment unsupported.
        if ($mimeType === 'message/rfc822' || $ext === 'eml') {
            if ($attachment->nestedEml !== null) {
                $nested = $this->redactEmlStructure(
                    structure: $attachment->nestedEml,
                    replacements: $replacements,
                    workFolder: $workFolder,
                    depth: ($depth + 1)
                );

                return new AnonymisedEmlAttachment($filename, $mimeType, null, false, $nested);
            }

            return new AnonymisedEmlAttachment($filename, $mimeType, null, true, null);
        }

        $kind = $this->resolveAttachmentRedactor(mimeType: $mimeType, extension: $ext);
        if ($kind === null) {
            // No anonymiser for this MIME (xlsx, ods, images, archives, …):
            // flag + drop content (the consumer renders a placeholder page).
            return new AnonymisedEmlAttachment($filename, $mimeType, null, true, null);
        }

        try {
            $bytes = $this->materialiseAndRedactAttachment(
                attachment: $attachment,
                replacements: $replacements,
                workFolder: $workFolder,
                kind: $kind,
                depth: $depth,
                index: $index
            );

            return new AnonymisedEmlAttachment($filename, $mimeType, $bytes, false, null);
        } catch (\Throwable $e) {
            // Fail-safe: a redactor failure MUST NOT emit unredacted bytes.
            // PII-free log (index/MIME/redactor/exception class only).
            $this->logger->warning(
                'DocumentProcessingHandler: EML attachment redaction failed; flagged unsupported',
                [
                    'attachmentIndex' => $index,
                    'mimeType'        => $mimeType,
                    'redactor'        => $kind,
                    'depth'           => $depth,
                    'exception'       => get_class($e),
                ]
            );

            return new AnonymisedEmlAttachment($filename, $mimeType, null, true, null);
        }//end try
    }//end redactAttachment()

    /**
     * Map an attachment MIME / extension to a redactor kind, or null when
     * unsupported. `word` covers the PhpWord readers (doc/docx/odt/rtf);
     * `pdf` the byte-level PDF pipeline; `text` the raw text replacer.
     *
     * @param string $mimeType  The attachment MIME type.
     * @param string $extension The lowercased extension (no dot).
     *
     * @return string|null 'word' | 'pdf' | 'text', or null when unsupported.
     */
    private function resolveAttachmentRedactor(string $mimeType, string $extension): ?string
    {
        $wordExt  = ['doc', 'docx', 'odt', 'rtf'];
        $wordMime = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'application/rtf',
            'text/rtf',
        ];
        if (in_array($extension, $wordExt, true) === true || in_array($mimeType, $wordMime, true) === true) {
            return 'word';
        }

        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            return 'pdf';
        }

        $textExt = ['txt', 'csv', 'html', 'htm', 'md', 'log'];
        if (in_array($extension, $textExt, true) === true || str_starts_with($mimeType, 'text/') === true) {
            return 'text';
        }

        return null;
    }//end resolveAttachmentRedactor()

    /**
     * Materialise an attachment's decoded bytes into the scratch folder and run
     * the matching Node-based redactor, returning the redacted bytes. Word
     * inputs are emitted as Word2007 (docx) by the redactor regardless of the
     * source format.
     *
     * @param EmlAttachment         $attachment   The source attachment (decoded bytes).
     * @param array<string, string> $replacements Shared entity → placeholder map.
     * @param Folder                $workFolder   Scratch folder.
     * @param string                $kind         'word' | 'pdf' | 'text'.
     * @param int                   $depth        Current depth (for unique names).
     * @param int                   $index        Attachment index (for unique names).
     *
     * @throws Exception When the redacted output cannot be read.
     *
     * @return string The decoded redacted bytes.
     */
    private function materialiseAndRedactAttachment(
        EmlAttachment $attachment,
        array $replacements,
        Folder $workFolder,
        string $kind,
        int $depth,
        int $index
    ): string {
        $ext = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'bin';
        }

        $inName = sprintf('in_%d_%d.%s', $depth, $index, $ext);
        if ($workFolder->nodeExists($inName) === true) {
            $workFolder->get($inName)->delete();
        }

        $in = $workFolder->newFile(path: $inName, content: $attachment->content);

        switch ($kind) {
            case 'word':
                $out = $this->replaceWordsInWordDocument(
                    node: $in,
                    replacements: $replacements,
                    outputName: sprintf('out_%d_%d.docx', $depth, $index)
                );
                break;
            case 'pdf':
                $out = $this->replaceWordsInPdfDocument(
                    node: $in,
                    replacements: $replacements,
                    outputName: sprintf('out_%d_%d.pdf', $depth, $index)
                );
                break;
            default:
                $out = $this->replaceWordsInTextDocument(
                    node: $in,
                    replacements: $replacements,
                    outputName: sprintf('out_%d_%d.%s', $depth, $index, $ext)
                );
        }//end switch

        // Return the redacted bytes. getContent() returns a string and throws
        // on failure (caught by the caller's per-attachment fail-safe).
        return $out->getContent();
    }//end materialiseAndRedactAttachment()

    /**
     * Create the scratch folder for EML attachment materialisation, beside the
     * source EML. Any stale folder from a prior run is removed first.
     *
     * @param Node $node   The source EML node.
     * @param int  $fileId Resolved file id (0 when unknown).
     *
     * @return Folder The fresh scratch folder.
     */
    private function createEmlWorkFolder(Node $node, int $fileId): Folder
    {
        $parent = $node->getParent();
        $name   = '.openregister-eml-anon-'.($fileId > 0 ? (string) $fileId : 'tmp');
        if ($parent->nodeExists($name) === true) {
            $parent->get($name)->delete();
        }

        return $parent->newFolder(path: $name);
    }//end createEmlWorkFolder()

    /**
     * Best-effort removal of the EML scratch folder. A cleanup failure is
     * logged (PII-free) and swallowed — it never affects the redaction result.
     *
     * @param Folder $folder The scratch folder to delete.
     *
     * @return void
     */
    private function deleteEmlWorkFolder(Folder $folder): void
    {
        try {
            $folder->delete();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DocumentProcessingHandler: failed to remove EML scratch folder',
                ['exception' => get_class($e)]
            );
        }
    }//end deleteEmlWorkFolder()

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
                            // Cast $original to string: PHP silently coerces numeric-string
                            // array keys (e.g. a 9-digit BSN) to int, and str_ireplace()
                            // rejects an int as its $search argument.
                            $text = str_ireplace((string) $original, $replacement, $text);
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

            // PhpWord roundtrip-safety workaround for the soft-line-break bug
            // (PHPOffice/PHPWord issue #1274, open since 2018 and unfixed even
            // on master). The Word2007 writer emits a soft line break as a BARE
            // <w:br/> placed directly under <w:p> — a sibling of the runs —
            // instead of wrapping it in a run as <w:r><w:br/></w:r>. PhpWord's
            // own Word2007 reader (Reader\Word2007\AbstractPart::readRun) only
            // recognises <w:br/> that sits INSIDE a <w:r>, so every soft break
            // is silently dropped the next time the document is loaded — e.g.
            // by the docx→PDF conversion — collapsing multi-line blocks onto a
            // single line. We correct the writer's OWN output here (never
            // PhpWord's source, so there is no fork to maintain); the pass is
            // idempotent and self-deletes as a concern once #1274 is fixed.
            $this->wrapSoftLineBreaksInRuns($outputTempFile);

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
     * Wrap bare `<w:br/>` soft line breaks in a run inside a saved .docx.
     *
     * Works around PHPOffice/PHPWord issue #1274: the Word2007 writer emits a
     * soft line break as a bare `<w:br/>` directly under `<w:p>`, which its own
     * reader ignores (only `<w:br/>` inside a `<w:r>` is read), so the break is
     * lost on the next load. This rewrites the run-content XML parts of the
     * just-written document so every `<w:br .../>` is wrapped as
     * `<w:r><w:br .../></w:r>`, which both PhpWord and Word read correctly.
     *
     * The transform is idempotent: an already-wrapped break is first unwrapped
     * and then re-wrapped, so running it more than once (or after PhpWord is
     * eventually fixed) yields the same result and never nests runs. Only the
     * main document, header and footer parts are touched.
     *
     * @param string $docxPath Absolute path to the .docx file to fix in place.
     *
     * @return void
     */
    private function wrapSoftLineBreaksInRuns(string $docxPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false
                || preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) !== 1
            ) {
                continue;
            }

            $xml = $zip->getFromName($name);
            if ($xml === false || strpos($xml, '<w:br') === false) {
                continue;
            }

            $fixed = $this->wrapSoftLineBreaksInXml($xml);
            if ($fixed !== $xml) {
                $zip->addFromString($name, $fixed);
            }
        }

        $zip->close();

    }//end wrapSoftLineBreaksInRuns()

    /**
     * Wrap bare `<w:br .../>` elements in a `<w:r>` within a WordprocessingML
     * XML string. Pure string transform, extracted for testability.
     *
     * Idempotent: any break already wrapped in a run is normalised (unwrapped)
     * first, so the result contains exactly one run wrapper per break and never
     * nests runs.
     *
     * @param string $xml A WordprocessingML part (document/header/footer).
     *
     * @return string The same XML with every break wrapped as `<w:r><w:br .../></w:r>`.
     */
    private function wrapSoftLineBreaksInXml(string $xml): string
    {
        // 1. Unwrap any break that is the sole content of a run, so the wrap in
        // step 2 cannot double-nest it (keeps the pass idempotent).
        $xml = preg_replace('#<w:r>\s*(<w:br(?:\s[^>]*)?/>)\s*</w:r>#', '$1', $xml) ?? $xml;

        // 2. Wrap every bare break in a run.
        return preg_replace('#<w:br(?:\s[^>]*)?/>#', '<w:r>$0</w:r>', $xml) ?? $xml;

    }//end wrapSoftLineBreaksInXml()

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
     * Replace words in an OpenDocument Text (.odt) file (Strategy B).
     *
     * An ODT is a ZIP container whose visible text lives in `content.xml`
     * (body, tables, lists) and `styles.xml` (page headers/footers). Rather
     * than PhpWord's ODText reader — which silently drops tables, headers and
     * footers on load, destroying that content — this rewrites those two XML
     * parts in place: it parses each part, walks the text nodes of every
     * paragraph, and substitutes entity text with its `[<TYPE>: <id>]`
     * placeholder (handling entities split across `<text:span>` runs), leaving
     * all structure and formatting untouched. A fail-loud validation gate then
     * re-reads the rewritten parts and records any surviving entity via
     * {@see getLastResidualEntities} — an unredacted or unreadable ODT is never
     * reported as a clean success. The file is still written (best-effort
     * policy, matching the PDF/DOCX paths).
     *
     * @param Node   $node         The .odt file node to process.
     * @param array  $replacements Map: entity-text (needle) => `[<TYPE>: <id>]`.
     * @param string $outputName   Name for the output file.
     *
     * @throws Exception If the node content is unreadable or the ZIP cannot be opened.
     *
     * @phpstan-param  array<string, string> $replacements
     * @psalm-param    array<string, string> $replacements
     * @phpstan-return File
     * @psalm-return   File
     *
     * @return File The anonymised ODT.
     */
    private function replaceWordsInOdtDocument(
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        $content = $node->getContent();
        if (is_string($content) === false) {
            throw new Exception('Failed to get content from file: '.$node->getPath());
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'openregister_odt_');
        if ($tempFile === false) {
            throw new Exception('Failed to create temporary file');
        }

        file_put_contents($tempFile, $content);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new Exception('Failed to open ODT container');
            }

            // Redact the two ODF parts that carry visible text. content.xml
            // holds the body, tables and lists; styles.xml holds page
            // headers/footers. Every other entry (images, settings, mimetype)
            // is left untouched, preserving structure and formatting.
            foreach (['content.xml', 'styles.xml'] as $part) {
                $xml = $zip->getFromName($part);
                if (is_string($xml) === false || $xml === '') {
                    continue;
                }

                $fixed = $this->replaceTextInOdfXml(xml: $xml, replacements: $replacements);
                if ($fixed !== $xml) {
                    $zip->addFromString($part, $fixed);
                }
            }

            $zip->close();
        } catch (\Throwable $e) {
            if (file_exists($tempFile) === true) {
                unlink($tempFile);
            }

            $this->logger->error(
                message: '[DocumentProcessingHandler] Failed to replace words in ODT document: '.$e->getMessage(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e,
                ]
            );
            throw new Exception('Failed to replace words in ODT document: '.$e->getMessage(), 0, $e);
        }//end try

        // Fail-loud validation gate: re-read the rewritten ODF parts and record
        // any entity whose original text survives (or an unreadable container)
        // as residual — never report an unredacted ODT as a clean success.
        $this->recordOdtResidualEntities(odtPath: $tempFile, replacements: $replacements);

        // Write the redacted ODT to the target folder.
        $parentFolder = $node->getParent();
        if ($parentFolder->nodeExists($outputName) === true) {
            $parentFolder->get($outputName)->delete();
        }

        $outputStream = fopen($tempFile, 'r');
        $newFile      = $parentFolder->newFile(path: $outputName, content: $outputStream);
        // Do NOT call fclose($outputStream); Nextcloud handles the stream lifecycle internally.
        unlink($tempFile);

        $this->logger->debug(
            message: '[DocumentProcessingHandler] Words replaced in ODT document',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'originalFile' => $node->getPath(),
                'outputFile'   => $newFile->getPath(),
                'replacements' => count($replacements),
            ]
        );

        return $newFile;
    }//end replaceWordsInOdtDocument()

    /**
     * Rewrite entity text inside a single ODF XML part (content.xml/styles.xml).
     *
     * Parses the XML and processes each paragraph container (`text:p`,
     * `text:h`) independently: it concatenates the paragraph's text nodes,
     * finds entity occurrences across the concatenation (so an entity split
     * across `<text:span>` runs is still caught), and rewrites the underlying
     * text nodes so each occurrence becomes its placeholder. All markup,
     * attributes and non-text elements are preserved. Pure string transform —
     * no I/O — for testability. Returns the input unchanged when it cannot be
     * parsed (the validation gate then flags any residual).
     *
     * @param string $xml          A parsed-as-XML ODF part.
     * @param array  $replacements Map: entity-text (needle) => placeholder.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return string The rewritten XML (or the original on parse failure).
     */
    private function replaceTextInOdfXml(string $xml, array $replacements): string
    {
        if (empty($replacements) === true || trim($xml) === '') {
            return $xml;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('text', self::ODF_TEXT_NS);

        $paragraphs = $xpath->query('//text:p | //text:h');
        if ($paragraphs === false) {
            return $xml;
        }

        foreach ($paragraphs as $paragraph) {
            $this->replaceTextInOdfParagraph(xpath: $xpath, paragraph: $paragraph, replacements: $replacements);
        }

        $out = $dom->saveXML();
        return ($out === false) ? $xml : $out;

    }//end replaceTextInOdfXml()

    /**
     * Apply replacements to the text nodes of a single ODF paragraph.
     *
     * Concatenates the paragraph's descendant text nodes into one string (with
     * a byte→node ownership map), computes non-overlapping replacement ranges
     * over that string, then rebuilds each text node: bytes inside a replaced
     * range are dropped and the placeholder is emitted once, on the node owning
     * the range's start. This handles entities split across multiple runs.
     *
     * @param \DOMXPath $xpath        Namespace-registered XPath over the document.
     * @param \DOMNode  $paragraph    The `text:p` / `text:h` node.
     * @param array     $replacements Map: entity-text (needle) => placeholder.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return void
     */
    private function replaceTextInOdfParagraph(\DOMXPath $xpath, \DOMNode $paragraph, array $replacements): void
    {
        $textNodes = $xpath->query('.//text()', $paragraph);
        if ($textNodes === false || $textNodes->length === 0) {
            return;
        }

        $segments = [];
        $full     = '';
        foreach ($textNodes as $textNode) {
            $data = (string) $textNode->nodeValue;
            if ($data === '') {
                continue;
            }

            $segments[] = [
                'node'   => $textNode,
                'start'  => strlen($full),
                'length' => strlen($data),
            ];
            $full      .= $data;
        }

        if ($full === '') {
            return;
        }

        $ranges = $this->computeOdfReplacementRanges(haystack: $full, replacements: $replacements);
        if (empty($ranges) === true) {
            return;
        }

        $newValues = $this->rebuildOdfSegmentValues(full: $full, segments: $segments, ranges: $ranges);
        foreach ($segments as $index => $segment) {
            $node = $segment['node'];
            if ($node instanceof \DOMNode && array_key_exists($index, $newValues) === true) {
                $node->nodeValue = $newValues[$index];
            }
        }

    }//end replaceTextInOdfParagraph()

    /**
     * Compute non-overlapping replacement ranges over a concatenated string.
     *
     * Needles are processed longest-first so a shorter variant cannot pre-empt
     * a longer one, and matching is case-insensitive to mirror the
     * `str_ireplace` semantics used on the DOCX/text paths. Overlapping matches
     * are skipped (first writer wins).
     *
     * @param string $haystack     The concatenated paragraph text.
     * @param array  $replacements Map: entity-text (needle) => placeholder.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return array<int, array{start: int, end: int, text: string}> Sorted by start.
     */
    private function computeOdfReplacementRanges(string $haystack, array $replacements): array
    {
        $needles = array_keys($replacements);
        usort(
            $needles,
            static function ($a, $b): int {
                return (strlen((string) $b) <=> strlen((string) $a));
            }
        );

        $ranges   = [];
        $consumed = [];
        foreach ($needles as $needle) {
            $needleStr = (string) $needle;
            if ($needleStr === '') {
                continue;
            }

            $placeholder = (string) ($replacements[$needle] ?? '');
            $length      = strlen($needleStr);
            $offset      = 0;
            while (($pos = stripos($haystack, $needleStr, $offset)) !== false) {
                $end     = ($pos + $length);
                $overlap = false;
                for ($byte = $pos; $byte < $end; $byte++) {
                    if (isset($consumed[$byte]) === true) {
                        $overlap = true;
                        break;
                    }
                }

                if ($overlap === false) {
                    for ($byte = $pos; $byte < $end; $byte++) {
                        $consumed[$byte] = true;
                    }

                    $ranges[] = [
                        'start' => $pos,
                        'end'   => $end,
                        'text'  => $placeholder,
                    ];
                }

                $offset = ($pos + 1);
            }//end while
        }//end foreach

        usort(
            $ranges,
            static function (array $a, array $b): int {
                return ($a['start'] <=> $b['start']);
            }
        );

        return $ranges;

    }//end computeOdfReplacementRanges()

    /**
     * Rebuild each text-node string after applying replacement ranges.
     *
     * Walks the concatenated string byte by byte, attributing each surviving
     * byte to its owning node and emitting each placeholder once on the node
     * owning its range start. Bytes inside a replaced range are dropped from
     * whichever nodes they belonged to.
     *
     * @param string $full     The concatenated paragraph text.
     * @param array  $segments Ordered [node, start, length] segment descriptors.
     * @param array  $ranges   Sorted replacement ranges [start, end, text].
     *
     * @phpstan-param array<int, array{node: \DOMNode, start: int, length: int}> $segments
     * @phpstan-param array<int, array{start: int, end: int, text: string}>      $ranges
     *
     * @return array<int, string> Map of segment index => new text-node value.
     */
    private function rebuildOdfSegmentValues(string $full, array $segments, array $ranges): array
    {
        $owner = [];
        foreach ($segments as $index => $segment) {
            $end = ($segment['start'] + $segment['length']);
            for ($byte = $segment['start']; $byte < $end; $byte++) {
                $owner[$byte] = $index;
            }
        }

        $newValues = [];
        foreach (array_keys($segments) as $index) {
            $newValues[$index] = '';
        }

        $firstIndex = (array_key_first($segments) ?? 0);
        $total      = strlen($full);
        $rangeCount = count($ranges);
        $rangeIndex = 0;
        $byte       = 0;
        while ($byte < $total) {
            if ($rangeIndex < $rangeCount && $byte === $ranges[$rangeIndex]['start']) {
                $ownerIndex = ($owner[$byte] ?? $firstIndex);
                $newValues[$ownerIndex] .= $ranges[$rangeIndex]['text'];
                $byte = $ranges[$rangeIndex]['end'];
                $rangeIndex++;
                continue;
            }

            $ownerIndex = ($owner[$byte] ?? $firstIndex);
            $newValues[$ownerIndex] .= $full[$byte];
            $byte++;
        }

        return $newValues;

    }//end rebuildOdfSegmentValues()

    /**
     * Fail-loud validation gate for the ODT writeback path.
     *
     * Re-opens the just-written ODT and re-extracts the concatenated paragraph
     * text of content.xml + styles.xml (the same within-paragraph concatenation
     * the replacement used), then asserts every entity's original text is
     * absent. Any survivor — or an unreadable container / missing content.xml,
     * which means redaction cannot be proven — is recorded via
     * {@see getLastResidualEntities} using the same {text, type, id} record
     * shape and `[<TYPE>: <id>]` placeholder parsing as the PDF path. Never
     * throws; never deletes the output.
     *
     * @param string $odtPath      Absolute path to the just-written .odt file.
     * @param array  $replacements Map: entity-text (needle) => `[<TYPE>: <id>]`.
     *
     * @phpstan-param array<string, string> $replacements
     * @psalm-param   array<string, string> $replacements
     *
     * @return void
     */
    private function recordOdtResidualEntities(string $odtPath, array $replacements): void
    {
        if (empty($replacements) === true) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($odtPath) !== true) {
            // Cannot re-open the container → redaction cannot be proven.
            $this->recordResidualNeedles(needles: array_keys($replacements), replacements: $replacements);
            return;
        }

        $contentXml = $zip->getFromName('content.xml');
        $stylesXml  = $zip->getFromName('styles.xml');
        $zip->close();

        if (is_string($contentXml) === false || $contentXml === '') {
            // A valid ODT always has content.xml; its absence means we cannot
            // verify redaction, so fail loud.
            $this->recordResidualNeedles(needles: array_keys($replacements), replacements: $replacements);
            return;
        }

        $extracted = $this->extractOdfConcatenatedText(xml: $contentXml);
        if (is_string($stylesXml) === true && $stylesXml !== '') {
            $extracted .= "\n".$this->extractOdfConcatenatedText(xml: $stylesXml);
        }

        $survivors = array_keys(
            array_filter(
                $replacements,
                static function ($needle) use ($extracted): bool {
                    return (stripos($extracted, (string) $needle) !== false);
                },
                ARRAY_FILTER_USE_KEY
            )
        );

        $this->recordResidualNeedles(needles: $survivors, replacements: $replacements);

    }//end recordOdtResidualEntities()

    /**
     * Record a set of surviving needles as residual entities.
     *
     * Maps each surviving needle back to a {text, type, id} record via its
     * `[<TYPE>: <id>]` placeholder — identical shape to the PDF path — and
     * stores them in {@see $lastResidualEntities}. A no-op (and no log) when
     * there are no survivors, so a clean run reports nothing.
     *
     * @param array $needles      Surviving entity needles.
     * @param array $replacements Map: entity-text (needle) => `[<TYPE>: <id>]`.
     *
     * @phpstan-param array<int, int|string>  $needles
     * @phpstan-param array<string, string>   $replacements
     *
     * @return void
     */
    private function recordResidualNeedles(array $needles, array $replacements): void
    {
        if (empty($needles) === true) {
            return;
        }

        $records = [];
        foreach ($needles as $needle) {
            $placeholder = (string) ($replacements[$needle] ?? '');
            $type        = 'UNKNOWN';
            $id          = '';
            if (preg_match('/^\[([^:\]]+):\s*([^\]]*)\]$/', $placeholder, $matches) === 1) {
                $type = trim($matches[1]);
                $id   = trim($matches[2]);
            }

            $records[] = [
                'text' => (string) $needle,
                'type' => $type,
                'id'   => $id,
            ];
        }

        $this->lastResidualEntities = $records;

        // PII-free warning (ADR-005): count only, never the residual text.
        $this->logger->warning(
            message: '[DocumentProcessingHandler] ODT anonymisation left residual entities; output written best-effort.',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'residualCount' => count($records),
            ]
        );

    }//end recordResidualNeedles()

    /**
     * Extract the concatenated paragraph text of an ODF XML part.
     *
     * Mirrors the replacement pass: for each paragraph container it concatenates
     * the descendant text nodes with no separator (so a split entity reads as
     * one string), joining paragraphs with a newline. Used only by the
     * validation gate. Returns '' when the XML cannot be parsed.
     *
     * @param string $xml An ODF XML part (content.xml / styles.xml).
     *
     * @return string The concatenated visible text.
     */
    private function extractOdfConcatenatedText(string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }

        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded === false) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('text', self::ODF_TEXT_NS);

        $paragraphs = $xpath->query('//text:p | //text:h');
        if ($paragraphs === false) {
            return '';
        }

        $parts = [];
        foreach ($paragraphs as $paragraph) {
            $textNodes = $xpath->query('.//text()', $paragraph);
            if ($textNodes === false) {
                continue;
            }

            $buffer = '';
            foreach ($textNodes as $textNode) {
                $buffer .= (string) $textNode->nodeValue;
            }

            if ($buffer !== '') {
                $parts[] = $buffer;
            }
        }

        return implode("\n", $parts);

    }//end extractOdfConcatenatedText()

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
