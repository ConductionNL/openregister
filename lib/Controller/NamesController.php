<?php

/**
 * OpenRegister Names Controller
 *
 * Ultra-fast object name lookup endpoints for frontend name resolution.
 * Provides optimized endpoints:
 * - GET /names - Get all object names or specific IDs via query parameter
 * - GET /names/{id} - Get name for specific object ID
 *
 * Utilizes aggressive caching for sub-10ms response times to enable
 * seamless frontend rendering of object names instead of UUIDs.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for ultra-fast object name lookup operations
 *
 * Provides cached name resolution endpoints optimized for frontend
 * performance and user experience.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class NamesController extends Controller {
	/**
	 * Constructor for NamesController.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param CacheHandler $objectCacheService Object cache service for name operations
	 * @param LoggerInterface $logger Logger for performance monitoring
	 * @param IUserSession $userSession User session for the current user
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CacheHandler $objectCacheService,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get all object names or names for specific IDs
	 *
	 * PERFORMANCE ENDPOINT**: Returns object names with aggressive caching.
	 *
	 * Query Parameters:**
	 * - `ids` (array): Optional. Array of object IDs/UUIDs to get names for
	 * - If provided: returns only names for specified IDs
	 * - If omitted: returns all object names (triggers cache warmup)
	 *
	 * Response Format:**
	 * ```json
	 * {
	 * "names": {
	 * "uuid-1": "Object Name 1",
	 * "uuid-2": "Object Name 2"
	 * },
	 * "total": 2,
	 * "cached": true,
	 * "execution_time": "5.23ms"
	 * }
	 * ```
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @throws \Exception If name lookup fails
	 *
	 * @return JSONResponse JSON response with object names or error
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		// SEC-CTRL-2: require authentication — this endpoint must not leak object/
		// organisation names anonymously. Dropped @PublicPage.
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		// SEC-CTRL-2 step 2 (closed): name resolution is tenant-scoped in
		// CacheHandler itself. getMultipleObjectNames() and getAllObjectNames()
		// resolve the caller's active organisation (plus its parents, mirroring
		// Db\MultiTenancyTrait) and refuse any name whose owning organisation is
		// outside it — including names already sitting in the shared cache, whose
		// tenancy is stored alongside the value. A name with no resolvable owning
		// organisation is refused rather than guessed.
		$startTime = microtime(true);

		try {
			// Check if specific IDs were requested.
			$requestedIds = $this->request->getParam('ids');

			/*
			 * Initialize names array before conditional assignment.
			 *
			 * @var array<string, string> $names
			 */

			$names = [];

			// Handle different input formats for IDs.
			if ($requestedIds !== null) {
				// Parse IDs from different possible formats.
				if (is_string($requestedIds) === true) {
					// Handle comma-separated string or JSON array string.
					if (str_starts_with($requestedIds, '[') === true) {
						$requestedIds = json_decode($requestedIds, true) ?? [];
					}

					if (is_string($requestedIds) === true) {
						$requestedIds = array_map('trim', explode(',', $requestedIds));
					}
				}

				// Not `is_string() === false && ...`: every path above turns a
				// string into an array (explode/array_map, or json_decode of a
				// '['-prefixed string), and a non-string never enters that block,
				// so the string test here can only ever be true.
				if (is_array($requestedIds) === false) {
					$requestedIds = [(string)$requestedIds];
				}

				/*
				 * Get names for specific IDs.
				 *
				 * @var array<string, string> $names
				 */

				$names = $this->objectCacheService->getMultipleObjectNames($requestedIds);

				$this->logger->debug(
					message: '[NamesController] 📦 BULK NAME LOOKUP REQUEST',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'requested_count' => count($requestedIds),
						'found_count' => count($names),
						'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
					]
				);
			}//end if

			if ($requestedIds === null) {
				// Get all object names (triggers warmup if needed).
				$names = $this->objectCacheService->getAllObjectNames();

				$this->logger->debug(
					message: '[NamesController] 📋 ALL NAMES REQUEST',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'total_names' => count($names),
						'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms',
					]
				);
			}

			$executionTime = round((microtime(true) - $startTime) * 1000, 2);

			return new JSONResponse(
				data: [
					'names' => $names,
					'total' => count($names),
					'cached' => true,
					'execution_time' => $executionTime . 'ms',
					'cache_stats' => $this->objectCacheService->getStats(),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[NamesController] Names endpoint failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to retrieve object names',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

	/**
	 * Get multiple object names via POST request with JSON body
	 *
	 * PERFORMANCE ENDPOINT**: Handles large ID arrays that exceed URL length limits.
	 * Accepts JSON body with 'ids' array to avoid URL length restrictions with UUIDs.
	 *
	 * Request Format:**
	 * ```json
	 * {
	 * "ids": ["uuid-1", "uuid-2", "uuid-3"]
	 * }
	 * ```
	 *
	 * Response Format:**
	 * ```json
	 * {
	 * "names": {
	 * "uuid-1": "Object Name 1",
	 * "uuid-2": "Object Name 2"
	 * },
	 * "total": 2,
	 * "requested": 3,
	 * "execution_time": "8.45ms"
	 * }
	 * ```
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @throws \Exception If name lookup fails
	 *
	 * @return JSONResponse JSON response with object names or error
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		// SEC-CTRL-2: require authentication — per-ids name resolution must not be
		// reachable anonymously. Dropped @PublicPage.
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		// SEC-CTRL-2 step 2 (closed): getMultipleObjectNames() only resolves ids
		// whose owning organisation is inside the caller's active-organisation
		// scope, so a caller-supplied UUID from another tenant resolves to nothing.
		$startTime = microtime(true);

		try {
			// Get JSON body content.
			$inputData = $this->request->getParams();

			// Support both 'ids' in JSON body and form data.
			$requestedIds = $inputData['ids'] ?? null;

			if ($requestedIds === null || is_array($requestedIds) === false) {
				return new JSONResponse(
					data: [
						'error' => 'Invalid request: ids array is required in request body',
						'example' => ['ids' => ['uuid-1', 'uuid-2', 'uuid-3']],
					],
					statusCode: 400
				);
			}

			// Filter and validate IDs.
			$requestedIds = array_filter(array_map('trim', $requestedIds));

			if (empty($requestedIds) === true) {
				return new JSONResponse(
					data: [
						'error' => 'No valid IDs provided in request',
					],
					statusCode: 400
				);
			}

			$names = $this->objectCacheService->getMultipleObjectNames($requestedIds);
			$executionTime = round((microtime(true) - $startTime) * 1000, 2);

			$this->logger->debug(
				message: '[NamesController] 📦 BULK NAME POST REQUEST',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'requested_count' => count($requestedIds),
					'found_count' => count($names),
					'execution_time' => $executionTime . 'ms',
				]
			);

			return new JSONResponse(
				data: [
					'names' => $names,
					'total' => count($names),
					'requested' => count($requestedIds),
					'cached' => true,
					'execution_time' => $executionTime . 'ms',
					'cache_stats' => $this->objectCacheService->getStats(),
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[NamesController] POST names endpoint failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'error' => 'Failed to retrieve object names',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end create()
}//end class
