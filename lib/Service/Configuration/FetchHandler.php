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
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Parser;

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
		$response = $this->get(url: $url);
		if ($response instanceof JSONResponse) {
			return $response;
		}

		return $this->parsed(response: $response, url: $url);
	}//end getJSONfromURL()

	/**
	 * Do a GET request on a URL, logged.
	 *
	 * @param string $url The URL to fetch from.
	 *
	 * @return ResponseInterface|JSONResponse The response, or a 400 when the request fails.
	 */
	private function get(string $url): ResponseInterface|JSONResponse {
		try {
			$this->logger->debug(
				message: "[FetchHandler] Fetching data from URL: {$url}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->client->request('GET', $url);
		} catch (GuzzleException $e) {
			$errorMessage = 'Failed to do a GET api-call on url: ' . $url . ' ' . $e->getMessage();
			$this->logger->error(message: '[FetchHandler] ' . $errorMessage, context: ['file' => __FILE__, 'line' => __LINE__]);
			return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
		}
	}//end get()

	/**
	 * A response's body as PHP array, decoded by its Content-Type, logged.
	 *
	 * @param ResponseInterface $response The response.
	 * @param string            $url      The URL it came from.
	 *
	 * @return array|JSONResponse The parsed data array, or a 400 when the body is neither JSON nor YAML.
	 */
	private function parsed(ResponseInterface $response, string $url): array|JSONResponse {
		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray    = $this->decode(data: $response->getBody()->getContents(), type: $contentType);
		if ($phpArray === null) {
			return $this->unparsable(contentType: $contentType, url: $url);
		}

		$this->logger->debug(
			message: "[FetchHandler] Successfully fetched and parsed data from URL: {$url}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $phpArray;
	}//end parsed()

	/**
	 * The 400 for a body that is neither JSON nor YAML, logged.
	 *
	 * @param string $contentType The response Content-Type header.
	 * @param string $url         The URL it came from.
	 *
	 * @return JSONResponse
	 */
	private function unparsable(string $contentType, string $url): JSONResponse {
		$error = 'Failed to parse response body as JSON or YAML';
		$this->logger->error(
			message: '[FetchHandler] ' . $error,
			context: ['file' => __FILE__, 'line' => __LINE__, 'Content-Type' => $contentType, 'url' => $url]
		);
		return new JSONResponse(data: ['error' => $error, 'Content-Type' => $contentType], statusCode: 400);
	}//end unparsable()

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
		$refusal = $this->sourceRefusal(configuration: $configuration);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->fetchRemote(sourceUrl: $configuration->getSourceUrl());
		} catch (GuzzleException $e) {
			$error = 'Failed to fetch remote configuration: ' . $e->getMessage();
			return $this->failure(logged: $error, answered: $error);
		} catch (\Exception $e) {
			$message = $e->getMessage();
			return $this->failure(logged: 'Unexpected error fetching remote configuration: ' . $message, answered: 'Unexpected error: ' . $message);
		}
	}//end fetchRemoteConfiguration()

	/**
	 * The 400 for a configuration that is not fetched from a remote source, or null.
	 *
	 * @param Configuration $configuration The configuration entity.
	 *
	 * @return JSONResponse|null
	 */
	private function sourceRefusal(Configuration $configuration): ?JSONResponse {
		// Only fetch from remote sources.
		if ($configuration->isRemoteSource() === false) {
			return new JSONResponse(data: ['error' => 'Configuration is not from a remote source'], statusCode: 400);
		}

		if (empty($configuration->getSourceUrl()) === true) {
			return new JSONResponse(data: ['error' => 'Configuration has no source URL'], statusCode: 400);
		}

		return null;
	}//end sourceRefusal()

	/**
	 * Fetch and parse a remote configuration, logging what it holds.
	 *
	 * @param string $sourceUrl The configuration's source URL.
	 *
	 * @return array|JSONResponse The fetched configuration data or error response.
	 */
	private function fetchRemote(string $sourceUrl): array|JSONResponse {
		$this->logger->info(
			message: "[FetchHandler] Fetching remote configuration from: {$sourceUrl}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		$remoteData = $this->getJSONfromURL(url: $sourceUrl);
		if ($remoteData instanceof JSONResponse) {
			return $remoteData;
		}

		$schemaCount   = count($remoteData['components']['schemas'] ?? []);
		$registerCount = count($remoteData['components']['registers'] ?? []);
		$msg           = '[FetchHandler] Fetched config: ' . $schemaCount . ' schemas, ' . $registerCount . ' registers';
		$this->logger->info(message: $msg, context: ['file' => __FILE__, 'line' => __LINE__]);

		return $remoteData;
	}//end fetchRemote()

	/**
	 * The 500 for a fetch that threw, logged.
	 *
	 * @param string $logged   What is logged, after the handler's prefix.
	 * @param string $answered The error answered.
	 *
	 * @return JSONResponse
	 */
	private function failure(string $logged, string $answered): JSONResponse {
		$this->logger->error(message: '[FetchHandler] ' . $logged, context: ['file' => __FILE__, 'line' => __LINE__]);
		return new JSONResponse(data: ['error' => $answered], statusCode: 500);
	}//end failure()

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

		return $this->yamlOrNull(data: $data, type: $type);
	}//end decode()

	/**
	 * Decode a body as YAML, or log why it is neither JSON nor YAML.
	 *
	 * @param string $data The data to decode.
	 * @param string $type The Content-Type header value.
	 *
	 * @return array|null The decoded array or null when it does not parse to an array.
	 */
	private function yamlOrNull(string $data, string $type): ?array {
		try {
			return $this->decodeYaml(data: $data, type: $type);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[FetchHandler] Body is neither JSON nor YAML: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'Content-Type' => $type]
			);
		}

		return null;
	}//end yamlOrNull()

	/**
	 * Decode a body as YAML, noting when its Content-Type said JSON.
	 *
	 * @param string $data The data to decode.
	 * @param string $type The Content-Type header value.
	 *
	 * @return array|null The decoded array or null when it does not parse to an array.
	 */
	private function decodeYaml(string $data, string $type): ?array {
		$decoded = (new Parser())->parse(value: $data);
		if (is_array($decoded) === false) {
			return null;
		}

		if (str_contains($type, 'json') === true) {
			$this->logger->info(
				message: '[FetchHandler] Content-Type was JSON but data was successfully parsed as YAML',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $decoded;
	}//end decodeYaml()
}//end class
