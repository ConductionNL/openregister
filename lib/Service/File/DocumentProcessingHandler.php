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

use Exception;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Orchestrates the Word/PDF/text replacement pipelines plus SAPP + PhpWord integration.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   File / PDF / PhpWord / sanitiser collaborators are required by design.
 */
class DocumentProcessingHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for DocumentProcessingHandler.
     *
     * @param IRootFolder     $rootFolder  Root folder for file access.
     * @param IUserSession    $userSession User session for getting current user.
     * @param LoggerInterface $logger      Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
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
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-file/specs/file-actions/spec.md#REQ-008
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
     * @param Node  $node     The file node to anonymize.
     * @param array $entities Array of detected entities with 'text', 'entityType', and 'key' fields.
     *
     * @throws Exception If anonymization fails.
     *
     * @phpstan-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
     *
     * @psalm-param array<int, array{text?: string, entityType?: string, key?: string}> $entities
     *
     * @return File The anonymized document file.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-file/specs/file-actions/spec.md#REQ-008
     */
    public function anonymizeDocument(Node $node, array $entities): File
    {
        // Build replacements array from entities.
        $replacements = [];
        foreach ($entities as $entity) {
            $originalText = $entity['text'] ?? '';
            $entityType   = $entity['entityType'] ?? 'UNKNOWN';
            $key          = $entity['key'] ?? substr(\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), 0, 8);

            if (empty($originalText) === false) {
                $replacements[$originalText] = '['.$entityType.': '.$key.']';
            }
        }

        // Generate anonymized file name.
        $fileName      = $node->getName();
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileBaseName  = pathinfo($fileName, PATHINFO_FILENAME);

        $anonymizedFileName = $fileBaseName.'_anonymized';
        if (empty($fileExtension) === false) {
            $anonymizedFileName .= '.'.$fileExtension;
        }

        // Entity anonymisation is GDPR-critical: fail closed if any original
        // entity text survives in the output (PDF path) rather than writing a
        // file marked '_anonymized' that still contains it.
        return $this->replaceWords(node: $node, replacements: $replacements, outputName: $anonymizedFileName, strict: true);
    }//end anonymizeDocument()

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
     * @spec openspec/changes/pdf-anonymisation/specs/pdf-anonymisation/spec.md
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
            $parser    = new \Smalot\PdfParser\Parser();
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
        $replacedBytes = $replacer->replaceInPdf(pdfBytes: $content, substitutions: $replacements, strict: $strict);

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
