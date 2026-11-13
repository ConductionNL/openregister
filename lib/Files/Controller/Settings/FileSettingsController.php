<?php

/**
 * OpenRegister File Settings Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller\Settings;

use OCA\OpenRegister\Exception\UnknownPatternSetException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Files\Controller\Support\FileSettingsControllerServices;
use OCA\OpenRegister\Files\Controller\Support\ServiceHealthProbe;
use Exception;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCA\OpenRegister\Service\Anonymisation\BackendMethodsInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for file processing settings.
 *
 * Handles:
 * - File extraction configuration
 * - Text extraction services (Dolphin, etc.)
 * - File indexing operations
 * - File processing statistics
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Service health check methods contribute inherent complexity
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class FileSettingsController extends Controller {

	/**
	 * The file extraction statistics served when nothing could be measured, in response order.
	 *
	 * Extraction keeps no per-file queue state, so pendingFiles, failed and processing stay 0
	 * and extractedTextStorageMB is not measured.
	 *
	 * @var array<string, bool|int|string>
	 */
	private const EMPTY_EXTRACTION_STATS = [
		'success' => true,
		'totalFiles' => 0,
		'processedFiles' => 0,
		'pendingFiles' => 0,
		'untrackedFiles' => 0,
		'extractedTextStorageMB' => '0.00',
		'totalFilesStorageMB' => '0.00',
		'completed' => 0,
		'failed' => 0,
		'indexed' => 0,
		'processing' => 0,
		'vectorized' => 0,
	];

	/**
	 * DI container, for the optional extraction backends.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Settings service.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request  The request.
	 * @param FileSettingsControllerServices $services The container, the settings service and the logger.
	 */
	public function __construct(
		IRequest $request,
		FileSettingsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->container = $services->container;
		$this->settingsService = $services->settingsService;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * Get File Management settings
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse File settings
	 *
	 * @psalm-return JSONResponse<200|500, array, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getFileSettings(): JSONResponse {
		try {
			$data = $this->settingsService->getFileSettingsOnly();
			return new JSONResponse(data: $data);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getFileSettings()

	/**
	 * Update File Management settings
	 *
	 * @return JSONResponse JSON response with updated file settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function updateFileSettings(): JSONResponse {
		try {
			$data = $this->request->getParams();

			// Extract IDs from objects sent by frontend.
			if (($data['provider'] ?? null) !== null && is_array($data['provider']) === true) {
				$data['provider'] = $data['provider']['id'] ?? null;
			}

			if (($data['chunkingStrategy'] ?? null) !== null && is_array($data['chunkingStrategy']) === true) {
				$data['chunkingStrategy'] = $data['chunkingStrategy']['id'] ?? null;
			}

			$result = $this->settingsService->updateFileSettingsOnly($data);
			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'File settings updated successfully',
					'data' => $result,
				]
			);
		} catch (UnknownPatternSetException $e) {
			return $this->error(message: $e->getMessage(), status: 400);
		} catch (Exception $e) {
			return $this->error(message: $e->getMessage());
		}//end try
	}//end updateFileSettings()

	/**
	 * Test Dolphin API connection
	 *
	 * @param string $apiEndpoint Dolphin API endpoint URL
	 * @param string $apiKey Dolphin API key
	 *
	 * @return JSONResponse
	 *
	 * @psalm-return JSONResponse<200|400|500, array{success: bool, error?: string,
	 *     message?: 'Dolphin connection successful'}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function testDolphinConnection(string $apiEndpoint, string $apiKey): JSONResponse {
		try {
			// Validate inputs.
			if (empty($apiEndpoint) === true || empty($apiKey) === true) {
				return $this->error(message: 'API endpoint and API key are required', status: 400);
			}

			$headers = [
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			];

			$result = $this->performHealthCheck(
				probe: new ServiceHealthProbe(serviceName: 'Dolphin', url: $apiEndpoint . '/health', headers: $headers)
			);

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return $this->error(message: $e->getMessage());
		}//end try
	}//end testDolphinConnection()

	/**
	 * Test Presidio API connection
	 *
	 * @param string $apiEndpoint Presidio API endpoint URL
	 *
	 * @return JSONResponse
	 *
	 * @psalm-return JSONResponse<200|400|500, array{success: bool, error?: string,
	 *     message?: string, capabilities?: array}, array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function testPresidioConnection(string $apiEndpoint): JSONResponse {
		try {
			// Validate inputs.
			if (empty($apiEndpoint) === true) {
				return $this->error(message: 'API endpoint is required', status: 400);
			}

			$result = $this->performHealthCheck(
				probe: new ServiceHealthProbe(serviceName: 'Presidio', url: $apiEndpoint . '/health')
			);

			if ($result['success'] === true) {
				// Try to get supported entities.
				$capabilities = $this->fetchPresidioCapabilities(apiEndpoint: $apiEndpoint);
				$result['capabilities'] = $capabilities;
			}

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return $this->error(message: $e->getMessage());
		}//end try
	}//end testPresidioConnection()

	/**
	 * Test OpenAnonymiser ExApp availability.
	 *
	 * OpenAnonymiser is now detected via AppAPI, not an HTTP endpoint.
	 * Prefer the admin endpoint `POST /api/admin/anonymisation/test-connection`
	 * with `{method: "openanonymiser"}`. This route is retained for backward
	 * compatibility and delegates to AnonymisationBackendService; the
	 * $apiEndpoint parameter is ignored and no external HTTP request is issued.
	 *
	 * @param string $apiEndpoint Ignored; retained for backward compatibility.
	 *
	 * @return JSONResponse
	 *
	 * @deprecated OpenAnonymiser is now detected via AppAPI, not an HTTP endpoint.
	 *
	 * @psalm-return JSONResponse<200|500, array{success: bool, error?: string,
	 *     message?: string}, array<never, never>>
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/tasks.md#task-4.4
	 */
	public function testOpenAnonymiserConnection(string $apiEndpoint = ''): JSONResponse {
		try {
			/*
			 * @var AnonymisationBackendServiceInterface $service
			 */

			$service = $this->container->get(AnonymisationBackendServiceInterface::class);
			$probe = $service->testConnection(method: BackendMethodsInterface::METHOD_OPENANONYMISER);

			if ($probe->reachable === true) {
				return new JSONResponse(
					data: [
						'success' => true,
						'message' => 'OpenAnonymiser ExApp detected via AppAPI',
					]
				);
			}

			return new JSONResponse(
				data: [
					'success' => false,
					'error' => ($probe->error ?? 'OpenAnonymiser ExApp not available'),
				]
			);
		} catch (Exception $e) {
			return $this->error(message: $e->getMessage());
		}//end try
	}//end testOpenAnonymiserConnection()

	/**
	 * Get file extraction statistics
	 *
	 * Combines two data sources:
	 * - FileMapper: total files and their size in Nextcloud (oc_filecache, bypasses rights logic)
	 * - TextExtractionService and ChunkMapper: extraction state (oc_openregister_chunks)
	 *
	 * Extraction keeps no per-file queue state, so pendingFiles, failed and
	 * processing are always 0, and extractedTextStorageMB is not measured.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse File extraction statistics including:
	 *                      - totalFiles: all files in Nextcloud (from oc_filecache)
	 *                      - processedFiles, completed: files that have text chunks
	 *                      - untrackedFiles: user files without text chunks
	 *                      - indexed, vectorized: indexed and vectorized chunks
	 *                      - totalChunks, totalEntities: chunk and entity rows
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{success: true, totalFiles: int, processedFiles: int,
	 *     pendingFiles: 0, untrackedFiles: int,
	 *     extractedTextStorageMB: string, totalFilesStorageMB: string,
	 *     completed: int, failed: 0, indexed: int,
	 *     processing: 0, vectorized: int, totalChunks?: int,
	 *     totalEntities?: int, error?: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getFileExtractionStats(): JSONResponse {
		try {
			$stats = array_merge(self::EMPTY_EXTRACTION_STATS, $this->measureFileExtraction());
		} catch (Exception $e) {
			// Return zeros instead of error to avoid breaking UI.
			$stats = array_merge(self::EMPTY_EXTRACTION_STATS, ['error' => $e->getMessage()]);
		}

		return new JSONResponse(data: $stats);
	}//end getFileExtractionStats()

	/**
	 * Measure the file cache, the chunk table and the extraction totals.
	 *
	 * @return array<string, int|string> The measured statistics, keyed as in EMPTY_EXTRACTION_STATS
	 *                                   plus totalChunks and totalEntities.
	 *
	 * @spec exclude Private helper of getFileExtractionStats();
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function measureFileExtraction(): array {
		$fileMapper = $this->container->get(\OCA\OpenRegister\Files\Db\FileMapperInterface::class);
		$chunkMapper = $this->container->get(\OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface::class);
		$extractionStats = $this->container->get(\OCA\OpenRegister\Files\Service\TextExtractionService::class)->getStats();

		$processedFiles = $chunkMapper->countFileSourceSummaries();
		$totalFilesStorageMB = round($fileMapper->getTotalFilesSize() / 1024 / 1024, 2);

		return [
			'totalFiles' => $fileMapper->countAllFiles(),
			'processedFiles' => $processedFiles,
			'untrackedFiles' => $extractionStats['untrackedFiles'],
			'totalFilesStorageMB' => number_format($totalFilesStorageMB, 2),
			'completed' => $processedFiles,
		] + $this->chunkIndexCounts(chunkMapper: $chunkMapper) + $this->extractionTotals(extractionStats: $extractionStats);
	}//end measureFileExtraction()

	/**
	 * The indexed and vectorized chunk counts.
	 *
	 * @param \OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface $chunkMapper The chunk table overview.
	 *
	 * @return array{indexed: int, vectorized: int}
	 *
	 * @spec exclude Private helper of getFileExtractionStats();
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function chunkIndexCounts(\OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface $chunkMapper): array {
		return [
			'indexed' => $chunkMapper->countIndexed(),
			'vectorized' => $chunkMapper->countVectorized(),
		];
	}//end chunkIndexCounts()

	/**
	 * The chunk and entity totals from the text extraction statistics.
	 *
	 * @param array<string, mixed> $extractionStats The TextExtractionService statistics.
	 *
	 * @return array{totalChunks: mixed, totalEntities: mixed}
	 *
	 * @spec exclude Private helper of getFileExtractionStats();
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function extractionTotals(array $extractionStats): array {
		return [
			'totalChunks' => $extractionStats['totalChunks'],
			'totalEntities' => $extractionStats['totalEntities'],
		];
	}//end extractionTotals()

	/**
	 * Perform a health check HTTP request against a service endpoint.
	 *
	 * Executes a GET request to the given URL with optional headers and returns
	 * a standardized result array indicating success or failure.
	 *
	 * @param ServiceHealthProbe $probe The service name, the URL to check and its optional headers.
	 *
	 * @return array{success: bool, message?: string, error?: string} Health check result.
	 *
	 * @spec exclude Private helper: shared cURL health-check used by the connection-test endpoints;
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function performHealthCheck(ServiceHealthProbe $probe): array {
		$ch = curl_init($probe->url);
		curl_setopt_array($ch, $this->healthCheckCurlOptions(probe: $probe));

		curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		// No curl_close(): deprecated since PHP 8.0 and a no-op — the
		// CurlHandle object is freed when it goes out of scope.
		if ($curlError !== '') {
			return [
				'success' => false,
				'error' => 'Connection failed: ' . $curlError,
			];
		}

		return $this->healthCheckStatusResult(probe: $probe, httpCode: $httpCode);
	}//end performHealthCheck()

	/**
	 * The cURL options of a health check request; without headers it sends a JSON content type.
	 *
	 * @param ServiceHealthProbe $probe The service name, the URL to check and its optional headers.
	 *
	 * @return array<int, mixed> The cURL options.
	 *
	 * @spec exclude Private helper of performHealthCheck();
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function healthCheckCurlOptions(ServiceHealthProbe $probe): array {
		$headers = $probe->headers;
		if (empty($headers) === true) {
			$headers = ['Content-Type: application/json'];
		}

		return [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
		];
	}//end healthCheckCurlOptions()

	/**
	 * The health check result for the HTTP status a reachable service answered with.
	 *
	 * @param ServiceHealthProbe $probe    The probed service.
	 * @param int                $httpCode The HTTP status code it answered with.
	 *
	 * @return array{success: bool, message?: string, error?: string} Health check result.
	 *
	 * @spec exclude Private helper of performHealthCheck();
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function healthCheckStatusResult(ServiceHealthProbe $probe, int $httpCode): array {
		if ($httpCode === 200 || $httpCode === 201) {
			return [
				'success' => true,
				'message' => $probe->serviceName . ' connection successful',
			];
		}

		return [
			'success' => false,
			'error' => $probe->serviceName . ' API returned HTTP ' . $httpCode,
		];
	}//end healthCheckStatusResult()

	/**
	 * Fetch Presidio supported entity capabilities.
	 *
	 * Makes a separate request to the Presidio /supportedentities endpoint
	 * and returns the capabilities array.
	 *
	 * @param string $apiEndpoint The Presidio API base endpoint URL.
	 *
	 * @return array Capabilities array, potentially containing 'supported_entities'.
	 *
	 * @spec exclude Private helper: fetches Presidio supported-entities for the connection test;
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function fetchPresidioCapabilities(string $apiEndpoint): array {
		$capabilities = [];

		$ch = curl_init($apiEndpoint . '/supportedentities');
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
				],
				CURLOPT_TIMEOUT => 10,
			]
		);
		$entitiesResponse = curl_exec($ch);
		// No curl_close(): deprecated since PHP 8.0 and a no-op — the
		// CurlHandle object is freed when it goes out of scope.
		if ($entitiesResponse !== false) {
			$entities = json_decode($entitiesResponse, true);
			if (is_array($entities) === true) {
				$capabilities['supported_entities'] = $entities;
			}
		}

		return $capabilities;
	}//end fetchPresidioCapabilities()

	/**
	 * A failed response: `success` false and the error.
	 *
	 * @param string $message The error.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function error(string $message, int $status=500): JSONResponse {
		return new JSONResponse(data: ['success' => false, 'error' => $message], statusCode: $status);
	}//end error()
}//end class
