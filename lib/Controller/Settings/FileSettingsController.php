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

namespace OCA\OpenRegister\Controller\Settings;

use Exception;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\SettingsService;
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
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ContainerInterface $container DI container.
	 * @param SettingsService $settingsService Settings service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
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
	 * @NoCSRFRequired
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
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
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
	 * @NoCSRFRequired
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
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'API endpoint and API key are required',
					],
					statusCode: 400
				);
			}

			$headers = [
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			];

			$result = $this->performHealthCheck(
				url: $apiEndpoint . '/health',
				serviceName: 'Dolphin',
				headers: $headers
			);

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end testDolphinConnection()

	/**
	 * Test Presidio API connection
	 *
	 * @param string $apiEndpoint Presidio API endpoint URL
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
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
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => 'API endpoint is required',
					],
					statusCode: 400
				);
			}

			$result = $this->performHealthCheck(
				url: $apiEndpoint . '/health',
				serviceName: 'Presidio'
			);

			if ($result['success'] === true) {
				// Try to get supported entities.
				$capabilities = $this->fetchPresidioCapabilities(apiEndpoint: $apiEndpoint);
				$result['capabilities'] = $capabilities;
			}

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
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
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|500, array{success: bool, error?: string,
	 *     message?: string}, array<never, never>>
	 *
	 * @spec openspec/changes/anonymiser-backend-selection/tasks.md#task-4.4
	 */
	public function testOpenAnonymiserConnection(string $apiEndpoint = ''): JSONResponse {
		try {
			/*
			 * @var AnonymisationBackendService $service
			 */

			$service = $this->container->get(AnonymisationBackendService::class);
			$probe = $service->testConnection(method: BackendState::METHOD_OPENANONYMISER);

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
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end testOpenAnonymiserConnection()

	/**
	 * Get file extraction statistics
	 *
	 * Combines multiple data sources for comprehensive file statistics:
	 * - FileMapper: Total files in Nextcloud (from oc_filecache, bypasses rights logic)
	 * - FileTextMapper: Extraction status (from oc_openregister_file_texts)
	 *
	 * This provides accurate statistics without dealing with Nextcloud's extensive rights logic.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse File extraction statistics including:
	 *                      - totalFiles: All files in Nextcloud (from oc_filecache)
	 *                      - processedFiles: Files tracked in extraction system
	 *                      (from oc_openregister_file_texts)
	 *                      - pendingFiles: Files discovered and waiting for extraction
	 *                      (status='pending')
	 *                      - untrackedFiles: Files in Nextcloud not yet discovered
	 *                      - completed, failed, indexed, processing, vectorized:
	 *                      Detailed processing status counts
	 *
	 * @psalm-return JSONResponse<200,
	 *     array{success: true, totalFiles: 0|mixed, processedFiles: 0|mixed,
	 *     pendingFiles: 0|mixed, untrackedFiles: 0|mixed,
	 *     extractedTextStorageMB: string, totalFilesStorageMB: string,
	 *     completed: 0|mixed, failed: 0|mixed, indexed: 0|mixed,
	 *     processing: 0|mixed, vectorized: 0|mixed, error?: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
	 */
	public function getFileExtractionStats(): JSONResponse {
		try {
			// Get total files from Nextcloud filecache (bypasses rights logic).
			$fileMapper = $this->container->get(\OCA\OpenRegister\Db\FileMapper::class);
			$totalNcFiles = $fileMapper->countAllFiles();
			$totalFilesSize = $fileMapper->getTotalFilesSize();

			// Get extraction statistics from our file_texts table.
			$textExtractSvc = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);
			$dbStats = $textExtractSvc->getExtractionStats('file');

			// Calculate storage in MB.
			$extractedTextMB = round($dbStats['total_text_size'] / 1024 / 1024, 2);
			$totalFilesStorageMB = round($totalFilesSize / 1024 / 1024, 2);

			// Calculate untracked files (files in Nextcloud not yet discovered).
			$untrackedFiles = $totalNcFiles - $dbStats['total'];

			return new JSONResponse(
				data: [
					'success' => true,
					'totalFiles' => $totalNcFiles,
					'processedFiles' => $dbStats['completed'],
					// Files successfully extracted (status='completed').
					'pendingFiles' => $dbStats['pending'],
					// Files discovered and waiting for extraction.
					'untrackedFiles' => max(0, $untrackedFiles),
					// Files not yet discovered.
					'extractedTextStorageMB' => number_format($extractedTextMB, 2),
					'totalFilesStorageMB' => number_format($totalFilesStorageMB, 2),
					'completed' => $dbStats['completed'],
					'failed' => $dbStats['failed'],
					'indexed' => $dbStats['indexed'],
					'processing' => $dbStats['processing'],
					'vectorized' => $dbStats['vectorized'],
				]
			);
		} catch (Exception $e) {
			// Return zeros instead of error to avoid breaking UI.
			return new JSONResponse(
				data: [
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
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end getFileExtractionStats()

	/**
	 * Perform a health check HTTP request against a service endpoint.
	 *
	 * Executes a GET request to the given URL with optional headers and returns
	 * a standardized result array indicating success or failure.
	 *
	 * @param string $url The full URL to check (e.g. endpoint + '/health').
	 * @param string $serviceName Human-readable service name for error messages.
	 * @param string[] $headers Optional HTTP headers (default: Content-Type: application/json).
	 *
	 * @return array{success: bool, message?: string, error?: string} Health check result.
	 *
	 * @spec exclude Private helper: shared cURL health-check used by the connection-test endpoints;
	 *              the file-index HTTP surface is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2.
	 */
	private function performHealthCheck(string $url, string $serviceName, array $headers = []): array {
		if (empty($headers) === true) {
			$headers = ['Content-Type: application/json'];
		}

		$ch = curl_init($url);
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_TIMEOUT => 10,
				CURLOPT_SSL_VERIFYPEER => true,
			]
		);

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

		if ($httpCode === 200 || $httpCode === 201) {
			return [
				'success' => true,
				'message' => $serviceName . ' connection successful',
			];
		}

		return [
			'success' => false,
			'error' => $serviceName . ' API returned HTTP ' . $httpCode,
		];
	}//end performHealthCheck()

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
}//end class
