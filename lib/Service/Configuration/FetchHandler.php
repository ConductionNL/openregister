<?php

/**
 * OpenRegister Fetch Handler
 *
 * This file contains the handler class for fetching configuration data
 * from remote sources (URLs, GitHub, GitLab).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Configuration;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Handler for fetching configuration data from remote sources.
 *
 * This handler is responsible for:
 * - Fetching JSON/YAML data from URLs
 * - Fetching configurations from GitHub
 * - Fetching configurations from GitLab
 * - Parsing and decoding response data
 * - Error handling for remote requests
 *
 * By separating fetching logic into its own handler, we avoid circular
 * dependencies between ConfigurationService and PreviewHandler.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class FetchHandler {

	/**
	 * HTTP client for making requests.
	 *
	 * @var Client The Guzzle HTTP client.
	 */
	private readonly Client $client;

	/**
	 * Logger for logging operations.
	 *
	 * @var LoggerInterface The logger interface.
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for FetchHandler.
	 *
	 * @param Client $client The HTTP client.
	 * @param LoggerInterface $logger The logger interface.
	 */
	public function __construct(
		Client $client,
		LoggerInterface $logger,
	) {
		$this->client = $client;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Fetch JSON or YAML data from a URL.
	 *
	 * This method performs a GET request to the specified URL and attempts to
	 * parse the response as JSON or YAML based on the Content-Type header.
	 *
	 * @param string $url The URL to fetch from.
	 *
	 * @return array|JSONResponse The parsed data array or error response.
	 *
	 * @throws \Exception If the request fails or parsing fails.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getJSONfromURL(string $url): array|JSONResponse {
		try {
			$this->logger->debug(
				message: "[FetchHandler] Fetching data from URL: {$url}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$response = $this->client->request('GET', $url);
		} catch (GuzzleException $e) {
			$errorMessage = 'Failed to do a GET api-call on url: ' . $url . ' ' . $e->getMessage();
			$this->logger->error(
				message: '[FetchHandler] ' . $errorMessage,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
		}

		$responseBody = $response->getBody()->getContents();
		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray = $this->decode(data: $responseBody, type: $contentType);

		if ($phpArray === null) {
			$error = 'Failed to parse response body as JSON or YAML';
			$this->logger->error(
				message: '[FetchHandler] ' . $error,
				context: ['file' => __FILE__, 'line' => __LINE__, 'Content-Type' => $contentType, 'url' => $url]
			);
			return new JSONResponse(
				data: ['error' => $error, 'Content-Type' => $contentType],
				statusCode: 400
			);
		}

		$this->logger->debug(
			message: "[FetchHandler] Successfully fetched and parsed data from URL: {$url}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $phpArray;
	}//end getJSONfromURL()

	/**
	 * Fetch remote configuration data for a Configuration entity.
	 *
	 * This method fetches the latest configuration data from the remote source
	 * specified in the Configuration entity (GitHub, GitLab, or direct URL).
	 *
	 * @param Configuration $configuration The configuration entity with source URL.
	 *
	 * @return array|JSONResponse The fetched configuration data or error response.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse {
		// Only fetch from remote sources.
		if ($configuration->isRemoteSource() === false) {
			return new JSONResponse(
				data: ['error' => 'Configuration is not from a remote source'],
				statusCode: 400
			);
		}

		$sourceUrl = $configuration->getSourceUrl();
		if (empty($sourceUrl) === true) {
			return new JSONResponse(
				data: ['error' => 'Configuration has no source URL'],
				statusCode: 400
			);
		}

		try {
			$this->logger->info(
				message: "[FetchHandler] Fetching remote configuration from: {$sourceUrl}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			// Use getJSONfromURL to fetch and parse the remote configuration.
			$remoteData = $this->getJSONfromURL(url: $sourceUrl);

			if ($remoteData instanceof JSONResponse) {
				return $remoteData;
			}

			$schemaCount = count($remoteData['components']['schemas'] ?? []);
			$registerCount = count($remoteData['components']['registers'] ?? []);
			$msg = '[FetchHandler] Fetched config: ' . $schemaCount . ' schemas, ' . $registerCount . ' registers';
			$this->logger->info(
				message: $msg,
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $remoteData;
		} catch (GuzzleException $e) {
			$this->logger->error(
				message: '[FetchHandler] Failed to fetch remote configuration: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: ['error' => 'Failed to fetch remote configuration: ' . $e->getMessage()],
				statusCode: 500
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[FetchHandler] Unexpected error fetching remote configuration: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return new JSONResponse(
				data: ['error' => 'Unexpected error: ' . $e->getMessage()],
				statusCode: 500
			);
		}//end try
	}//end fetchRemoteConfiguration()

	/**
	 * Decode a fetched body as JSON, then YAML, whatever its Content-Type.
	 *
	 * The Content-Type only says which to try first. raw.githubusercontent.com
	 * and Nextcloud's public DAV serve every .json and .yaml file as
	 * text/plain, and reading the header as a gate made every configuration
	 * on such a URL impossible to preview, import or update (live pass O1).
	 *
	 * @param string $data The data to decode.
	 * @param string $type The Content-Type header value.
	 *
	 * @return array|null The decoded array or null when neither parses to an array.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-remote-configuration-is-read-by-its-content-not-its-content-type
	 */
	private function decode(string $data, string $type): ?array {
		$yamlFirst = (str_contains($type, 'yaml') === true || str_contains($type, 'yml') === true);

		if ($yamlFirst === false) {
			$decoded = json_decode($data, associative: true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		try {
			$decoded = Yaml::parse($data);
			if (is_array($decoded) === true) {
				if (str_contains($type, 'json') === true) {
					$this->logger->info(
						message: '[FetchHandler] Content-Type was JSON but data was successfully parsed as YAML',
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
				}

				return $decoded;
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[FetchHandler] Body is neither JSON nor YAML: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'Content-Type' => $type]
			);
		}

		return null;
	}//end decode()
}//end class
