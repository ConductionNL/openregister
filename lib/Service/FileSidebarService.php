<?php

/**
 * FileSidebarService
 *
 * Provides data for the Nextcloud Files sidebar tabs. Supports looking up
 * OpenRegister objects that reference a given file ID and aggregating
 * extraction / entity-recognition metadata for the Extraction tab.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for Files sidebar tab data retrieval.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FileSidebarService
{
    /**
     * Constructor.
     *
     * @param RegisterMapper       $registerMapper       Register mapper for RBAC-aware register lookups.
     * @param SchemaMapper         $schemaMapper         Schema mapper for schema lookups.
     * @param IDBConnection        $db                   Database connection for magic table queries.
     * @param ChunkMapper          $chunkMapper          Chunk mapper for extraction data.
     * @param EntityRelationMapper $entityRelationMapper Entity relation mapper for PII data.
     * @param GdprEntityMapper     $gdprEntityMapper     GDPR entity mapper for entity type lookups.
     * @param RiskLevelService     $riskLevelService     Risk level computation service.
     * @param LoggerInterface      $logger               Logger.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IDBConnection $db,
        private readonly ChunkMapper $chunkMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly GdprEntityMapper $gdprEntityMapper,
        private readonly RiskLevelService $riskLevelService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get all OpenRegister objects that reference a given Nextcloud file ID.
     *
     * Searches across all register/schema magic tables for objects containing
     * the file ID in any column. Results respect RBAC: only objects from
     * registers the current user has access to are returned.
     *
     * @param int $fileId The Nextcloud file ID to search for.
     *
     * @return array<int, array{uuid: string, title: string, register: array{id: int, title: string}, schema: array{id: int, title: string}}>
     */
    public function getObjectsForFile(int $fileId): array
    {
        $results = [];

        try {
            // findAll respects RBAC — only registers the user can access.
            $registers = $this->registerMapper->findAll();
        } catch (\Exception $e) {
            $this->logger->warning(
                '[FileSidebarService] Failed to fetch registers: '.$e->getMessage()
            );
            return [];
        }

        foreach ($registers as $register) {
            $schemaIds = $register->getSchemas();
            if (empty($schemaIds) === true) {
                continue;
            }

            foreach ($schemaIds as $schemaId) {
                try {
                    $schema = $this->schemaMapper->find((int) $schemaId);
                } catch (\Exception $e) {
                    continue;
                }

                $tableName = 'openregister_table_'.$register->getId().'_'.$schema->getId();

                // Check if the table exists before querying.
                if ($this->db->tableExists($tableName) === false) {
                    continue;
                }

                $found = $this->searchTableForFileId($tableName, $fileId);
                foreach ($found as $row) {
                    $results[] = [
                        'uuid'     => $row['uuid'] ?? ($row['id'] ?? ''),
                        'title'    => $this->extractTitle($row),
                        'register' => [
                            'id'    => $register->getId(),
                            'title' => $register->getTitle() ?? 'Register '.$register->getId(),
                        ],
                        'schema'   => [
                            'id'    => $schema->getId(),
                            'title' => $schema->getTitle() ?? 'Schema '.$schema->getId(),
                        ],
                    ];
                }
            }//end foreach
        }//end foreach

        return $results;
    }//end getObjectsForFile()

    /**
     * Search a specific magic table for rows containing a file ID.
     *
     * File IDs are stored as integer values in object columns. We search
     * all non-system columns for the file ID value.
     *
     * @param string $tableName The magic table name (without prefix).
     * @param int    $fileId    The file ID to search for.
     *
     * @return array<int, array<string, mixed>> Matching rows.
     */
    private function searchTableForFileId(string $tableName, int $fileId): array
    {
        try {
            // Get column names for the table to search all data columns.
            $schemaManager = $this->db->getInner()->createSchemaManager();
            $columns       = $schemaManager->listTableColumns($tableName);

            // System columns that should not be searched for file references.
            $systemColumns = [
                'id', 'uuid', 'register', 'schema', 'object', 'created',
                'updated', 'owner', 'organisation', 'authorization', 'version',
                'status', 'folder', 'textContent',
            ];

            $searchColumns = [];
            foreach ($columns as $column) {
                $colName = $column->getName();
                if (in_array($colName, $systemColumns, true) === false) {
                    $searchColumns[] = $colName;
                }
            }

            if (empty($searchColumns) === true) {
                return [];
            }

            // Build a query that searches for the file ID as a string value in any column.
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName);

            $fileIdStr = (string) $fileId;
            $orConds   = [];
            foreach ($searchColumns as $colName) {
                $orConds[] = $qb->expr()->eq(
                    $colName,
                    $qb->createNamedParameter($fileIdStr)
                );
            }

            $qb->where($qb->expr()->orX(...$orConds));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->debug(
                '[FileSidebarService] Error searching table '.$tableName.': '.$e->getMessage()
            );
            return [];
        }//end try
    }//end searchTableForFileId()

    /**
     * Extract a human-readable title from an object row.
     *
     * Uses the first non-empty string column value, falling back to UUID.
     *
     * @param array<string, mixed> $row The database row.
     *
     * @return string The extracted title.
     */
    private function extractTitle(array $row): string
    {
        // Common title-like column names to check first.
        $preferredColumns = ['title', 'name', 'label', 'subject', 'description'];

        foreach ($preferredColumns as $col) {
            if (isset($row[$col]) === true
                && is_string($row[$col]) === true
                && $row[$col] !== ''
            ) {
                return $row[$col];
            }
        }

        // Fall back to UUID.
        if (isset($row['uuid']) === true && $row['uuid'] !== '') {
            return (string) $row['uuid'];
        }

        return 'Object '.(string) ($row['id'] ?? 'unknown');
    }//end extractTitle()

    /**
     * Get extraction status and metadata for a file.
     *
     * Aggregates data from ChunkMapper (chunk count, extraction timestamp),
     * EntityRelationMapper (entity counts by type), and RiskLevelService
     * (risk level assessment).
     *
     * @param int $fileId The Nextcloud file ID.
     *
     * @return array{
     *   fileId: int,
     *   extractionStatus: string,
     *   chunkCount: int,
     *   entityCount: int,
     *   riskLevel: string,
     *   extractedAt: string|null,
     *   entities: array<int, array{type: string, count: int}>,
     *   anonymized: bool,
     *   anonymizedAt: string|null,
     *   anonymizedFileId: int|null
     * }
     */
    public function getExtractionStatus(int $fileId): array
    {
        // Get chunks for this file.
        $chunks     = $this->chunkMapper->findBySource('file', $fileId);
        $chunkCount = count($chunks);

        // If no chunks exist, this file has not been extracted.
        if ($chunkCount === 0) {
            return [
                'fileId'           => $fileId,
                'extractionStatus' => 'none',
                'chunkCount'       => 0,
                'entityCount'      => 0,
                'riskLevel'        => 'none',
                'extractedAt'      => null,
                'entities'         => [],
                'anonymized'       => false,
                'anonymizedAt'     => null,
                'anonymizedFileId' => null,
            ];
        }

        // Get extraction timestamp from chunk mapper.
        $timestamp   = $this->chunkMapper->getLatestUpdatedTimestamp('file', $fileId);
        $extractedAt = null;
        if ($timestamp !== null) {
            $extractedAt = date('c', $timestamp);
        }

        // Get entity relations for this file.
        $entityRelations = $this->entityRelationMapper->findByFileId($fileId);
        $entityCount     = count($entityRelations);

        // Aggregate entities by type.
        $entityTypeCounts = [];
        $anonymized       = false;
        foreach ($entityRelations as $relation) {
            // Check anonymization status.
            if ($relation->getAnonymized() === true) {
                $anonymized = true;
            }

            // Look up entity type from GdprEntity.
            try {
                $entity   = $this->gdprEntityMapper->find($relation->getEntityId());
                $type     = $entity->getType();
                if (isset($entityTypeCounts[$type]) === false) {
                    $entityTypeCounts[$type] = 0;
                }

                $entityTypeCounts[$type]++;
            } catch (\Exception $e) {
                // Entity not found — skip.
                continue;
            }
        }//end foreach

        // Build entity type array.
        $entities = [];
        foreach ($entityTypeCounts as $type => $count) {
            $entities[] = [
                'type'  => $type,
                'count' => $count,
            ];
        }

        // Get risk level.
        $riskLevel = $this->riskLevelService->getRiskLevel($fileId);

        return [
            'fileId'           => $fileId,
            'extractionStatus' => 'completed',
            'chunkCount'       => $chunkCount,
            'entityCount'      => $entityCount,
            'riskLevel'        => $riskLevel,
            'extractedAt'      => $extractedAt,
            'entities'         => $entities,
            'anonymized'       => $anonymized,
            'anonymizedAt'     => null,
            'anonymizedFileId' => null,
        ];
    }//end getExtractionStatus()
}//end class
