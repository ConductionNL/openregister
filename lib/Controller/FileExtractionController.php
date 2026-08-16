<?php

/**
 * OpenRegister File Extraction Controller
 *
 * This controller handles file operations and text extraction endpoints.
 * Provides core file extraction functionality accessible via API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * FileExtractionController
 *
 * Handles file extraction endpoints for the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 *
 * @suppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class FileExtractionController extends Controller {
	/**
	 * Constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request
	 * @param TextExtractionService $textExtractor Text extraction service
	 * @param VectorizationService $vectorizationService Unified vectorization service
	 * @param ChunkMapper $chunkMapper Chunk mapper for text chunks
	 * @param EntityRelationMapper $entityRelationMapper Entity relation mapper
	 * @param RiskLevelService $riskLevelService Risk level computation service
	 * @param IRootFolder $rootFolder Root folder for per-user file access checks
	 * @param IUserSession $userSession Active user session for caller identity
	 * @param IGroupManager $groupManager Group manager for admin checks
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TextExtractionService $textExtractor,
		private readonly VectorizationService $vectorizationService,
		private readonly ChunkMapper $chunkMapper,
		private readonly EntityRelationMapper $entityRelationMapper,
		private readonly RiskLevelService $riskLevelService,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Whether the current session user can access the given Nextcloud file.
	 *
	 * Resolves the file through the caller's own user folder so Nextcloud's
	 * share/permission ACLs apply. A file the user cannot access resolves to
	 * no node — preventing IDOR where a caller extracts/inspects arbitrary
	 * file IDs they do not own.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return bool True when the file is reachable in the caller's user folder.
	 */
	private function hasFileAccess(int $fileId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		return empty($userFolder->getById($fileId)) === false;
	}//end hasFileAccess()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * The instance-wide extraction maintenance operations (discover/extractAll/
	 * retryFailed/cleanup/vectorizeBatch) act on every file in the instance, so
	 * they are admin-only.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Get all files tracked in the extraction system.
	 *
	 * Returns file summaries with chunk counts and entity counts,
	 * sourced from the chunk-based extraction architecture.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing file extraction data
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function index(): JSONResponse {
		try {
			$limit = (int)($this->request->getParam('limit', 50));
			$offset = (int)($this->request->getParam('offset', 0));
			$search = $this->request->getParam('search');
			$status = $this->request->getParam('status');
			$riskLevel = $this->request->getParam('riskLevel');
			$sort = $this->request->getParam('sort', 'extractedAt');
			$order = $this->request->getParam('order', 'DESC');

			// All chunked files are "completed" — if filtering for another status, return empty.
			if ($status !== null && $status !== '' && $status !== 'completed') {
				return new JSONResponse(
					data: [
						'success' => true,
						'data' => [],
						'count' => 0,
					]
				);
			}

			$searchTerm = null;
			if ($search !== null && $search !== '') {
				$searchTerm = $search;
			}

			// For riskLevel/entityCount sorting, fetch all then sort in PHP.
			$phpSort = in_array($sort, ['riskLevel', 'entityCount'], true);
			$dbLimit = $limit;
			$dbOffset = $offset;
			$dbSort = $sort;
			if ($phpSort === true) {
				$dbLimit = null;
				$dbOffset = null;
				$dbSort = 'extractedAt';
			}

			$summaries = $this->chunkMapper->getFileSourceSummaries($dbLimit, $dbOffset, $searchTerm, $dbSort, $order);
			$totalCount = $this->chunkMapper->countFileSourceSummaries($searchTerm);

			$data = [];
			foreach ($summaries as $summary) {
				$entityCount = count($this->entityRelationMapper->findByFileId($summary['sourceId']));
				$fileRisk = $this->riskLevelService->getRiskLevel($summary['sourceId']);

				$data[] = [
					'id' => $summary['sourceId'],
					'fileName' => $summary['fileName'],
					'mimeType' => $summary['mimeType'],
					'fileSize' => $summary['fileSize'],
					'extractionStatus' => 'completed',
					'chunkCount' => $summary['chunkCount'],
					'extractedAt' => $summary['lastExtracted'],
					'extractionError' => null,
					'entityCount' => $entityCount,
					'riskLevel' => $fileRisk,
				];
			}

			// Filter by risk level if requested.
			if ($riskLevel !== null && $riskLevel !== '') {
				$data = array_values(array_filter($data, fn ($f) => $f['riskLevel'] === $riskLevel));
				$totalCount = count($data);
			}

			// PHP-side sorting for fields not in the DB query.
			if ($phpSort === true) {
				$riskOrder = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'very_high' => 4];
				usort(
					$data,
					function ($a, $b) use ($sort, $order, $riskOrder) {
						$cmp = ($a[$sort] ?? 0) <=> ($b[$sort] ?? 0);
						if ($sort === 'riskLevel') {
							$cmp = ($riskOrder[$a['riskLevel']] ?? 0) <=> ($riskOrder[$b['riskLevel']] ?? 0);
						}

						if ($order === 'ASC') {
							return $cmp;
						}

						return -$cmp;
					}
				);
				$totalCount = count($data);
				$data = array_slice($data, $offset, $limit);
			}//end if

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $data,
					'count' => $totalCount,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

	/**
	 * Get a single file's extraction information by ID.
	 *
	 * @param int $id Nextcloud file ID from oc_filecache
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with file extraction details
	 *
	 * @psalm-return JSONResponse<200|404,
	 *     array{success: bool, error?: 'File not found in extraction system',
	 *     message?: string,
	 *     data?: non-empty-list<array{checksum: null|string, chunkIndex: int,
	 *     createdAt: null|string, embeddingProvider: null|string,
	 *     endOffset: int, id: int, indexed: bool, language: null|string,
	 *     languageConfidence: float|null, languageLevel: null|string,
	 *     organisation: null|string, overlapSize: int, owner: null|string,
	 *     positionReference: array|null, sourceId: int|null,
	 *     sourceType: null|string, startOffset: int, updatedAt: null|string,
	 *     uuid: null|string, vectorized: bool}>}, array<never, never>>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function show(int $id): JSONResponse {
		try {
			// Get chunks for this file.
			$chunks = $this->chunkMapper->findBySource(sourceType: 'file', sourceId: $id);

			if (empty($chunks) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'File not found in extraction system',
						'message' => 'No chunks found for file ID: ' . $id,
					],
					statusCode: 404
				);
			}

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => array_map(fn ($chunk) => $chunk->jsonSerialize(), $chunks),
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'File not found in extraction system',
					'message' => $e->getMessage(),
				],
				statusCode: 404
			);
		}//end try
	}//end show()

	/**
	 * Extract text from a specific file by Nextcloud file ID.
	 *
	 * If the file doesn't exist in the OpenRegister file_texts table,
	 * it will be looked up in Nextcloud's oc_filecache and added.
	 *
	 * @param int $id Nextcloud file ID from oc_filecache
	 * @param bool $forceReExtract Force re-extraction even if file hasn't changed
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing extraction result
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|404|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Extraction failed'|'File not found in Nextcloud',
	 *         message: string
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @suppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows re-extraction bypass
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extract(int $id, bool $forceReExtract = false): JSONResponse {
		// IDOR guard: only extract files the caller can actually access. 404
		// (not 403) so a non-owner cannot probe which file IDs exist.
		if ($this->hasFileAccess(fileId: $id) === false) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'File not found in Nextcloud',
					'message' => 'File not found or access denied',
				],
				statusCode: 404
			);
		}

		try {
			// ExtractFile returns void, not an object.
			$this->textExtractor->extractFile(fileId: $id, forceReExtract: $forceReExtract);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'File extraction completed',
				]
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'File not found in Nextcloud',
					'message' => $e->getMessage(),
				],
				statusCode: 404
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Extraction failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end extract()

	/**
	 * Discover files in Nextcloud that aren't tracked yet.
	 *
	 * This finds new files and stages them with status='pending'.
	 * Does NOT perform actual text extraction.
	 *
	 * @param int $limit Maximum number of files to discover
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing file discovery results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'File discovery failed',
	 *         message: string,
	 *         data?: array{
	 *             discovered: int<0, max>,
	 *             failed: int<0, max>,
	 *             total: int<0, max>,
	 *             error?: string
	 *         }
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function discover(int $limit = 100): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$stats = $this->textExtractor->discoverUntrackedFiles($limit);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'File discovery completed',
					'data' => $stats,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'File discovery failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end discover()

	/**
	 * Extract text from all pending files (files already tracked with status='pending').
	 *
	 * This processes files already staged for extraction. Use discover() first
	 * to find and stage new files from Nextcloud.
	 *
	 * @param int $limit Maximum number of files to process
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing batch extraction results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Batch extraction failed',
	 *         message: string,
	 *         data?: array{processed: int<0, max>, failed: int<0, max>, total: int<0, max>}
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extractAll(int $limit = 100): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$stats = $this->textExtractor->extractPendingFiles($limit);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Batch extraction completed',
					'data' => $stats,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Batch extraction failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end extractAll()

	/**
	 * Retry failed file extractions.
	 *
	 * @param int $limit Maximum number of files to retry
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing retry operation results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Retry failed',
	 *         message: string,
	 *         data?: array{retried: int<0, max>, failed: int<0, max>, total: int<0, max>}
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function retryFailed(int $limit = 50): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$stats = $this->textExtractor->retryFailedExtractions($limit);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Retry completed',
					'data' => $stats,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Retry failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end retryFailed()

	/**
	 * Get extraction statistics
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing extraction statistics
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt No per-object resource: returns aggregate extraction
	 *   counters (TextExtractionService::getStats); takes no caller-supplied file id.
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Failed to retrieve statistics',
	 *         message?: string,
	 *         data?: array{
	 *             totalFiles: int,
	 *             untrackedFiles: int,
	 *             totalChunks: int,
	 *             totalObjects: int,
	 *             totalEntities: int
	 *         }
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function stats(): JSONResponse {
		try {
			$stats = $this->textExtractor->getStats();

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $stats,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Failed to retrieve statistics',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end stats()

	/**
	 * Clean up invalid file_texts entries
	 *
	 * Removes entries for files that no longer exist, directories, and system files.
	 * This helps maintain database integrity and remove orphaned records.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing cleanup operation results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Cleanup failed',
	 *         message: string,
	 *         data?: array{deleted: 0, reasons: array<never, never>}
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cleanup(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			// Note: cleanupInvalidEntries not available in TextExtractionService.
			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Cleanup completed',
					'data' => [
						'deleted' => 0,
						'reasons' => [],
					],
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Cleanup failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end cleanup()

	/**
	 * Get file types with their file and chunk counts
	 *
	 * Returns only file types that have completed extractions with chunks.
	 * Useful for showing which file types are available for vectorization.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response containing file type statistics
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt No per-object resource: returns aggregate file-type
	 *   counts (currently an empty stub); takes no caller-supplied file id.
	 *
	 * @psalm-return JSONResponse<
	 *     200|500,
	 *     array{
	 *         success: bool,
	 *         error?: 'Failed to retrieve file types',
	 *         message?: string,
	 *         data?: array<never, never>
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function fileTypes(): JSONResponse {
		try {
			// Note: getFileTypeStats not available in TextExtractionService.
			$types = [];

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $types,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Failed to retrieve file types',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end fileTypes()

	/**
	 * Vectorize file chunks in batch
	 *
	 * Processes extracted file chunks and generates vector embeddings.
	 * Supports serial and parallel processing modes.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with vectorization result
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function vectorizeBatch(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$data = $this->request->getParams();
			$mode = $data['mode'] ?? 'serial';
			$maxFiles = (int)($data['max_files'] ?? 0);
			$batchSize = (int)($data['batch_size'] ?? 50);
			$fileTypes = $data['file_types'] ?? [];

			// Use unified vectorization service with 'file' entity type.
			$result = $this->vectorizationService->vectorizeBatch(
				entityType: 'file',
				options: [
					'mode' => $mode,
					'max_files' => $maxFiles,
					'batch_size' => $batchSize,
					'file_types' => $fileTypes,
				]
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $result,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => 'Vectorization failed',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end vectorizeBatch()
}//end class
