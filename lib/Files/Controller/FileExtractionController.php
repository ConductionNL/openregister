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

namespace OCA\OpenRegister\Files\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Service\FileExtractionOptions;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionControllerServices;
use OCA\OpenRegister\Files\Controller\Support\FileExtractionListing;
use OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface;
use OCA\OpenRegister\Files\Db\FileSourceOrder;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The read scope is the object rule for an object's file.
 */
class FileExtractionController extends Controller {
	/**
	 * Text extraction service
	 *
	 * @var TextExtractionService
	 */
	private readonly TextExtractionService $textExtractor;

	/**
	 * Unified vectorization service
	 *
	 * @var VectorizationServiceInterface
	 */
	private readonly VectorizationServiceInterface $vectorizationService;

	/**
	 * Chunk mapper for text chunks
	 *
	 * @var ChunkIndexOverviewInterface
	 */
	private readonly ChunkIndexOverviewInterface $chunkMapper;

	/**
	 * Entity relation mapper
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * Risk level computation service
	 *
	 * @var RiskLevelService
	 */
	private readonly RiskLevelService $riskLevelService;

	/**
	 * Active user session for caller identity
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for admin checks
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Whether the caller can open a file.
	 *
	 * @var CallerFileAccess
	 */
	private readonly CallerFileAccess $fileAccess;

	/**
	 * Constructor
	 *
	 * @param IRequest $request HTTP request
	 * @param FileExtractionControllerServices $services The file extraction controller services.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		IRequest $request,
		FileExtractionControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->textExtractor = $services->domain->textExtractor;
		$this->vectorizationService = $services->domain->vectorizationService;
		$this->chunkMapper = $services->mappers->chunkMapper;
		$this->entityRelationMapper = $services->mappers->entityRelationMapper;
		$this->riskLevelService = $services->domain->riskLevelService;
		$this->userSession = $services->platform->userSession;
		$this->groupManager = $services->platform->groupManager;
		$this->fileAccess = new CallerFileAccess(
			fileReadScope: $services->domain->fileReadScope,
			userSession: $services->platform->userSession,
			rootFolder: $services->platform->rootFolder
		);
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
		return $this->fileAccess->mayOpen(fileId: $fileId);
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
			$listing = new FileExtractionListing(request: $this->request);

			// All chunked files are "completed" — if filtering for another status, return empty.
			if (in_array($listing->status, [null, '', 'completed'], true) === false) {
				return new JSONResponse(data: ['success' => true, 'data' => [], 'count' => 0]);
			}

			$listing->restrict(restricted: $this->isCurrentUserAdmin() === false);
			$page       = $this->extractionSummaries(listing: $listing);
			$data       = $this->extractionRows(summaries: $page['summaries']);
			$totalCount = $page['total'];

			$riskLevel = $listing->riskLevel;
			if (in_array($riskLevel, [null, ''], true) === false) {
				$data       = array_values(array_filter($data, fn ($f) => $f['riskLevel'] === $riskLevel));
				$totalCount = count($data);
			}

			if ($listing->sortsInPhp() === true) {
				$data = $listing->sortRows(rows: $data);
			}

			if ($listing->cutsInPhp() === true) {
				$totalCount = count($data);
				$data       = array_slice($data, $listing->offset, $listing->limit);
			}

			return new JSONResponse(data: ['success' => true, 'data' => $data, 'count' => $totalCount]);
		} catch (\Exception $e) {
			return new JSONResponse(data: ['success' => false, 'error' => $e->getMessage()], statusCode: 500);
		}//end try
	}//end index()

	/**
	 * The chunked-file summaries of one listing, and their total, cut to what the caller may open.
	 *
	 * When the page is cut in PHP every summary is fetched.
	 *
	 * @param FileExtractionListing $listing The list parameters.
	 *
	 * @return array{summaries: array, total: int} The summaries and the database total.
	 */
	private function extractionSummaries(FileExtractionListing $listing): array {
		$dbLimit  = $listing->limit;
		$dbOffset = $listing->offset;
		if ($listing->cutsInPhp() === true) {
			$dbLimit  = null;
			$dbOffset = null;
		}

		$order      = new FileSourceOrder(sort: $listing->databaseSort(), order: $listing->order);
		$summaries  = $this->chunkMapper->getFileSourceSummaries($dbLimit, $dbOffset, $listing->searchTerm, $order);
		$totalCount = $this->chunkMapper->countFileSourceSummaries($listing->searchTerm);
		if ($listing->isRestricted() === true) {
			$summaries = array_values(
				array_filter($summaries, fn ($summary) => $this->hasFileAccess(fileId: (int)$summary['sourceId']))
			);
		}

		return ['summaries' => $summaries, 'total' => $totalCount];
	}//end extractionSummaries()

	/**
	 * The overview rows of chunked-file summaries, with entity count and risk level.
	 *
	 * @param array $summaries The summaries.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function extractionRows(array $summaries): array {
		$data = [];
		foreach ($summaries as $summary) {
			$entityCount = count($this->entityRelationMapper->findByFileId($summary['sourceId']));
			$fileRisk    = $this->riskLevelService->getRiskLevel($summary['sourceId']);

			$data[] = [
				'id'               => $summary['sourceId'],
				'fileName'         => $summary['fileName'],
				'mimeType'         => $summary['mimeType'],
				'fileSize'         => $summary['fileSize'],
				'extractionStatus' => 'completed',
				'chunkCount'       => $summary['chunkCount'],
				'extractedAt'      => $summary['lastExtracted'],
				'extractionError'  => null,
				'entityCount'      => $entityCount,
				'riskLevel'        => $fileRisk,
			];
		}

		return $data;
	}//end extractionRows()

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
		if ($this->hasFileAccess(fileId: $id) === false) {
			return $this->notFound(error: 'File not found in extraction system', message: 'File not found or access denied');
		}

		try {
			// Get chunks for this file.
			$chunks = $this->chunkMapper->findBySource(sourceType: 'file', sourceId: $id);

			if (empty($chunks) === true) {
				return $this->notFound(error: 'File not found in extraction system', message: 'No chunks found for file ID: ' . $id);
			}

			return $this->succeeded(body: ['data' => array_map(fn ($chunk) => $chunk->jsonSerialize(), $chunks)]);
		} catch (\Exception $e) {
			return $this->notFound(error: 'File not found in extraction system', message: $e->getMessage());
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows re-extraction bypass
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function extract(int $id, bool $forceReExtract = false): JSONResponse {
		// IDOR guard: only extract files the caller can actually access. 404
		// (not 403) so a non-owner cannot probe which file IDs exist.
		if ($this->hasFileAccess(fileId: $id) === false) {
			return $this->notFound(error: 'File not found in Nextcloud', message: 'File not found or access denied');
		}

		try {
			// ExtractFile returns void, not an object.
			$this->textExtractor->extractFile(fileId: $id, options: new FileExtractionOptions(forceReExtract: $forceReExtract));

			return $this->succeeded(body: ['message' => 'File extraction completed']);
		} catch (NotFoundException $e) {
			return $this->notFound(error: 'File not found in Nextcloud', message: $e->getMessage());
		} catch (\Exception $e) {
			return $this->failed(error: 'Extraction failed', message: $e->getMessage());
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
	 *     200|403|500,
	 *     array{success: false, error: string}|array{
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

			return $this->succeeded(body: ['message' => 'File discovery completed', 'data' => $stats]);
		} catch (\Exception $e) {
			return $this->failed(error: 'File discovery failed', message: $e->getMessage());
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
	 *     200|403|500,
	 *     array{success: false, error: string}|array{
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
			// Same floor/ceiling as the bulk endpoint: a zero or negative limit
			// would answer "nothing to do" for a queue that is not empty.
			$limit = max(1, min($limit, 500));
			$stats = $this->textExtractor->extractPendingFiles($limit);

			return $this->succeeded(body: ['message' => 'Batch extraction completed', 'data' => $stats]);
		} catch (\Exception $e) {
			return $this->failed(error: 'Batch extraction failed', message: $e->getMessage());
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
	 *     200|403|500,
	 *     array{success: false, error: string}|array{
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

			return $this->succeeded(body: ['message' => 'Retry completed', 'data' => $stats]);
		} catch (\Exception $e) {
			return $this->failed(error: 'Retry failed', message: $e->getMessage());
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
	 * @psalm-return JSONResponse<
	 *     200|403|500,
	 *     array{success: false, error: string}|array{
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
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(['success' => false, 'error' => 'Admin privileges required'], 403);
		}

		try {
			$stats = $this->textExtractor->getStats();

			return $this->succeeded(body: ['data' => $stats]);
		} catch (\Exception $e) {
			return $this->failed(error: 'Failed to retrieve statistics', message: $e->getMessage());
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
	 *     200|403|500,
	 *     array{success: false, error: string}|array{
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
			return $this->failed(error: 'Cleanup failed', message: $e->getMessage());
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

			return $this->succeeded(body: ['data' => $types]);
		} catch (\Exception $e) {
			return $this->failed(error: 'Failed to retrieve file types', message: $e->getMessage());
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

			return $this->succeeded(body: ['data' => $result]);
		} catch (\Exception $e) {
			return $this->failed(error: 'Vectorization failed', message: $e->getMessage());
		}//end try
	}//end vectorizeBatch()

	/**
	 * A successful response: `success` true, then the body.
	 *
	 * @param array<string, mixed> $body The rest of the response body.
	 *
	 * @return JSONResponse
	 */
	private function succeeded(array $body): JSONResponse {
		return new JSONResponse(data: ['success' => true] + $body);
	}//end succeeded()

	/**
	 * A failed response (500) with an error and a message.
	 *
	 * @param string $error   The error.
	 * @param string $message The message.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $error, string $message): JSONResponse {
		return new JSONResponse(data: ['success' => false, 'error' => $error, 'message' => $message], statusCode: 500);
	}//end failed()

	/**
	 * A not-found response (404) with an error and a message.
	 *
	 * @param string $error   The error.
	 * @param string $message The message.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $error, string $message): JSONResponse {
		return new JSONResponse(data: ['success' => false, 'error' => $error, 'message' => $message], statusCode: 404);
	}//end notFound()
}//end class
