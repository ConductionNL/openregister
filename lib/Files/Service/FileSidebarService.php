<?php

/**
 * FileSidebarService
 *
 * Provides data for the Nextcloud Files sidebar tabs. Supports looking up
 * OpenRegister objects that reference a given file ID and aggregating
 * extraction / entity-recognition metadata for the Extraction tab.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
class FileSidebarService {

	/**
	 * The extraction status of a file that has no chunks, after its fileId.
	 *
	 * @var array<string, mixed>
	 */
	private const NOT_EXTRACTED = [
		'extractionStatus' => 'none',
		'chunkCount' => 0,
		'entityCount' => 0,
		'riskLevel' => 'none',
		'extractedAt' => null,
		'entities' => [],
		'anonymized' => false,
		'anonymizedAt' => null,
		'anonymizedFileId' => null,
	];
	/**
	 * Register mapper for RBAC-aware register lookups.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper for schema lookups.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Database connection for magic table queries.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Chunk mapper for extraction data.
	 *
	 * @var ChunkSourceReaderInterface
	 */
	private readonly ChunkSourceReaderInterface $chunkMapper;

	/**
	 * Entity relation mapper for PII data.
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * GDPR entity mapper for entity type lookups.
	 *
	 * @var GdprEntityMapperInterface
	 */
	private readonly GdprEntityMapperInterface $gdprEntityMapper;

	/**
	 * Risk level computation service.
	 *
	 * @var RiskLevelService
	 */
	private readonly RiskLevelService $riskLevelService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param FileSidebarSchemaLookup $schemas          The register and schema mappers
	 * @param FileSidebarEntityStore  $entities         The database, chunk, entity relation and GDPR entity mappers
	 * @param RiskLevelService        $riskLevelService Risk level computation service.
	 * @param LoggerInterface         $logger           Logger.
	 */
	public function __construct(
		FileSidebarSchemaLookup $schemas,
		FileSidebarEntityStore $entities,
		RiskLevelService $riskLevelService,
		LoggerInterface $logger,
	) {
		$this->registerMapper = $schemas->registerMapper;
		$this->schemaMapper = $schemas->schemaMapper;
		$this->db = $entities->db;
		$this->chunkMapper = $entities->chunkMapper;
		$this->entityRelationMapper = $entities->entityRelationMapper;
		$this->gdprEntityMapper = $entities->gdprEntityMapper;
		$this->riskLevelService = $riskLevelService;
		$this->logger = $logger;
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
	 * @return array<int, array{
	 *     uuid: string,
	 *     title: string,
	 *     register: array{id: int, title: string},
	 *     schema: array{id: int, title: string}
	 * }>
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function getObjectsForFile(int $fileId): array {
		$results = [];

		try {
			// FindAll respects RBAC — only registers the user can access.
			$registers = $this->registerMapper->findAll();
		} catch (\Exception $e) {
			$this->logger->warning(
				'[FileSidebarService] Failed to fetch registers: ' . $e->getMessage()
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
					$schema = $this->schemaMapper->find((int)$schemaId);
				} catch (\Exception $e) {
					continue;
				}

				$tableName = 'openregister_table_' . $register->getId() . '_' . $schema->getId();

				// Check if the table exists before querying.
				if ($this->db->tableExists($tableName) === false) {
					continue;
				}

				$found = $this->searchTableForFileId(tableName: $tableName, fileId: $fileId);
				foreach ($found as $row) {
					$results[] = [
						'uuid' => $row['uuid'] ?? ($row['id'] ?? ''),
						'title' => $this->extractTitle(row: $row),
						'register' => [
							'id' => $register->getId(),
							'title' => $register->getTitle() ?? 'Register ' . $register->getId(),
						],
						'schema' => [
							'id' => $schema->getId(),
							'title' => $schema->getTitle() ?? 'Schema ' . $schema->getId(),
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
	 * @param int $fileId The file ID to search for.
	 *
	 * @return array<int, array<string, mixed>> Matching rows.
	 */
	private function searchTableForFileId(string $tableName, int $fileId): array {
		try {
			// Get column names for the table to search all data columns.
			$schemaManager = $this->db->getInner()->createSchemaManager();
			$columns = $schemaManager->listTableColumns($tableName);

			// System columns that should not be searched for file references.
			$systemColumns = [
				'id',
				'uuid',
				'register',
				'schema',
				'object',
				'created',
				'updated',
				'owner',
				'organisation',
				'authorization',
				'version',
				'status',
				'folder',
				'textContent',
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

			$fileIdStr = (string)$fileId;
			$orConds = [];
			foreach ($searchColumns as $colName) {
				$orConds[] = $qb->expr()->eq(
					$colName,
					$qb->createNamedParameter($fileIdStr)
				);
			}

			$qb->where($qb->expr()->orX(...$orConds));

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			return $rows;
		} catch (\Exception $e) {
			$this->logger->debug(
				'[FileSidebarService] Error searching table ' . $tableName . ': ' . $e->getMessage()
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
	private function extractTitle(array $row): string {
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
			return (string)$row['uuid'];
		}

		return 'Object ' . (string)($row['id'] ?? 'unknown');
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
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function getExtractionStatus(int $fileId): array {
		// Get chunks for this file.
		$chunkCount = count($this->chunkMapper->findBySource('file', $fileId));

		// If no chunks exist, this file has not been extracted.
		if ($chunkCount === 0) {
			return ['fileId' => $fileId] + self::NOT_EXTRACTED;
		}

		// Extraction timestamp from the chunk mapper, then the file's entity relations.
		$extractedAt     = $this->extractedAt(fileId: $fileId);
		$entityRelations = $this->entityRelationMapper->findByFileId($fileId);
		$entities        = $this->entitiesByType(entityRelations: $entityRelations);

		return [
			'fileId' => $fileId, 'extractionStatus' => 'completed', 'chunkCount' => $chunkCount, 'entityCount' => count($entityRelations),
			'riskLevel' => $this->riskLevelService->getRiskLevel($fileId), 'extractedAt' => $extractedAt, 'entities' => $entities,
			'anonymized' => $this->anyAnonymized(entityRelations: $entityRelations), 'anonymizedAt' => null, 'anonymizedFileId' => null,
		];
	}//end getExtractionStatus()

	/**
	 * When the file's chunks were last updated, as ISO 8601, or null.
	 *
	 * @param int $fileId The Nextcloud file ID.
	 *
	 * @return string|null
	 */
	private function extractedAt(int $fileId): ?string {
		$timestamp = $this->chunkMapper->getLatestUpdatedTimestamp('file', $fileId);
		if ($timestamp === null) {
			return null;
		}

		return date('c', $timestamp);
	}//end extractedAt()

	/**
	 * Count the file's entities by their GDPR entity type; a relation whose entity is gone is skipped.
	 *
	 * @param array $entityRelations The file's entity relations.
	 *
	 * @return array<int, array{type: string, count: int}>
	 */
	private function entitiesByType(array $entityRelations): array {
		$entityTypeCounts = [];
		foreach ($entityRelations as $relation) {
			// Look up entity type from GdprEntity.
			try {
				$type = $this->gdprEntityMapper->find($relation->getEntityId())->getType();
				$entityTypeCounts[$type] = ($entityTypeCounts[$type] ?? 0) + 1;
			} catch (\Exception $e) {
				// Entity not found — skip.
				continue;
			}
		}

		$entities = [];
		foreach ($entityTypeCounts as $type => $count) {
			$entities[] = ['type' => $type, 'count' => $count];
		}

		return $entities;
	}//end entitiesByType()

	/**
	 * Whether any of the file's entity relations is anonymized.
	 *
	 * @param array $entityRelations The file's entity relations.
	 *
	 * @return bool
	 */
	private function anyAnonymized(array $entityRelations): bool {
		foreach ($entityRelations as $relation) {
			if ($relation->getAnonymized() === true) {
				return true;
			}
		}

		return false;
	}//end anyAnonymized()
}//end class
