<?php

/**
 * ObjectHandler
 *
 * Handles object indexing and search in Solr/Elasticsearch.
 * Reads objects from database and indexes them - does NOT extract text or vectorize.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * ObjectHandler
 *
 * Indexes objects to search backend (Solr/Elastic).
 *
 * ARCHITECTURE:
 * - TextExtractionService extracts text from objects (separate flow with listeners).
 * - VectorizationService vectorizes objects (separate flow with listeners).
 * - ObjectHandler reads objects from database and indexes them to Solr/Elastic.
 * - Does NOT extract text or vectorize - only indexes existing data.
 *
 * RESPONSIBILITIES:
 * - Read objects from database (ObjectEntityMapper).
 * - Index objects to Solr objectCollection.
 * - Search objects in Solr.
 * - Commit changes to Solr.
 * - Keep Solr index in sync with database objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index
 */
class ObjectHandler
{
    /**
     * Constructor
     *
     * @param SchemaMapper           $schemaMapper   Schema mapper
     * @param RegisterMapper         $registerMapper Register mapper
     * @param LoggerInterface        $logger         Logger
     * @param SearchBackendInterface $searchBackend  Search backend
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
        private readonly SearchBackendInterface $searchBackend
    ) {
    }//end __construct()

    /**
     * Search objects in Solr.
     *
     * @param array $query        Search query parameters
     * @param bool  $rbac         Apply RBAC filters
     * @param bool  $multitenancy Apply multitenancy filters
     * @param bool  $published    Filter published objects
     * @param bool  $deleted      Include deleted objects
     *
     * @return (array|int|mixed)[] Search results in OpenRegister format
     *
     * @throws Exception If objectCollection is not configured
     *
     * @psalm-return array{results: array<never, never>|mixed, total: 0|mixed, start: 0|mixed}
     */
    public function searchObjects(
        array $query=[],
        bool $rbac=true,
        bool $multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array {
        $this->logger->debug(
            '[ObjectHandler] Searching objects',
            [
                'query'        => $query,
                'rbac'         => $rbac,
                'multitenancy' => $multitenancy,
            ]
        );

        // Build Solr query from OpenRegister query.
        $solrQuery = $this->buildSolrQuery(
            query: $query,
            rbac: $rbac,
            multitenancy: $multitenancy,
            published: $published,
            deleted: $deleted
        );

        // Execute search via backend (backend handles collection selection).
        $results = $this->searchBackend->search($solrQuery);

        // Convert Solr results to OpenRegister format.
        return $this->convertToOpenRegisterFormat($results);
    }//end searchObjects()

    /**
     * Build Solr query from OpenRegister query parameters.
     *
     * @param array $query        OpenRegister query
     * @param bool  $rbac         Apply RBAC filters
     * @param bool  $multitenancy Apply multitenancy filters
     * @param bool  $published    Filter published objects
     * @param bool  $deleted      Include deleted objects
     *
     * @return (int|mixed|string[])[] Solr query parameters
     *
     * @psalm-return array{q: '*:*'|mixed, start: 0|mixed, rows: 10|mixed,
     *     fq?: list{0: '-deleted:true'|'published:true', 1?: '-deleted:true'}}
     */
    private function buildSolrQuery(array $query, bool $rbac, bool $multitenancy, bool $published, bool $deleted): array
    {
        $solrQuery = [
            'q'     => $query['q'] ?? '*:*',
            'start' => $query['start'] ?? 0,
            'rows'  => $query['rows'] ?? 10,
        ];

        // Add filters.
        $filters = [];

        if ($rbac === true) {
            // TODO: Add RBAC filters based on current user.
        }

        if ($multitenancy === true) {
            // TODO: Add multitenancy filters based on current organisation.
        }

        if ($published === true) {
            $filters[] = 'published:true';
        }

        if ($deleted === false) {
            $filters[] = '-deleted:true';
        }

        if ($filters !== []) {
            $solrQuery['fq'] = $filters;
        }

        return $solrQuery;
    }//end buildSolrQuery()

    /**
     * Convert Solr response to OpenRegister format.
     *
     * @param array $solrResults Solr search results
     *
     * @return (array|int|mixed)[] OpenRegister formatted results
     *
     * @psalm-return array{results: array<never, never>|mixed, total: 0|mixed, start: 0|mixed}
     */
    private function convertToOpenRegisterFormat(array $solrResults): array
    {
        $response = $solrResults['response'] ?? [];
        $docs     = $response['docs'] ?? [];

        return [
            'results' => $docs,
            'total'   => $response['numFound'] ?? 0,
            'start'   => $response['start'] ?? 0,
        ];
    }//end convertToOpenRegisterFormat()

    /**
     * Commit changes to Solr.
     *
     * Forces Solr to commit pending changes to make them searchable.
     *
     * @return bool True if commit succeeded
     *
     * @throws Exception If objectCollection is not configured
     */
    public function commit(): bool
    {
        $this->logger->debug('[ObjectHandler] Committing to Solr');

        try {
            // Use search backend to commit (backend handles collection selection).
            $result = $this->searchBackend->commit();

            if ($result === true) {
                $this->logger->info('[ObjectHandler] Successfully committed to Solr');
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                '[ObjectHandler] Failed to commit to Solr',
                [
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end commit()

    /**
     * Reindex all objects in the system.
     *
     * This delegates to the underlying search backend's reindexAll method.
     *
     * @param int         $maxObjects     Maximum objects to reindex (0 = all).
     * @param int         $batchSize      Batch size for reindexing.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Reindexing results with statistics.
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array
    {
        $this->logger->info(
            '[ObjectHandler] Starting full reindex',
            [
                'maxObjects' => $maxObjects,
                'batchSize'  => $batchSize,
                'collection' => $collectionName,
            ]
        );

        try {
            // Delegate to search backend.
            return $this->searchBackend->reindexAll($maxObjects, $batchSize, $collectionName);
        } catch (Exception $e) {
            $this->logger->error(
                '[ObjectHandler] Reindex failed',
                [
                    'error' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try
    }//end reindexAll()
}//end class
