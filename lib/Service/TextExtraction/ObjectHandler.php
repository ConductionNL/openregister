<?php

/**
 * Object Text Extraction Handler
 *
 * Handles text extraction from OpenRegister objects.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Handler for extracting text from OpenRegister objects.
 */
class ObjectHandler implements TextExtractionHandlerInterface
{


    /**
     * Constructor.
     *
     * @param ObjectMapper    $objectMapper   Object mapper.
     * @param ChunkMapper     $chunkMapper    Chunk mapper.
     * @param SchemaMapper    $schemaMapper   Schema mapper.
     * @param RegisterMapper  $registerMapper Register mapper.
     * @param LoggerInterface $logger         Logger.
     */
    public function __construct(
        private readonly ObjectMapper $objectMapper,
        private readonly ChunkMapper $chunkMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Get the source type this handler supports.
     *
     * @return string Source type identifier.
     */
    public function getSourceType(): string
    {
        return 'object';

    }//end getSourceType()


    /**
     * Extract text from an object.
     *
     * @param int                  $sourceId   Object ID.
     * @param array<string, mixed> $sourceMeta Object metadata.
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
     */
    public function extractText(int $sourceId, array $sourceMeta, bool $force=false): array
    {
        $this->logger->info('[ObjectHandler] Extracting text from object', ['objectId' => $sourceId]);

        // Get object entity.
        $object = $this->objectMapper->find($sourceId);

        // Convert object to text.
        $textParts = [];

        // Add object UUID and version.
        $textParts[] = "Object ID: ".$object->getUuid();
        if ($object->getVersion() !== null) {
            $textParts[] = "Version: ".$object->getVersion();
        }

        // Add schema information.
        try {
            if ($object->getSchema() !== null) {
                $schema      = $this->schemaMapper->find($object->getSchema());
                $textParts[] = "Type: ".($schema->getTitle() ?? $schema->getName() ?? 'Unknown');
                if ($schema->getDescription() !== null && $schema->getDescription() !== '') {
                    $textParts[] = "Schema Description: ".$schema->getDescription();
                }
            }
        } catch (Exception $e) {
            $this->logger->debug(
                    '[ObjectHandler] Could not load schema',
                    [
                        'object_id' => $sourceId,
                        'schema_id' => $object->getSchema(),
                    ]
                    );
        }

        // Add register information.
        try {
            if ($object->getRegister() !== null) {
                $register    = $this->registerMapper->find($object->getRegister());
                $textParts[] = "Register: ".($register->getTitle() ?? $register->getName() ?? 'Unknown');
                if ($register->getDescription() !== null && $register->getDescription() !== '') {
                    $textParts[] = "Register Description: ".$register->getDescription();
                }
            }
        } catch (Exception $e) {
            $this->logger->debug(
                    '[ObjectHandler] Could not load register',
                    [
                        'object_id'   => $sourceId,
                        'register_id' => $object->getRegister(),
                    ]
                    );
        }

        // Extract text from object data.
        $objectData = $object->getObject();
        if (is_array($objectData) === true) {
            $extractedText = $this->extractTextFromArray($objectData);
            if (empty($extractedText) === false) {
                $textParts[] = "Content: ".$extractedText;
            }
        }

        // Add organization.
        if ($object->getOrganization() !== null && $object->getOrganization() !== '') {
            $textParts[] = "Organization: ".$object->getOrganization();
        }

        // Join all parts.
        $text = implode("\n", $textParts);

        if (trim($text) === '') {
            throw new Exception("No text extracted from object {$sourceId}");
        }

        // Calculate checksum.
        $checksum = hash('sha256', $text);

        return [
            'source_type'         => 'object',
            'source_id'           => $sourceId,
            'text'                => $text,
            'length'              => strlen($text),
            'checksum'            => $checksum,
            'method'              => 'object_extraction',
            'owner'               => $object->getOwner() ?? null,
            'organisation'        => $object->getOrganization() ?? null,
            'language'            => null,
            'language_level'      => null,
            'language_confidence' => null,
            'detection_method'    => null,
            'metadata'            => [
                'uuid'        => $object->getUuid(),
                'schema_id'   => $object->getSchema(),
                'register_id' => $object->getRegister(),
                'version'     => $object->getVersion(),
            ],
        ];

    }//end extractText()


    /**
     * Check if object needs extraction.
     *
     * @param int  $sourceId        Object ID.
     * @param int  $sourceTimestamp Object modification timestamp.
     * @param bool $force           Force flag.
     *
     * @return bool True if extraction is needed.
     */
    public function needsExtraction(int $sourceId, int $sourceTimestamp, bool $force): bool
    {
        if ($force === true) {
            return true;
        }

        // Check if chunks exist and are up-to-date.
        $latestChunkTimestamp = $this->chunkMapper->getLatestUpdatedTimestamp('object', $sourceId);

        if ($latestChunkTimestamp === null) {
            return true;
        }

        return $latestChunkTimestamp < $sourceTimestamp;

    }//end needsExtraction()


    /**
     * Get object metadata.
     *
     * @param int $sourceId Object ID.
     *
     * @return array<string, mixed> Object metadata.
     *
     * @throws DoesNotExistException If object not found.
     */
    public function getSourceMetadata(int $sourceId): array
    {
        $object = $this->objectMapper->find($sourceId);

        return [
            'id'           => $object->getId(),
            'uuid'         => $object->getUuid(),
            'schema'       => $object->getSchema(),
            'register'     => $object->getRegister(),
            'version'      => $object->getVersion(),
            'organization' => $object->getOrganization(),
            'owner'        => $object->getOwner(),
            'updated'      => $object->getUpdated(),
        ];

    }//end getSourceMetadata()


    /**
     * Get object modification timestamp.
     *
     * @param int $sourceId Object ID.
     *
     * @return int Unix timestamp.
     */
    public function getSourceTimestamp(int $sourceId): int
    {
        try {
            $object = $this->objectMapper->find($sourceId);
            return $object->getUpdated()?->getTimestamp() ?? time();
        } catch (DoesNotExistException $e) {
            return time();
        }

    }//end getSourceTimestamp()


    /**
     * Recursively extract text from nested arrays/objects.
     *
     * @param array  $data   Data to extract text from.
     * @param string $prefix Prefix for nested keys.
     * @param int    $depth  Current recursion depth.
     *
     * @return string Extracted text.
     */
    private function extractTextFromArray(array $data, string $prefix='', int $depth=0): string
    {
        // Prevent excessive recursion.
        if ($depth > 10) {
            return '';
        }

        $textParts = [];

        foreach ($data as $key => $value) {
            // Build context path.
            if ($prefix !== null && $prefix !== '') {
                $contextKey = "{$prefix}.{$key}";
            } else {
                $contextKey = (string) $key;
            }

            // Handle different value types.
            if (is_string($value) === true && trim($value) !== '' && trim($value) !== null) {
                $textParts[] = "{$contextKey}: {$value}";
            } else if (is_numeric($value) === true) {
                $textParts[] = "{$contextKey}: {$value}";
            } else if (is_bool($value) === true) {
                $boolStr     = $value === true ? 'true' : 'false';
                $textParts[] = "{$contextKey}: {$boolStr}";
            } else if (is_array($value) === true && empty($value) === false) {
                // Recursively process nested arrays.
                $nestedText = $this->extractTextFromArray($value, $contextKey, $depth + 1);
                if (empty($nestedText) === false) {
                    $textParts[] = $nestedText;
                }
            }//end if
        }//end foreach

        return implode("\n", $textParts);

    }//end extractTextFromArray()


}//end class
