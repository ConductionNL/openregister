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

use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
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
	 * @param ObjectNameCacheInterface $objectCacheService Object cache service for name operations
	 * @param LoggerInterface $logger Logger for performance monitoring
	 * @param IUserSession $userSession User session for the current user
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectNameCacheInterface $objectCacheService,
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
	 * @no-admin-idor-exempt Guarded in the name cache, not here: CacheHandler::visibleNames()
	 *   returns only names the caller may read (object read RBAC + multitenancy, and the
	 *   caller's organisation scope for organisation names), so an id the caller cannot
	 *   read resolves to nothing.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		// SEC-CTRL-2: require authentication — this endpoint must not leak object/
		// organisation names anonymously. Dropped @PublicPage.
		if ($this->userSession->getUser() === null) {
			return $this->authenticationRequiredResponse();
		}

		// Names follow read rights, decided in CacheHandler itself: the caller
		// gets the name of every object they may read (the object read path's own
		// RBAC + multitenancy filter answers that), and nothing for an object they
		// may not read, including names already in the shared cache. Organisation
		// names follow the caller's organisation scope.
		$startTime = microtime(true);

		try {
			$names = $this->lookupNamesForQuery(startTime: $startTime);

			return $this->namesResponse(names: $names, startTime: $startTime);
		} catch (\Exception $e) {
			return $this->lookupFailedResponse(logMessage: '[NamesController] Names endpoint failed', exception: $e);
		}
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
	 * @no-admin-idor-exempt Guarded in the name cache, not here: CacheHandler::visibleNames()
	 *   returns only names the caller may read (object read RBAC + multitenancy, and the
	 *   caller's organisation scope for organisation names), so an id the caller cannot
	 *   read resolves to nothing.
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		// SEC-CTRL-2: require authentication — per-ids name resolution must not be
		// reachable anonymously. Dropped @PublicPage.
		if ($this->userSession->getUser() === null) {
			return $this->authenticationRequiredResponse();
		}

		// The lookup (getMultipleObjectNames()) only resolves ids the caller may read, so a
		// caller-supplied UUID of an object they cannot read resolves to nothing.
		$startTime = microtime(true);

		try {
			return $this->lookupPostedNames(startTime: $startTime);
		} catch (\Exception $e) {
			return $this->lookupFailedResponse(logMessage: '[NamesController] POST names endpoint failed', exception: $e);
		}
	}//end create()

	/**
	 * Answer an anonymous caller.
	 *
	 * @return JSONResponse 401 response
	 */
	private function authenticationRequiredResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authenticationRequiredResponse()

	/**
	 * Resolve the names the GET query asks for: the listed ids, or every name when no ids are given.
	 *
	 * @param float $startTime Request start, for the logged execution time
	 *
	 * @return array<string, string> Names keyed by id
	 */
	private function lookupNamesForQuery(float $startTime): array {
		// Check if specific IDs were requested.
		$requestedIds = $this->request->getParam('ids');

		if ($requestedIds === null) {
			return $this->lookupAllNames(startTime: $startTime);
		}

		return $this->lookupRequestedNames(
			requestedIds: $this->normaliseQueryIds(requestedIds: $requestedIds),
			startTime: $startTime
		);
	}//end lookupNamesForQuery()

	/**
	 * Turn the `ids` query value into a list of ids.
	 *
	 * Accepts a comma-separated string, a JSON array string or an array; any other
	 * scalar becomes a one-element list.
	 *
	 * @param mixed $requestedIds The raw `ids` query value (never null)
	 *
	 * @return array<int|string, mixed> The requested ids
	 */
	private function normaliseQueryIds(mixed $requestedIds): array {
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

		return $requestedIds;
	}//end normaliseQueryIds()

	/**
	 * Resolve the names of the given ids and log the bulk lookup.
	 *
	 * @param array<int|string, mixed> $requestedIds The ids to resolve
	 * @param float                    $startTime    Request start, for the logged execution time
	 *
	 * @return array<string, string> Names keyed by id
	 */
	private function lookupRequestedNames(array $requestedIds, float $startTime): array {
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

		return $names;
	}//end lookupRequestedNames()

	/**
	 * Resolve every object name (triggers warmup if needed) and log the lookup.
	 *
	 * @param float $startTime Request start, for the logged execution time
	 *
	 * @return array<string, string> Names keyed by id
	 */
	private function lookupAllNames(float $startTime): array {
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

		return $names;
	}//end lookupAllNames()

	/**
	 * Build the GET response around the resolved names.
	 *
	 * @param array<string, string> $names     Names keyed by id
	 * @param float                 $startTime Request start, for the reported execution time
	 *
	 * @return JSONResponse The names response
	 */
	private function namesResponse(array $names, float $startTime): JSONResponse {
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
	}//end namesResponse()

	/**
	 * Validate the posted `ids` and resolve their names.
	 *
	 * @param float $startTime Request start, for the reported execution time
	 *
	 * @return JSONResponse The names response, or a 400 for missing or empty ids
	 */
	private function lookupPostedNames(float $startTime): JSONResponse {
		// Get JSON body content.
		$inputData = $this->request->getParams();

		// Support both 'ids' in JSON body and form data.
		$requestedIds = $inputData['ids'] ?? null;

		if ($requestedIds === null || is_array($requestedIds) === false) {
			return $this->idsArrayRequiredResponse();
		}

		// Filter and validate IDs.
		$requestedIds = array_filter(array_map('trim', $requestedIds));

		if (empty($requestedIds) === true) {
			return $this->noValidIdsResponse();
		}

		return $this->postedNamesResponse(requestedIds: $requestedIds, startTime: $startTime);
	}//end lookupPostedNames()

	/**
	 * Answer a POST whose body carries no `ids` array.
	 *
	 * @return JSONResponse 400 response with an example body
	 */
	private function idsArrayRequiredResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'Invalid request: ids array is required in request body',
				'example' => ['ids' => ['uuid-1', 'uuid-2', 'uuid-3']],
			],
			statusCode: 400
		);
	}//end idsArrayRequiredResponse()

	/**
	 * Answer a POST whose `ids` are all blank.
	 *
	 * @return JSONResponse 400 response
	 */
	private function noValidIdsResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'No valid IDs provided in request',
			],
			statusCode: 400
		);
	}//end noValidIdsResponse()

	/**
	 * Resolve the posted ids, log the lookup and build the POST response.
	 *
	 * @param array<int|string, string> $requestedIds The trimmed, non-empty ids
	 * @param float                     $startTime    Request start, for the reported execution time
	 *
	 * @return JSONResponse The names response
	 */
	private function postedNamesResponse(array $requestedIds, float $startTime): JSONResponse {
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
	}//end postedNamesResponse()

	/**
	 * Log a failed name lookup and answer 500 with its message.
	 *
	 * @param string     $logMessage The error line to log
	 * @param \Exception $exception  The failure
	 *
	 * @return JSONResponse 500 response
	 */
	private function lookupFailedResponse(string $logMessage, \Exception $exception): JSONResponse {
		$this->logLookupFailure(logMessage: $logMessage, exception: $exception);

		return new JSONResponse(
			data: [
				'error' => 'Failed to retrieve object names',
				'message' => $exception->getMessage(),
			],
			statusCode: 500
		);
	}//end lookupFailedResponse()

	/**
	 * Log a failed name lookup with its message and trace.
	 *
	 * @param string     $logMessage The error line to log
	 * @param \Exception $exception  The failure
	 *
	 * @return void
	 */
	private function logLookupFailure(string $logMessage, \Exception $exception): void {
		$this->logger->error(
			message: $logMessage,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $exception->getMessage(),
				'trace' => $exception->getTraceAsString(),
			]
		);
	}//end logLookupFailure()
}//end class
