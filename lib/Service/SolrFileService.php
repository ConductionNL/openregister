<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;

/**
 * SOLR File Service
 *
 * Handles file-specific SOLR operations including text extraction, chunking,
 * and indexing files to the fileCollection.
 *
 * This service focuses exclusively on file processing operations and delegates
 * core SOLR infrastructure tasks to GuzzleSolrService.
 *
 * Future integration with LLPhant for:
 * - Document loading (PDF, DOCX, images)
 * - Text extraction
 * - Intelligent chunking
 * - OCR for images
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
class SolrFileService
{
    /**
     * Default chunking configuration
     */
    /**
     * Default chunk size in tokens
     *
     * @var int
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Default chunk overlap in tokens
     *
     * @var int
     */
    private const DEFAULT_CHUNK_OVERLAP = 200;

    /**
     * Maximum chunks per file (safety limit)
     *
     * @var int
     */
    private const MAX_CHUNKS_PER_FILE = 1000;

    /**
     * Minimum chunk size in tokens
     *
     * @var int
     */
    private const MIN_CHUNK_SIZE = 100;

    /**
     * Chunking strategies
     */
    private const RECURSIVE_CHARACTER = 'RECURSIVE_CHARACTER';
    private const FIXED_SIZE          = 'FIXED_SIZE';

    /**
     * Lazy-loaded TextExtractionService to break circular dependency
     *
     * @var TextExtractionService|null
     */
    private ?TextExtractionService $textExtractionService = null;


    /**
     * Constructor
     *
     * @param GuzzleSolrService $guzzleSolrService Core SOLR operations service
     * @param SettingsService   $settingsService   Settings management service
     * @param IAppContainer     $container         App container for lazy service loading
     * @param LoggerInterface   $logger            PSR-3 logger
     */
    public function __construct(
        private readonly GuzzleSolrService $guzzleSolrService,
        private readonly SettingsService $settingsService,
        private readonly IAppContainer $container,
        private readonly LoggerInterface $logger,
        private readonly ChunkMapper $chunkMapper,
    ) {

    }//end __construct()


    /**
     * Get TextExtractionService instance (lazy loading to break circular dependency)
     *
     * @return TextExtractionService
     */
    private function getTextExtractionService(): TextExtractionService
    {
        if ($this->textExtractionService === null) {
            $this->textExtractionService = $this->container->get(TextExtractionService::class);
        }

        return $this->textExtractionService;

    }//end getTextExtractionService()


    /**
     * Get the collection name for file operations
     *
     * @return string|null The fileCollection name, or null if not configured
     */
    private function getFileCollection(): ?string
    {
        $solrSettings = $this->settingsService->getSolrSettingsOnly();
        return $solrSettings['fileCollection'] ?? null;

    }//end getFileCollection()


    /**
     * Process and index a file
     *
     * This method handles the complete file processing pipeline:
     * 1. Extract text from file
     * 2. Chunk the text intelligently
     * 3. Index chunks to SOLR fileCollection
     * 4. (Future) Generate embeddings for vector search
     *
     * @param string $filePath Path to the file
     * @param array  $metadata File metadata (id, name, type, etc.)
     *
     * @return (array|mixed|scalar)[] Processing result with statistics
     *
     * @throws \Exception If fileCollection is not configured
     *
     * @psalm-return array{
     *     success: bool,
     *     file_id: mixed|string,
     *     error?: string,
     *     processing_time_ms: float,
     *     collection: string,
     *     file_name?: mixed|string,
     *     text_length?: int<0, max>,
     *     chunks_created?: int<1, max>,
     *     chunks_indexed?: 0|mixed,
     *     index_result?: array
     * }
     */
    public function processAndIndexFile(string $filePath, array $metadata): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        $this->logger->info(
                'Processing file for indexing',
                [
                    'file'       => $filePath,
                    'file_id'    => $metadata['file_id'] ?? null,
                    'collection' => $collection,
                ]
                );

        $startTime = microtime(true);

        try {
            // Step 1: Extract text from file (supports PDF, DOCX, XLSX, PPTX, images, etc.).
            $this->logger->debug(message: 'Step 1: Extracting text from file');
            $fullText = $this->extractTextFromFile($filePath);

            if (trim($fullText) === '' || trim($fullText) === null) {
                throw new \Exception('No text extracted from file');
            }

            // Step 2: Chunk the document intelligently (preserves sentence/paragraph boundaries).
            $this->logger->debug(message: 'Step 2: Chunking document', context: ['text_length' => strlen($fullText)]);
            $chunks = $this->chunkDocument(
                    $fullText,
                    [
                        'chunk_size'    => $metadata['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE,
                        'chunk_overlap' => $metadata['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP,
                        'strategy'      => $metadata['chunk_strategy'] ?? self::RECURSIVE_CHARACTER,
                        'file_type'     => $metadata['file_type'] ?? null,
                    ]
                    );

            if ($chunks === []) {
                throw new \Exception('No chunks created from document');
            }

            // Step 3: Index chunks to SOLR fileCollection with metadata.
            $this->logger->debug(message: 'Step 3: Indexing chunks to SOLR', context: ['chunk_count' => count($chunks)]);
            $indexResult = $this->indexFileChunks(
                $metadata['file_id'] ?? basename($filePath),
                $chunks,
                $metadata
            );

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $result = [
                'success'            => true,
                'file_id'            => $metadata['file_id'] ?? basename($filePath),
                'file_name'          => $metadata['file_name'] ?? basename($filePath),
                'text_length'        => strlen($fullText),
                'chunks_created'     => count($chunks),
                'chunks_indexed'     => $indexResult['indexed'] ?? 0,
                'processing_time_ms' => $processingTime,
                'collection'         => $collection,
                'index_result'       => $indexResult,
            ];

            $this->logger->info(message: 'File processing completed successfully', context: $result);

            return $result;
        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error(
                    'File processing failed',
                    [
                        'file'               => basename($filePath),
                        'error'              => $e->getMessage(),
                        'processing_time_ms' => $processingTime,
                    ]
                    );

            return [
                'success'            => false,
                'file_id'            => $metadata['file_id'] ?? basename($filePath),
                'error'              => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'collection'         => $collection,
            ];
        }//end try

    }//end processAndIndexFile()


    /**
     * Extract text from a file
     *
     * Supported formats:
     * - PDF (via pdftotext or Smalot\PdfParser)
     * - Word (.docx via PhpOffice\PhpWord)
     * - Excel (.xlsx via PhpOffice\PhpSpreadsheet)
     * - PowerPoint (.pptx via PhpOffice\PhpPresentation)
     * - Images (.jpg, .png via Tesseract OCR)
     * - Text files (.txt, .md, .html)
     *
     * @param string $filePath Path to the file
     *
     * @return string Extracted text content
     *
     * @throws \Exception If file format is not supported or extraction fails
     */
    public function extractTextFromFile(string $filePath): string
    {
        if (file_exists($filePath) === false) {
            throw new \Exception("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $fileSize  = filesize($filePath);

        $this->logger->info(
                'Extracting text from file',
                [
                    'file'       => basename($filePath),
                    'extension'  => $extension,
                    'size_bytes' => $fileSize,
                ]
                );

        $startTime = microtime(true);

        try {
            $text = match ($extension) {
                // Text files - direct reading.
                'txt', 'md', 'markdown', 'text' => $this->extractFromTextFile($filePath),

                // HTML files.
                'html', 'htm' => $this->extractFromHtml($filePath),

                // PDF files.
                'pdf' => $this->extractFromPdf($filePath),

                // Microsoft Office formats.
                'docx' => $this->extractFromDocx($filePath),
                'xlsx' => $this->extractFromXlsx($filePath),
                'pptx' => $this->extractFromPptx($filePath),

                // Images (OCR).
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff' => $this->extractFromImage($filePath),

                // JSON.
                'json' => $this->extractFromJson($filePath),

                // XML.
                'xml' => $this->extractFromXml($filePath),

                default => throw new \Exception("Unsupported file format: {$extension}")
            };//end match

            $extractionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                    'Text extraction successful',
                    [
                        'file'               => basename($filePath),
                        'text_length'        => strlen($text),
                        'extraction_time_ms' => $extractionTime,
                    ]
                    );

            return $text;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Text extraction failed',
                    [
                        'file'      => basename($filePath),
                        'extension' => $extension,
                        'error'     => $e->getMessage(),
                    ]
                    );
            throw new \Exception("Failed to extract text from {$extension} file: ".$e->getMessage());
        }//end try

    }//end extractTextFromFile()


    /**
     * Extract text from plain text file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromTextFile(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \Exception('Failed to read file');
        }

        return $content;

    }//end extractFromTextFile()


    /**
     * Extract text from HTML file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromHtml(string $filePath): string
    {
        $html = file_get_contents($filePath);
        if ($html === false) {
            throw new \Exception('Failed to read HTML file');
        }

        // Strip HTML tags and decode entities.
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace.
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);

    }//end extractFromHtml()


    /**
     * Extract text from PDF file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromPdf(string $filePath): string
    {
        // Try using Smalot PdfParser if available.
        if (class_exists('\Smalot\PdfParser\Parser') === true) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf    = $parser->parseFile($filePath);
                return $pdf->getText();
            } catch (\Exception $e) {
                $this->logger->warning(
                        'Smalot PDF parser failed, trying pdftotext',
                        [
                            'error' => $e->getMessage(),
                        ]
                        );
            }
        }

        // Fallback to pdftotext command if available.
        if ($this->commandExists('pdftotext') === true) {
            $outputFile = tempnam(sys_get_temp_dir(), 'pdf_').'.txt';
            $command    = sprintf('pdftotext %s %s 2>&1', escapeshellarg($filePath), escapeshellarg($outputFile));
            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($outputFile) === true) {
                $text = file_get_contents($outputFile);
                unlink($outputFile);
                if ($text !== false && $text !== '') {
                    return $text;
                }

                return '';
            }
        }

        throw new \Exception('PDF extraction requires Smalot PdfParser or pdftotext command');

    }//end extractFromPdf()


    /**
     * Extract text from DOCX file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromDocx(string $filePath): string
    {
        if (class_exists('\PhpOffice\PhpWord\IOFactory') === false) {
            throw new \Exception('PhpOffice\PhpWord is required for DOCX extraction');
        }

        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
            $text    = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText') === true) {
                        $text .= $element->getText()."\n";
                    } else if (method_exists($element, 'getElements') === true) {
                        foreach ($element->getElements() as $childElement) {
                            if (method_exists($childElement, 'getText') === true) {
                                $text .= $childElement->getText()."\n";
                            }
                        }
                    }
                }
            }

            return trim($text);
        } catch (\Exception $e) {
            throw new \Exception('Failed to extract text from DOCX: '.$e->getMessage());
        }//end try

    }//end extractFromDocx()


    /**
     * Extract text from XLSX file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromXlsx(string $filePath): string
    {
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory') === false) {
            throw new \Exception('PhpOffice\PhpSpreadsheet is required for XLSX extraction');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $text        = '';

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $text .= "Sheet: ".$sheet->getTitle()."\n\n";

                foreach ($sheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);

                    $rowData = [];
                    foreach ($cellIterator as $cell) {
                        $rowData[] = $cell->getValue();
                    }

                    $text .= implode("\t", $rowData)."\n";
                }

                $text .= "\n";
            }

            return trim($text);
        } catch (\Exception $e) {
            throw new \Exception('Failed to extract text from XLSX: '.$e->getMessage());
        }//end try

    }//end extractFromXlsx()


    /**
     * Extract text from PPTX file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromPptx(string $filePath): string
    {
        // PhpPresentation is not as widely used, so we'll use a simple ZIP extraction.
        if (class_exists('\ZipArchive') === false) {
            throw new \Exception('ZipArchive extension is required for PPTX extraction');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception('Failed to open PPTX file as ZIP');
        }

        $text = '';

        // Extract text from slides.
        for ($i = 1; $i < 100; $i++) {
            $slideXml = $zip->getFromName("ppt/slides/slide{$i}.xml");
            if ($slideXml === false) {
                break;
            }

            // Extract text from XML.
            $xml = simplexml_load_string($slideXml);
            if ($xml !== false) {
                $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $textElements = $xml->xpath('//a:t');

                foreach ($textElements as $textElement) {
                    $text .= (string) $textElement.' ';
                }

                $text .= "\n\n";
            }
        }

        $zip->close();
        return trim($text);

    }//end extractFromPptx()


    /**
     * Extract text from image using OCR
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromImage(string $filePath): string
    {
        // Check if Tesseract OCR is available.
        if ($this->commandExists('tesseract') === false) {
            throw new \Exception('Tesseract OCR is required for image text extraction. Install with: sudo apt-get install tesseract-ocr');
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'ocr_');
        $command    = sprintf('tesseract %s %s 2>&1', escapeshellarg($filePath), escapeshellarg($outputFile));
        exec($command, $output, $returnCode);

        $textFile = $outputFile.'.txt';
        if ($returnCode === 0 && file_exists($textFile) === true) {
            $text = file_get_contents($textFile);
            unlink($textFile);
            if (file_exists($outputFile) === true) {
                unlink($outputFile);
            }

            if ($text !== false && $text !== '') {
                return $text;
            }

            return '';
        }

        throw new \Exception('OCR extraction failed. Tesseract returned code: '.$returnCode);

    }//end extractFromImage()


    /**
     * Extract text from JSON file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromJson(string $filePath): string
    {
        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \Exception('Failed to read JSON file');
        }

        $data = json_decode($json, true);
        if ($data === null) {
            throw new \Exception('Invalid JSON format');
        }

        // Convert JSON to readable text format.
        return $this->jsonToText($data);

    }//end extractFromJson()


    /**
     * Extract text from XML file
     *
     * @param string $filePath File path
     *
     * @return string Extracted text
     */
    private function extractFromXml(string $filePath): string
    {
        $xml = file_get_contents($filePath);
        if ($xml === false) {
            throw new \Exception('Failed to read XML file');
        }

        // Strip XML tags.
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);

    }//end extractFromXml()


    /**
     * Convert JSON data to readable text
     *
     * @param mixed  $data   JSON data
     * @param string $prefix Prefix for nested keys
     *
     * @return string Readable text
     */
    private function jsonToText($data, string $prefix=''): string
    {
        $text = '';

        if (is_array($data) === true) {
            foreach ($data as $key => $value) {
                if ($prefix !== null && $prefix !== '') {
                    $newPrefix = $prefix.'.'.$key;
                } else {
                    $newPrefix = $key;
                }

                if (is_scalar($value) === true) {
                    $text .= $newPrefix.': '.$value."\n";
                } else if (is_array($value) === true || is_object($value) === true) {
                    $text .= $this->jsonToText($value, $newPrefix);
                }
            }
        } else if (is_object($data) === true) {
            $text .= $this->jsonToText((array) $data, $prefix);
        }

        return $text;

    }//end jsonToText()


    /**
     * Check if a command exists in the system
     *
     * @param string $command Command name
     *
     * @return bool True if command exists
     */
    private function commandExists(string $command): bool
    {
        /*
         */
        $result = shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($command)));
        return !empty($result);

    }//end commandExists()


    /**
     * Chunk a document into smaller pieces for indexing and embedding
     *
     * Uses intelligent recursive splitting that preserves sentence and paragraph boundaries
     *
     * @param string $text    The full text to chunk
     * @param array  $options Chunking options (chunk_size, chunk_overlap, strategy, file_type)
     *
     * @return string[] Array of text chunks
     *
     * @psalm-return array<int, string>
     */
    public function chunkDocument(string $text, array $options=[]): array
    {
        $chunkSize    = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
        $chunkOverlap = $options['chunk_overlap'] ?? self::DEFAULT_CHUNK_OVERLAP;
        $strategy     = $options['strategy'] ?? self::RECURSIVE_CHARACTER;
        $fileType     = $options['file_type'] ?? null;

        $this->logger->debug(
                'Chunking document',
                [
                    'text_length'   => strlen($text),
                    'chunk_size'    => $chunkSize,
                    'chunk_overlap' => $chunkOverlap,
                    'strategy'      => $strategy,
                    'file_type'     => $fileType,
                ]
                );

        $startTime = microtime(true);

        // Clean the text first.
        $text = $this->cleanText($text);

        // Choose chunking strategy.
        $chunks = match ($strategy) {
            self::FIXED_SIZE => $this->chunkFixedSize($text, $chunkSize, $chunkOverlap),
            self::RECURSIVE_CHARACTER => $this->chunkRecursive($text, $chunkSize, $chunkOverlap),
            default => $this->chunkRecursive($text, $chunkSize, $chunkOverlap)
        };

        // Respect max chunks limit.
        if (count($chunks) > self::MAX_CHUNKS_PER_FILE) {
            $this->logger->warning(
                    'File exceeds max chunks, truncating',
                    [
                        'chunks' => count($chunks),
                        'max'    => self::MAX_CHUNKS_PER_FILE,
                    ]
                    );
            $chunks = array_slice($chunks, 0, self::MAX_CHUNKS_PER_FILE);
        }

        $chunkingTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Document chunked successfully',
                [
                    'chunk_count'      => count($chunks),
                    'chunking_time_ms' => $chunkingTime,
                    //
                    'avg_chunk_size'   => $this->calculateAvgChunkSize($chunks),
                ]
                );

        return $chunks;

    }//end chunkDocument()


    /**
     * Clean text by removing excessive whitespace and normalizing
     *
     * @param string $text Text to clean
     *
     * @return string Cleaned text
     */
    private function cleanText(string $text): string
    {
        // Remove null bytes.
        $text = str_replace("\0", '', $text);

        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove excessive whitespace but preserve paragraph breaks.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);

    }//end cleanText()


    /**
     * Calculate average chunk size from an array of chunks.
     *
     * @param array<string> $chunks Array of chunk strings.
     *
     * @return float Average chunk size in characters.
     */
    private function calculateAvgChunkSize(array $chunks): float
    {
        if (count($chunks) === 0) {
            return 0.0;
        }

        $totalSize = 0;
        foreach ($chunks as $chunk) {
            $totalSize += strlen($chunk);
        }

        return round($totalSize / count($chunks), 2);

    }//end calculateAvgChunkSize()


    /**
     * Chunk text using fixed size with overlap
     *
     * @param string $text         Text to chunk
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return string[] Chunks
     *
     * @psalm-return array<int<0, max>, string>
     */
    private function chunkFixedSize(string $text, int $chunkSize, int $chunkOverlap): array
    {
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < strlen($text)) {
            // Extract chunk.
            $chunk = substr($text, $offset, $chunkSize);

            // Try to break at word boundary if not at end.
            if ($offset + $chunkSize < strlen($text)) {
                $lastSpace = strrpos($chunk, ' ');
                if ($lastSpace !== false && $lastSpace > $chunkSize * 0.8) {
                    $chunk = substr($chunk, 0, $lastSpace);
                }
            }

            if (strlen(trim($chunk)) >= self::MIN_CHUNK_SIZE) {
                $chunks[] = trim($chunk);
            }

            $offset += strlen($chunk) - $chunkOverlap;

            // Prevent infinite loop.
            if ($offset <= 0) {
                $offset = strlen($chunk);
            }
        }//end while

        return array_filter($chunks, fn($c) => !empty(trim($c)));

    }//end chunkFixedSize()


    /**
     * Chunk text recursively by trying different separators
     *
     * This method tries to split by:
     * 1. Double newlines (paragraphs)
     * 2. Single newlines (lines)
     * 3. Sentences (periods, exclamation marks, question marks)
     * 4. Commas
     * 5. Spaces (words)
     *
     * @param string $text         Text to chunk
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return array<int, string> Chunks
     */
    private function chunkRecursive(string $text, int $chunkSize, int $chunkOverlap): array
    {
        // If text is already small enough, return it.
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        // Define separators in order of preference.
        $separators = [
            "\n\n",
        // Paragraphs.
            "\n",
        // Lines.
            ". ",
        // Sentences.
            "! ",
            "? ",
            "; ",
            ", ",
        // Clauses.
            " ",
        // Words.
        ];

        return $this->recursiveSplit($text, $separators, $chunkSize, $chunkOverlap);

    }//end chunkRecursive()


    /**
     * Recursively split text using different separators
     *
     * @param string $text         Text to split
     * @param array  $separators   Array of separators to try
     * @param int    $chunkSize    Target chunk size
     * @param int    $chunkOverlap Overlap size
     *
     * @return array<int, string> Chunks
     */
    private function recursiveSplit(string $text, array $separators, int $chunkSize, int $chunkOverlap): array
    {
        // If text is small enough, return it.
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        // If no separators left, use fixed size chunking.
        if ($separators === []) {
            return $this->chunkFixedSize($text, $chunkSize, $chunkOverlap);
        }

        // Try splitting with current separator.
        $separator = array_shift($separators);
        $splits    = explode($separator, $text);

        // Rebuild chunks.
        $chunks       = [];
        $currentChunk = '';

        foreach ($splits as $split) {
            if ($currentChunk === '') {
                $testChunk = $split;
            } else {
                $testChunk = $currentChunk.$separator.$split;
            }

            if (strlen($testChunk) <= $chunkSize) {
                // Can add to current chunk.
                $currentChunk = $testChunk;
            } else {
                // Current chunk is full.
                if ($currentChunk !== '') {
                    if (strlen(trim($currentChunk)) >= self::MIN_CHUNK_SIZE) {
                        $chunks[] = trim($currentChunk);
                    }

                    // Add overlap from end of previous chunk.
                    if ($chunkOverlap > 0 && strlen($currentChunk) > $chunkOverlap) {
                        $overlapText  = substr($currentChunk, -$chunkOverlap);
                        $currentChunk = $overlapText.$separator.$split;
                    } else {
                        $currentChunk = $split;
                    }
                } else {
                    // Single split is too large, need to split it further.
                    if (strlen($split) > $chunkSize) {
                        $subChunks    = $this->recursiveSplit($split, $separators, $chunkSize, $chunkOverlap);
                        $chunks       = array_merge($chunks, $subChunks);
                        $currentChunk = '';
                    } else {
                        $currentChunk = $split;
                    }
                }//end if
            }//end if
        }//end foreach

        // Add the last chunk.
        if ($currentChunk !== '' && strlen(trim($currentChunk)) >= self::MIN_CHUNK_SIZE) {
            $chunks[] = trim($currentChunk);
        }

        return array_filter($chunks, fn($c) => !empty(trim($c)));

    }//end recursiveSplit()


    /**
     * Index file chunks to SOLR fileCollection
     *
     * @param string $fileId   File identifier
     * @param array  $chunks   Array of text chunks
     * @param array  $metadata File metadata
     *
     * @return (bool|int|string)[] Indexing result
     *
     * @throws \Exception If fileCollection is not configured
     *
     * @psalm-return array{success: bool, indexed: int<0, max>, collection: string}
     */
    public function indexFileChunks(string $fileId, array $chunks, array $metadata): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        $this->logger->info(
                'Indexing file chunks to fileCollection',
                [
                    'file_id'     => $fileId,
                    'chunk_count' => count($chunks),
                    'collection'  => $collection,
                ]
                );

        $documents = [];
        foreach ($chunks as $index => $chunkText) {
            $documents[] = [
                'id'           => $fileId.'_chunk_'.$index,
                'file_id'      => $fileId,
                'chunk_index'  => $index,
                'total_chunks' => count($chunks),
                'chunk_text'   => $chunkText,
                'file_name'    => $metadata['file_name'] ?? '',
                'file_type'    => $metadata['file_type'] ?? '',
                'file_size'    => $metadata['file_size'] ?? 0,
                'created_at'   => date('c'),
            ];
        }

        // TODO: PHASE 4 - Use collection-aware bulk indexing
        // For now, use existing bulk index method.
        $success = $this->guzzleSolrService->bulkIndex($documents, true);

        if ($success === true) {
            $indexedCount = count($documents);
        } else {
            $indexedCount = 0;
        }

        return [
            'success'    => $success,
            'indexed'    => $indexedCount,
            'collection' => $collection,
        ];

    }//end indexFileChunks()


    /**
     * Search files in SOLR
     *
     * @param array $query Search query parameters
     *
     * @return (array|int|string)[] Search results
     *
     * @throws \Exception If fileCollection is not configured
     *
     * @psalm-return array{results: array<never, never>, total: 0, collection: string}
     */
    public function searchFiles(array $query=[]): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        $this->logger->debug(
                'Searching files in fileCollection',
                [
                    'collection' => $collection,
                    'query'      => $query,
                ]
                );

        // TODO: PHASE 2 - Implement collection-aware search
        // For now, this is a placeholder.
        return [
            'results'    => [],
            'total'      => 0,
            'collection' => $collection,
        ];

    }//end searchFiles()


    /**
     * Delete a file and all its chunks from SOLR
     *
     * @param string $fileId File identifier
     *
     * @return array|bool True if deletion succeeded
     *
     * @throws \Exception If fileCollection is not configured
     */
    public function deleteFile(string $fileId): array|bool
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        $this->logger->info(
                'Deleting file from fileCollection',
                [
                    'file_id'    => $fileId,
                    'collection' => $collection,
                ]
                );

        // Delete all chunks for this file.
        $query = "file_id:{$fileId}";

        // TODO: PHASE 2 - Use collection-aware delete.
        return $this->guzzleSolrService->deleteByQuery(query: $query, commit: true);

    }//end deleteFile()


    /**
     * Get statistics for files in SOLR
     *
     * @return (false|int|mixed|null|string)[] Statistics including document count, collection info
     *
     * @throws \Exception If fileCollection is not configured
     *
     * @psalm-return array{
     *     available: false|mixed,
     *     collection?: string,
     *     document_count?: 0|mixed,
     *     total_files?: 0|mixed,
     *     indexed_files?: 0|mixed,
     *     collection_info?: mixed|null,
     *     error?: 'fileCollection not configured'
     * }
     */
    public function getFileStats(): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            return [
                'available' => false,
                'error'     => 'fileCollection not configured',
            ];
        }

        // Get dashboard stats and extract file collection info.
        $dashboardStats = $this->guzzleSolrService->getDashboardStats();

        return [
            'available'       => $dashboardStats['available'] ?? false,
            'collection'      => $collection,
            'document_count'  => $dashboardStats['fileDocuments'] ?? 0,
            'total_files'     => $dashboardStats['total_files'] ?? 0,
            'indexed_files'   => $dashboardStats['indexed_files'] ?? 0,
            'collection_info' => $dashboardStats['collections']['file'] ?? null,
        ];

    }//end getFileStats()


    /**
     * Process extracted files and index their chunks
     *
     * This method retrieves files from the file_texts table, chunks them,
     * and indexes the chunks to SOLR file collection.
     *
     * @param int|null $limit   Maximum number of files to process (null = no limit)
     * @param array    $options Chunking options (chunk_size, chunk_overlap, strategy)
     *
     * @return (((mixed|string)[]|float|int|mixed)[]|true)[] Processing result with statistics
     *
     * @throws \Exception If fileCollection is not configured
     *
     * @psalm-return array{
     *     success: true,
     *     stats: array{
     *         processed: 0|1|2,
     *         indexed: 0|1|2,
     *         failed: int,
     *         total_chunks: 0|mixed,
     *         errors: array<int, mixed|string>,
     *         execution_time_ms: float
     *     }
     * }
     */
    public function processExtractedFiles(?int $limit=null, array $options=[]): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        $this->logger->info(
                'Starting bulk file chunking and indexing',
                [
                    'limit'      => $limit,
                    'collection' => $collection,
                ]
                );

        $startTime = microtime(true);
        $stats     = [
            'processed'    => 0,
            'indexed'      => 0,
            'failed'       => 0,
            'total_chunks' => 0,
            'errors'       => [],
        ];

        // Get chunks for files that haven't been indexed yet.
        // TextExtractionService works with chunks, so we get chunks directly.
        // TODO: Update to use chunk-based approach with TextExtractionService.
        // For now, this method needs to be refactored to work with chunks.
        $fileTexts = [];

        foreach ($fileTexts as $fileText) {
            try {
                $stats['processed']++;

                // Get the text content.
                $text = $fileText->getTextContent();
                if ($text === '' || $text === null) {
                    $this->logger->warning(
                            'Empty text content for file',
                            [
                                'file_id' => $fileText->getFileId(),
                            ]
                            );
                    continue;
                }

                // Chunk the text.
                $chunks = $this->chunkDocument(
                        $text,
                        array_merge(
                        $options,
                        [
                            'file_type' => $fileText->getMimeType(),
                        ]
                        )
                        );

                if ($chunks === []) {
                    $this->logger->warning(
                            'No chunks produced for file',
                            [
                                'file_id' => $fileText->getFileId(),
                            ]
                            );
                    continue;
                }

                // Prepare metadata.
                $metadata = [
                    'file_id'      => $fileText->getFileId(),
                    'file_path'    => $fileText->getFilePath(),
                    'mime_type'    => $fileText->getMimeType(),
                    'size'         => $fileText->getSize(),
                    'extracted_at' => $fileText->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                ];

                // Index the chunks.
                $result = $this->indexFileChunks(
                    (string) $fileText->getFileId(),
                    $chunks,
                    $metadata
                );

                if ($result['success'] === true) {
                    $stats['indexed']++;
                    $stats['total_chunks'] += $result['indexed'] ?? 0;

                    $this->logger->info(
                            'Successfully indexed file chunks',
                            [
                                'file_id' => $fileText->getFileId(),
                                'chunks'  => $result['indexed'] ?? 0,
                            ]
                            );
                } else {
                    $stats['failed']++;
                    $stats['errors'][$fileText->getFileId()] = $result['error'] ?? ($result['message'] ?? 'Unknown error');
                }//end if
            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][$fileText->getFileId()] = $e->getMessage();

                $this->logger->error(
                        'Failed to process file for chunking',
                        [
                            'file_id' => $fileText->getFileId(),
                            'error'   => $e->getMessage(),
                        ]
                        );
            }//end try
        }//end foreach

        $stats['execution_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Completed bulk file chunking',
                [
                    'stats' => $stats,
                ]
                );

        return [
            'success' => true,
            'stats'   => $stats,
        ];

    }//end processExtractedFiles()


    /**
     * Process and index a single extracted file
     *
     * @param int   $fileId  The file ID from the file_texts table
     * @param array $options Chunking options
     *
     * @return array Processing result
     *
     * @throws \Exception If fileCollection is not configured or file not found
     */
    public function processExtractedFile(int $fileId, array $options=[]): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            throw new \Exception('fileCollection not configured in SOLR settings');
        }

        // Extract chunking options if provided.
        $chunkSize    = $options['chunk_size'] ?? null;
        $chunkOverlap = $options['chunk_overlap'] ?? null;

        $this->logger->info(
                'Processing single extracted file',
                [
                    'file_id'       => $fileId,
                    'collection'    => $collection,
                    'chunk_size'    => $chunkSize,
                    'chunk_overlap' => $chunkOverlap,
                ]
                );

        // Extract file using TextExtractionService if not already extracted.
        // This will create chunks automatically.
        try {
            $this->getTextExtractionService()->extractFile($fileId, false);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to extract file: '.$e->getMessage(),
            ];
        }

        // Get chunks for this file.
        $chunks = $this->chunkMapper->findBySource('file', $fileId);
        if ($chunks === []) {
            return [
                'success' => false,
                'message' => 'No chunks found for file',
            ];
        }

        // Get file metadata from Nextcloud.
        $fileMapper = $this->container->get(FileMapper::class);
        $ncFile     = $fileMapper->getFile($fileId);

        // Prepare metadata.
        $metadata = [
            'file_id'      => $fileId,
            'file_path'    => $ncFile['path'] ?? '',
            'mime_type'    => $ncFile['mimetype'] ?? '',
            'size'         => $ncFile['size'] ?? 0,
            'extracted_at' => date('Y-m-d\TH:i:s\Z'),
        ];

        // Convert chunks to format expected by indexFileChunks.
        $chunkData = [];
        foreach ($chunks as $chunk) {
            $chunkData[] = [
                'text'         => $chunk->getTextContent(),
                'start_offset' => $chunk->getStartOffset(),
                'end_offset'   => $chunk->getEndOffset(),
            ];
        }

        // Index the chunks.
        $result = $this->indexFileChunks(
            (string) $fileId,
            $chunkData,
            $metadata
        );

        return $result;

    }//end processExtractedFile()


    /**
     * Get chunking statistics
     *
     * Returns statistics about how many files have been chunked and indexed
     *
     * @return (bool|int|mixed|string)[] Statistics
     *
     * @psalm-return array{
     *     available: bool,
     *     collection?: string,
     *     total_extracted?: 0|mixed,
     *     total_chunks_indexed?: 0|mixed,
     *     unique_files_indexed?: 0|mixed,
     *     pending_indexing?: 0|mixed,
     *     error?: 'fileCollection not configured'
     * }
     */
    public function getChunkingStats(): array
    {
        $collection = $this->getFileCollection();

        if ($collection === null) {
            return [
                'available' => false,
                'error'     => 'fileCollection not configured',
            ];
        }

        // Get total extracted files using TextExtractionService stats.
        $extractionStats = $this->getTextExtractionService()->getStats();

        // Get total chunks in SOLR.
        $fileStats = $this->getFileStats();

        return [
            'available'            => true,
            'collection'           => $collection,
            'total_extracted'      => $extractionStats['totalFiles'] ?? 0,
            'total_chunks_indexed' => $fileStats['document_count'] ?? 0,
            'unique_files_indexed' => $fileStats['indexed_files'] ?? 0,
            'pending_indexing'     => max(0, ($extractionStats['totalFiles'] ?? 0) - ($fileStats['indexed_files'] ?? 0)),
        ];

    }//end getChunkingStats()


}//end class
