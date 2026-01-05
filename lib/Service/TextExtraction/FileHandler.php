<?php

/**
 * File Text Extraction Handler
 *
 * Handles text extraction from Nextcloud files.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Handler for extracting text from Nextcloud files.
 */
class FileHandler implements TextExtractionHandlerInterface
{
    /**
     * Constructor.
     *
     * @param FileMapper      $fileMapper  File mapper.
     * @param ChunkMapper     $chunkMapper Chunk mapper.
     * @param IRootFolder     $rootFolder  Nextcloud root folder.
     * @param LoggerInterface $logger      Logger.
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly ChunkMapper $chunkMapper,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get the source type this handler supports.
     *
     * @return string Source type identifier.
     *
     * @psalm-return 'file'
     */
    public function getSourceType(): string
    {
        return 'file';
    }//end getSourceType()

    /**
     * Extract text from a file.
     *
     * @param int                  $sourceId   File ID.
     * @param array<string, mixed> $sourceMeta File metadata.
     * @param bool                 $force      Force re-extraction.
     *
     * @return array{
     *     source_type: string,
     *     source_id: int,
     *     text: string,
     *     length: int,
     *     checksum: string,
     *     method: string,
     *     owner: string|null,
     *     organisation: string|null,
     *     language: string|null,
     *     language_level: string|null,
     *     language_confidence: float|null,
     *     detection_method: string|null,
     *     metadata: array<string, mixed>
     * }
     *
     * @throws Exception When extraction fails.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Force parameter follows interface contract
     */
    public function extractText(int $sourceId, array $sourceMeta, bool $force=false): array
    {
        $this->logger->info(message: '[FileHandler] Extracting text from file', context: ['fileId' => $sourceId]);

        // Get file node from Nextcloud.
        $files = $this->rootFolder->getById($sourceId);
        if (empty($files) === true) {
            throw new Exception("File with ID {$sourceId} not found");
        }

        $file = $files[0];
        if (($file instanceof \OCP\Files\File) === false) {
            throw new Exception("File with ID {$sourceId} is not a file");
        }

        // Extract text based on MIME type.
        $mimeType = $file->getMimeType();
        $text     = $this->performTextExtraction(file: $file, mimeType: $mimeType);

        if ($text === null || trim($text) === '') {
            throw new Exception("No text extracted from file {$sourceId}");
        }

        // Calculate checksum.
        $checksum = hash('sha256', $text);

        // Detect language (simplified - can be enhanced).
        $language = $this->detectLanguage($text);

        return [
            'source_type'         => 'file',
            'source_id'           => $sourceId,
            'text'                => $text,
            'length'              => strlen($text),
            'checksum'            => $checksum,
            'method'              => 'file_extraction',
            'owner'               => $sourceMeta['owner'] ?? null,
            'organisation'        => $sourceMeta['organisation'] ?? null,
            'language'            => $language['language'] ?? null,
            'language_level'      => $language['level'] ?? null,
            'language_confidence' => $language['confidence'] ?? null,
            'detection_method'    => $language['method'] ?? null,
            'metadata'            => [
                'file_path' => $file->getPath(),
                'file_name' => $file->getName(),
                'mime_type' => $mimeType,
                'file_size' => $file->getSize(),
            ],
        ];
    }//end extractText()

    /**
     * Check if file needs extraction.
     *
     * @param int  $sourceId        File ID.
     * @param int  $sourceTimestamp File modification timestamp.
     * @param bool $force           Force flag.
     *
     * @return bool True if extraction is needed.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force parameter follows interface contract
     */
    public function needsExtraction(int $sourceId, int $sourceTimestamp, bool $force): bool
    {
        if ($force === true) {
            return true;
        }

        // Check if chunks exist and are up-to-date.
        $latestChunkTimestamp = $this->chunkMapper->getLatestUpdatedTimestamp(sourceType: 'file', sourceId: $sourceId);

        if ($latestChunkTimestamp === null) {
            return true;
        }

        return $latestChunkTimestamp < $sourceTimestamp;
    }//end needsExtraction()

    /**
     * Get file metadata.
     *
     * @param int $sourceId File ID.
     *
     * @return (int|null|string)[] File metadata.
     *
     * @throws DoesNotExistException If file not found.
     *
     * @psalm-return array{fileid: int, storage: int, path: string,
     *     path_hash: string, parent: int, name: string, mimetype: string,
     *     mimepart: string, size: int, mtime: int, storage_mtime: int,
     *     encrypted: int, unencrypted_size: int, etag: string,
     *     permissions: int, checksum: string, share_token: null|string,
     *     share_stime: int|null, storage_id: null|string, owner: null|string,
     *     accessUrl: null|string, downloadUrl: null|string, published: null|string}
     */
    public function getSourceMetadata(int $sourceId): array
    {
        $ncFile = $this->fileMapper->getFile($sourceId);
        if ($ncFile === null) {
            throw new DoesNotExistException("File with ID {$sourceId} not found");
        }

        return $ncFile;
    }//end getSourceMetadata()

    /**
     * Get file modification timestamp.
     *
     * @param int $sourceId File ID.
     *
     * @return int Unix timestamp.
     */
    public function getSourceTimestamp(int $sourceId): int
    {
        try {
            $ncFile = $this->getSourceMetadata($sourceId);
            return (int) ($ncFile['mtime'] ?? time());
        } catch (DoesNotExistException $e) {
            return time();
        }
    }//end getSourceTimestamp()

    /**
     * Perform text extraction from file based on MIME type.
     *
     * @param \OCP\Files\File $file     File node.
     * @param string          $mimeType MIME type.
     *
     * @return string|null Extracted text or null if extraction failed.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple MIME type handling conditions
     */
    private function performTextExtraction(\OCP\Files\File $file, string $mimeType): ?string
    {
        try {
            // This is a simplified version - the actual extraction logic
            // Should be moved from TextExtractionService here.
            // For now, delegate to existing extraction methods.
            $content = $file->getContent();

            if ($mimeType === 'text/plain' || str_starts_with($mimeType, 'text/') === true) {
                return $content;
            }

            // For other types, we'd need to use the extraction methods
            // From TextExtractionService (PDF, DOCX, etc.).
            // This should be refactored to use IndexService if needed.
            $this->logger->warning(
                '[FileHandler] Complex extraction not yet implemented',
                [
                    'mime_type' => $mimeType,
                ]
            );

            return null;
        } catch (Exception $e) {
            $this->logger->error(
                '[FileHandler] Text extraction failed',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end performTextExtraction()

    /**
     * Detect language from text.
     *
     * @param string $_text Text to analyze.
     *
     * @return null[]
     *
     * @psalm-return array{language: null, level: null, confidence: null, method: null}
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function detectLanguage(string $_text): array
    {
        // Simplified language detection - can be enhanced with proper library.
        return [
            'language'   => null,
            'level'      => null,
            'confidence' => null,
            'method'     => null,
        ];
    }//end detectLanguage()
}//end class
