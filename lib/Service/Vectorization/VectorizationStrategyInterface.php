<?php
/**
 * Vectorization Strategy Interface
 *
 * Defines contract for entity-specific vectorization logic.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Vectorization;

/**
 * VectorizationStrategyInterface
 *
 * Interface for implementing entity-specific vectorization strategies.
 * Each strategy handles: fetching, text extraction, and metadata preparation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
interface VectorizationStrategyInterface
{
    /**
     * Fetch entities to vectorize based on options
     *
     * Examples:
     * - Objects: fetch by views, schemas
     * - Files: fetch by status='completed', file types
     *
     * @param array $options Strategy-specific options
     *
     * @return array Array of entities to vectorize
     */
    public function fetchEntities(array $options): array;

    /**
     * Extract vectorization items from an entity
     *
     * An entity may produce multiple items to vectorize:
     * - Object: typically 1 item (serialized object data)
     * - File: N items (one per chunk)
     *
     * Each item must have:
     * - 'text': string to vectorize
     * - additional data needed for metadata
     *
     * @param mixed $entity Entity to extract items from
     *
     * @return array Array of items, each with 'text' and other data
     */
    public function extractVectorizationItems($entity): array;

    /**
     * Prepare metadata for vector storage
     *
     * Returns metadata needed for VectorEmbeddingService.storeVector():
     * - entity_type: string
     * - entity_id: string
     * - chunk_index: int (optional, default 0)
     * - total_chunks: int (optional, default 1)
     * - chunk_text: string|null (optional, preview text)
     * - additional_metadata: array (optional, extra data)
     *
     * @param mixed $entity Original entity
     * @param array $item   Vectorization item (from extractVectorizationItems)
     *
     * @return array Metadata for vector storage
     */
    public function prepareVectorMetadata($entity, array $item): array;

    /**
     * Get a unique identifier for an entity (for logging/errors)
     *
     * @param mixed $entity Entity
     *
     * @return string|int Identifier
     */
    public function getEntityIdentifier($entity);
}

