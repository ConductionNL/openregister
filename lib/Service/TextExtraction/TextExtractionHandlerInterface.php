<?php

/**
 * Text Extraction Handler Interface
 *
 * Interface for handlers that extract text from different source types.
 * This allows the TextExtractionService to be generic and extensible
 * for future source types (agenda, email, etc.).
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

/**
 * Interface for text extraction handlers.
 *
 * Each handler is responsible for extracting text from a specific source type
 * (files, objects, agenda items, emails, etc.).
 */
interface TextExtractionHandlerInterface
{


    /**
     * Get the source type this handler supports.
     *
     * @return string Source type identifier (e.g., 'file', 'object', 'agenda', 'email')
     */
    public function getSourceType(): string;


    /**
     * Extract text from a source.
     *
     * @param int                  $sourceId   Source identifier.
     * @param array<string, mixed> $sourceMeta Source metadata.
     * @param bool                 $force      Force re-extraction even if up-to-date.
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
     * @throws \Exception When extraction fails.
     */
    public function extractText(int $sourceId, array $sourceMeta, bool $force=false): array;


    /**
     * Check if source needs extraction or re-extraction.
     *
     * @param int  $sourceId        Source identifier.
     * @param int  $sourceTimestamp Source modification timestamp.
     * @param bool $force           Force flag.
     *
     * @return bool True if extraction is needed.
     */
    public function needsExtraction(int $sourceId, int $sourceTimestamp, bool $force): bool;


    /**
     * Get source metadata for a given source ID.
     *
     * @param int $sourceId Source identifier.
     *
     * @return array<string, mixed> Source metadata.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If source not found.
     */
    public function getSourceMetadata(int $sourceId): array;


    /**
     * Get the timestamp when the source was last modified.
     *
     * @param int $sourceId Source identifier.
     *
     * @return int Unix timestamp.
     */
    public function getSourceTimestamp(int $sourceId): int;


}//end interface
