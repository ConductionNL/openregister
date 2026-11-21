<?php

/**
 * OpenRegister UploadService
 *
 * Service class for handling file and JSON uploads in the OpenRegister application.
 *
 * This service provides methods for:
 * - Processing uploaded JSON data
 * - Retrieving JSON data from URLs
 * - Parsing YAML data
 * - Handling schema resolution and validation
 * - Managing relations and linked data (extending objects with related sub-objects)
 * - CRUD operations on objects
 * - Audit trails and data aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Files\Service;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Parser;
use OCA\OpenRegister\Service\Security\SafeFetchUrl;

/**
 * UploadService handles file and JSON uploads
 *
 * Service for handling file and JSON uploads in the OpenRegister application.
 * This service processes uploaded JSON data, either directly via a POST body,
 * from a provided URL, or from an uploaded file. It supports multiple data
 * formats (e.g., JSON, YAML) and integrates with schemas and registers for
 * database updates.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class UploadService implements UploadServiceInterface {

	/**
	 * HTTP client
	 *
	 * Used for fetching JSON data from URLs.
	 *
	 * @var Client HTTP client instance
	 */
	private readonly Client $client;

	/**
	 * Constructor.
	 *
	 * WHY THIS EXISTS. `$client` was declared `readonly` and never assigned —
	 * this class had no constructor — so `getUploadedJson()` died with
	 *
	 *   Typed property UploadService::$client must not be accessed before
	 *   initialization
	 *
	 * the moment an upload used the `url` branch. A fatal, not a degraded path.
	 * Same defect as NotificationService and EndpointService in this repo.
	 *
	 * Guzzle's Client is not a Nextcloud service, so it is constructed here with
	 * a default rather than injected; the parameter stays optional so a test can
	 * pass a mock handler.
	 *
	 * @param Client|null $client HTTP client; a default Guzzle client when null.
	 */
	public function __construct(?Client $client = null) {
		$this->client = ($client ?? new Client());
	}//end __construct()

	/**
	 * Gets the uploaded JSON from the request data and returns it as a PHP array
	 *
	 * Processes uploaded JSON data from multiple sources in priority order:
	 * 1. Uploaded file (if 'file' key present)
	 * 2. URL (if 'url' key present - fetches JSON from URL)
	 * 3. Direct JSON (if 'json' key present - JSON string or array)
	 *
	 * Removes internal parameters (starting with '_') before processing.
	 *
	 * @param array<string, mixed> $data All request parameters
	 *
	 * @return array<string, mixed>|JSONResponse PHP array with uploaded JSON data or JSONResponse with error message
	 *
	 * @throws \Exception If file processing fails
	 * @throws \GuzzleHttp\Exception\GuzzleException If URL fetching fails
	 *
	 * @spec exclude Source-dispatch helper routing to per-source (file/url/json) processors; no business rule of its own.
	 */
	public function getUploadedJson(array $data): array|JSONResponse {
		// Remove internal parameters (starting with '_').
		$data = $this->removeInternalParameters(data: $data);

		// Validate upload source is provided.
		$validationError = $this->validateUploadSource(data: $data);
		if ($validationError !== null) {
			return $validationError;
		}

		// Process based on upload source type.
		if (empty($data['file']) === false) {
			// File upload handling - throws Exception (not yet implemented).
			$this->processFileUpload(_file: $data['file']);
		}

		if (empty($data['url']) === false) {
			return $this->processUrlUpload(url: $data['url']);
		}

		// Process direct JSON input.
		return $this->processJsonUpload(jsonInput: $data['json']);
	}//end getUploadedJson()

	/**
	 * Remove internal parameters from data array
	 *
	 * Internal parameters start with '_' and are used for pagination, filtering, etc.
	 *
	 * @param array<string, mixed> $data Input data array.
	 *
	 * @return array<string, mixed> Data array with internal parameters removed.
	 */
	private function removeInternalParameters(array $data): array {
		foreach (array_keys($data) as $key) {
			if (str_starts_with($key, '_') === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end removeInternalParameters()

	/**
	 * Validate that an upload source is provided
	 *
	 * @param array<string, mixed> $data Input data array.
	 *
	 * @return JSONResponse|null Error response if validation fails, null if valid.
	 *
	 * @psalm-return JSONResponse<400,
	 *     array{error: 'Missing one of these keys in your POST body: file, url or json.'},
	 *     array<never, never>>|null
	 */
	private function validateUploadSource(array $data): ?JSONResponse {
		$allowedKeys = ['file', 'url', 'json'];
		$matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

		if (count($matchingKeys) === 0) {
			return new JSONResponse(
				data: ['error' => 'Missing one of these keys in your POST body: file, url or json.'],
				statusCode: 400
			);
		}

		return null;
	}//end validateUploadSource()

	/**
	 * Process file upload source
	 *
	 * @param mixed $_file File upload data
	 *
	 * @return never Processed data or error response.
	 *
	 * @throws \Exception If file processing fails.
	 *
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 */
	private function processFileUpload(mixed $_file): never {
		// @todo use .json file content from POST as $json.
		// Method always throws, so this is unreachable but kept for API compatibility.
		$this->getJSONfromFile();
	}//end processFileUpload()

	/**
	 * Process URL upload source
	 *
	 * @param string $url URL to fetch data from.
	 *
	 * @return array|JSONResponse Upload result or error response.
	 */
	private function processUrlUpload(string $url): array|JSONResponse {
		$result = $this->getJSONfromURL(url: $url);

		// Handle array response (direct array return).
		if (is_array($result) === true) {
			$result['source'] = $url;
			return $result;
		}

		// If it's a JSONResponse (error case), return it directly.
		return $result;
	}//end processUrlUpload()

	/**
	 * Process direct JSON upload
	 *
	 * @param mixed $jsonInput JSON input (string or array).
	 *
	 * @return array<string, mixed>|JSONResponse Processed data or error response.
	 */
	private function processJsonUpload(mixed $jsonInput): array|JSONResponse {
		$phpArray = $jsonInput;

		// Decode JSON string if input is a string.
		if (is_string($phpArray) === true) {
			$phpArray = json_decode($phpArray, associative: true);
		}

		// Validate that JSON decoding succeeded.
		if ($phpArray === null || $phpArray === false) {
			return new JSONResponse(data: ['error' => 'Failed to decode JSON input.'], statusCode: 400);
		}

		return $phpArray;
	}//end processJsonUpload()

	/**
	 * Uses Guzzle to call the given URL and returns response as PHP array
	 *
	 * Fetches JSON or YAML data from a remote URL using HTTP GET request.
	 * Automatically detects content type and parses accordingly.
	 *
	 * @param string $url The URL to fetch JSON/YAML data from
	 *
	 * @return array<string, mixed>|JSONResponse The response converted to PHP array or JSONResponse with error message
	 *
	 * @throws GuzzleException If HTTP request fails
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SafeFetchUrl::assert is the shared static SSRF guard
	 */
	private function getJSONfromURL(string $url): array|JSONResponse {
		// Anti-SSRF: reject non-public/loopback/private hosts before fetching (SEC-SVC-1).
		try {
			SafeFetchUrl::assert(url: $url);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => 'URL is not allowed: ' . $e->getMessage()], statusCode: 400);
		}

		$response = $this->fetch(url: $url);
		if ($response instanceof JSONResponse) {
			return $response;
		}

		return self::decodedBody(response: $response);
	}//end getJSONfromURL()

	/**
	 * GET the URL without following redirects.
	 *
	 * @param string $url The validated URL.
	 *
	 * @return ResponseInterface|JSONResponse The response, or a 400 when the request fails.
	 *
	 * @throws GuzzleException If HTTP request fails other than with a bad response
	 */
	private function fetch(string $url): ResponseInterface|JSONResponse {
		try {
			// Step 1: Make HTTP GET request to fetch data from URL.
			// Redirects are disabled so the validated host cannot be swapped
			// for a private/internal target via a 3xx Location header.
			return $this->client->request('GET', $url, ['allow_redirects' => false]);
		} catch (\GuzzleHttp\Exception\BadResponseException $e) {
			// Return error response if HTTP request fails.
			$errorMsg = 'Failed to do a GET api-call on url: ' . $url . ' ' . $e->getMessage();
			return new JSONResponse(data: ['error' => $errorMsg], statusCode: 400);
		}
	}//end fetch()

	/**
	 * The response body as a PHP array, or a 400 when it parses as neither JSON nor YAML.
	 *
	 * @param ResponseInterface $response The response.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private static function decodedBody(ResponseInterface $response): array|JSONResponse {
		$phpArray = self::parsedBody(response: $response);
		if ($phpArray === null || $phpArray === false) {
			return new JSONResponse(data: ['error' => 'Failed to parse response body as JSON or YAML.'], statusCode: 400);
		}

		return $phpArray;
	}//end decodedBody()

	/**
	 * Parse the response body as the Content-Type says.
	 *
	 * Supports JSON and YAML formats based on Content-Type. If Content-Type is
	 * not specified or not recognized, try to parse as JSON first, then YAML.
	 * YAML is parsed by a Symfony Yaml Parser, as Yaml::parse() itself does.
	 *
	 * @param ResponseInterface $response The response.
	 *
	 * @return mixed The parsed body; null or false when it does not parse.
	 */
	private static function parsedBody(ResponseInterface $response): mixed {
		// Step 2: Get response body content as string.
		$responseBody = $response->getBody()->getContents();

		// Step 3: Use Content-Type header to determine the data format.
		return match ($response->getHeaderLine('Content-Type')) {
			'application/json' => json_decode(json: $responseBody, associative: true),
			'application/yaml' => (new Parser())->parse(value: $responseBody),
			default => (json_decode(json: $responseBody, associative: true) ?? (new Parser())->parse(value: $responseBody)),
		};
	}//end parsedBody()

	/**
	 * Gets JSON content from an uploaded file.
	 *
	 * @return never The parsed JSON content from the file or an error response.
	 *
	 * @throws \Exception If the file cannot be read or its content cannot be parsed as JSON.
	 */
	private function getJSONfromFile(): never {
		// @todo: Implement file reading logic here.
		// For now, return a simple array to ensure code consistency.
		throw new Exception('File upload handling is not yet implemented');
	}//end getJSONfromFile()
}//end class
