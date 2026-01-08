<?php

/**
 * OpenRegister Fetch Handler
 *
 * This file contains the handler class for fetching configuration data
 * from remote sources (URLs, GitHub, GitLab).
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
 */
class FetchHandler
{

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
     * @param Client          $client The HTTP client.
     * @param LoggerInterface $logger The logger interface.
     */
    public function __construct(
        Client $client,
        LoggerInterface $logger
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
     */
    public function getJSONfromURL(string $url): array|JSONResponse
    {
        try {
            $this->logger->debug("Fetching data from URL: {$url}");
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            $errorMessage = 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage();
            $this->logger->error($errorMessage);
            return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
        }

        $responseBody = $response->getBody()->getContents();
        $contentType  = $response->getHeaderLine('Content-Type');
        $phpArray     = $this->decode(data: $responseBody, type: $contentType);

        if ($phpArray === null) {
            $error = 'Failed to parse response body as JSON or YAML';
            $this->logger->error($error, ['Content-Type' => $contentType, 'url' => $url]);
            return new JSONResponse(
                data: ['error' => $error, 'Content-Type' => $contentType],
                statusCode: 400
            );
        }

        $this->logger->debug("Successfully fetched and parsed data from URL: {$url}");
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
     */
    public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse
    {
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
            $this->logger->info("Fetching remote configuration from: {$sourceUrl}");

            // Use getJSONfromURL to fetch and parse the remote configuration.
            $remoteData = $this->getJSONfromURL($sourceUrl);

            if ($remoteData instanceof JSONResponse) {
                return $remoteData;
            }

            $schemaCount   = count($remoteData['components']['schemas'] ?? []);
            $registerCount = count($remoteData['components']['registers'] ?? []);
            $this->logger->info(
                "Successfully fetched remote configuration with {$schemaCount} schemas and {$registerCount} registers"
            );

            return $remoteData;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to fetch remote configuration: ".$e->getMessage());
            return new JSONResponse(
                data: ['error' => 'Failed to fetch remote configuration: '.$e->getMessage()],
                statusCode: 500
            );
        } catch (\Exception $e) {
            $this->logger->error("Unexpected error fetching remote configuration: ".$e->getMessage());
            return new JSONResponse(
                data: ['error' => 'Unexpected error: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end fetchRemoteConfiguration()

    /**
     * Decode data based on content type.
     *
     * Attempts to decode the data as JSON or YAML based on the Content-Type header.
     *
     * @param string $data The data to decode.
     * @param string $type The Content-Type header value.
     *
     * @return array|null The decoded array or null on failure.
     */
    private function decode(string $data, string $type): ?array
    {
        // Try JSON first (most common).
        if (str_contains($type, 'json') === true || empty($type) === true) {
            $decoded = json_decode($data, associative: true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
        }

        // Try YAML if JSON failed or if Content-Type suggests YAML.
        if (str_contains($type, 'yaml') === true || str_contains($type, 'yml') === true) {
            try {
                $decoded = Yaml::parse($data);
                if (is_array($decoded) === true) {
                    return $decoded;
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to parse as YAML: ".$e->getMessage());
            }
        }

        // If JSON detection failed, try YAML as fallback.
        if (str_contains($type, 'json') === true) {
            try {
                $decoded = Yaml::parse($data);
                if (is_array($decoded) === true) {
                    $this->logger->info("Content-Type was JSON but data was successfully parsed as YAML");
                    return $decoded;
                }
            } catch (\Exception $e) {
                // YAML parsing also failed, return null.
            }
        }

        return null;
    }//end decode()
}//end class
