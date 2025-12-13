<?php
/**
 * Document Service
 *
 * Service for manipulating document content, including word replacement
 * for anonymization purposes. This service provides functionality to
 * replace text in various document formats (Word, PDF, text files).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Service for document manipulation and word replacement
 *
 * This service provides methods to replace text in documents for
 * anonymization and other purposes. It supports Word documents,
 * text files, and other formats.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */
class DocumentService
{
    /**
     * Logger instance for error reporting
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Root folder service for file operations
     *
     * @var IRootFolder
     */
    private readonly IRootFolder $rootFolder;

    /**
     * Constructor for DocumentService
     *
     * @param LoggerInterface $logger     Logger for error reporting
     * @param IRootFolder     $rootFolder Root folder service for file operations
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        IRootFolder $rootFolder
    ) {
        $this->logger     = $logger;
        $this->rootFolder = $rootFolder;

    }//end __construct()

    /**
     * Replace words in a document
     *
     * This method replaces specified words/phrases in a document with
     * replacement text. It supports Word documents and text-based files.
     *
     * @param Node   $node         The file node to process
     * @param array  $replacements Array of replacement mappings ['original' => 'replacement']
     * @param string $outputName   Optional name for the output file (default: adds '_replaced' suffix)
     *
     * @return Node The new file node with replaced content
     *
     * @throws Exception If replacement fails
     */
    public function replaceWords(Node $node, array $replacements, ?string $outputName=null): Node
    {
        if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
            throw new Exception('Node must be a file');
        }

        $fileName      = $node->getName();
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

        // Generate output file name if not provided.
        if ($outputName === null) {
            $outputName = $fileNameWithoutExtension.'_replaced';
            if (empty($fileExtension) === false) {
                $outputName .= '.'.$fileExtension;
            }
        }

        // Process based on file type.
        if (in_array($fileExtension, ['doc', 'docx'], true) === true) {
            return $this->replaceWordsInWordDocument($node, $replacements, $outputName);
        } else {
            return $this->replaceWordsInTextDocument($node, $replacements, $outputName);
        }

    }//end replaceWords()

    /**
     * Replace words in a Word document
     *
     * @param Node   $node         The file node to process
     * @param array  $replacements Array of replacement mappings
     * @param string $outputName   Name for the output file
     *
     * @return File The new file node with replaced content
     *
     * @throws Exception If replacement fails
     */
    private function replaceWordsInWordDocument(
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        // Get the file content as a stream and save to a temp file.
        $stream   = $node->fopen('r');
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
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($tempFile);

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

            // Save the modified document to a new temp file.
            $outputTempFile = tempnam(sys_get_temp_dir(), 'openregister_word_output_');
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($outputTempFile);

            // Get the parent folder and create the new file.
            $parentFolder = $node->getParent();
            if ($parentFolder->nodeExists($outputName) === true) {
                $parentFolder->get($outputName)->delete();
            }

            $outputStream = fopen($outputTempFile, 'r');
            $newFile     = $parentFolder->newFile($outputName, $outputStream);
            // Do NOT call fclose($outputStream) here; Nextcloud handles the stream lifecycle internally.

            // Clean up temp files.
            unlink($tempFile);
            unlink($outputTempFile);

            $this->logger->debug(
                'Words replaced in Word document',
                [
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
                'Failed to replace words in Word document: '.$e->getMessage(),
                [
                    'exception' => $e,
                ]
            );
            throw new Exception('Failed to replace words in Word document: '.$e->getMessage(), 0, $e);
        }//end try

    }//end replaceWordsInWordDocument()

    /**
     * Replace words in a text-based document
     *
     * @param Node   $node         The file node to process
     * @param array  $replacements Array of replacement mappings
     * @param string $outputName   Name for the output file
     *
     * @return File The new file node with replaced content
     *
     * @throws Exception If replacement fails
     */
    private function replaceWordsInTextDocument(
        Node $node,
        array $replacements,
        string $outputName
    ): File {
        // Get file content.
        $content = $node->getContent();
        if ($content === false) {
            throw new Exception('Failed to get content from file: '.$node->getPath());
        }

        // Apply replacements.
        $modifiedContent = $content;
        foreach ($replacements as $original => $replacement) {
            $modifiedContent = str_ireplace($original, $replacement, $modifiedContent);
        }

        // Create output file.
        $parentFolder = $node->getParent();
        if ($parentFolder->nodeExists($outputName) === true) {
            $parentFolder->get($outputName)->delete();
        }

        $newFile = $parentFolder->newFile($outputName, $modifiedContent);

        $this->logger->debug(
            'Words replaced in text document',
            [
                'originalFile' => $node->getPath(),
                'outputFile'   => $newFile->getPath(),
                'replacements' => count($replacements),
            ]
        );

        return $newFile;

    }//end replaceWordsInTextDocument()

    /**
     * Anonymize a document by replacing detected entities
     *
     * This is a convenience method that creates replacement mappings
     * from entity detection results and applies them to a document.
     *
     * @param Node  $node     The file node to anonymize
     * @param array $entities Array of detected entities with 'text' and 'key' fields
     *
     * @return Node The anonymized file node
     *
     * @throws Exception If anonymization fails
     */
    public function anonymizeDocument(Node $node, array $entities): Node
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
        $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

        $anonymizedFileName = $fileNameWithoutExtension.'_anonymized';
        if (empty($fileExtension) === false) {
            $anonymizedFileName .= '.'.$fileExtension;
        }

        return $this->replaceWords($node, $replacements, $anonymizedFileName);

    }//end anonymizeDocument()

}//end class


