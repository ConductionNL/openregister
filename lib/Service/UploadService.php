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

namespace OCA\OpenRegister\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

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
 */
class UploadService
{

    /**
     * HTTP client
     *
     * Used for fetching JSON data from URLs.
     *
     * @var Client HTTP client instance
     */
    private readonly Client $client;

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
     */
    public function getUploadedJson(array $data): array | JSONResponse
    {
        // Remove internal parameters (starting with '_').
        $data = $this->removeInternalParameters($data);

        // Validate upload source is provided.
        $validationError = $this->validateUploadSource($data);
        if ($validationError !== null) {
            return $validationError;
        }

        // Process based on upload source type.
        if (empty($data['file']) === false) {
            // File upload handling - throws Exception (not yet implemented).
            $this->processFileUpload($data['file']);
        }

        if (empty($data['url']) === false) {
            return $this->processUrlUpload($data['url']);
        }

        // Process direct JSON input.
        return $this->processJsonUpload($data['json']);
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
    private function removeInternalParameters(array $data): array
    {
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
    private function validateUploadSource(array $data): JSONResponse|null
    {
        $allowedKeys  = ['file', 'url', 'json'];
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
    private function processFileUpload(mixed $_file): never
    {
        // @todo use .json file content from POST as $json.
        // Method always throws, so this is unreachable but kept for API compatibility.
        $this->getJSONfromFile();
    }//end processFileUpload()

    /**
     * Process URL upload source
     *
     * @param string $url URL to fetch data from.
     *
     * @return array<string, mixed>|JSONResponse Processed data or error response.
     */
    private function processUrlUpload(string $url): array | JSONResponse
    {
        $result = $this->getJSONfromURL($url);

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
    private function processJsonUpload(mixed $jsonInput): array | JSONResponse
    {
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
     */
    private function getJSONfromURL(string $url): array | JSONResponse
    {
        try {
            // Step 1: Make HTTP GET request to fetch data from URL.
            $response = $this->client->request('GET', $url);
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            // Return error response if HTTP request fails.
            $errorMsg = 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage();
            return new JSONResponse(data: ['error' => $errorMsg], statusCode: 400);
        }

        // Step 2: Get response body content as string.
        $responseBody = $response->getBody()->getContents();

        // Step 3: Use Content-Type header to determine the data format.
        // Supports JSON and YAML formats based on Content-Type.
        $contentType = $response->getHeaderLine('Content-Type');
        switch ($contentType) {
            case 'application/json':
                $phpArray = json_decode(json: $responseBody, associative: true);
                break;
            case 'application/yaml':
                $phpArray = Yaml::parse(input: $responseBody);
                break;
            default:
                // If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML.
                $phpArray = json_decode(json: $responseBody, associative: true);
                if ($phpArray === null) {
                    $phpArray = Yaml::parse(input: $responseBody);
                }
                break;
        }

        if ($phpArray === null || $phpArray === false) {
            return new JSONResponse(data: ['error' => 'Failed to parse response body as JSON or YAML.'], statusCode: 400);
        }

        return $phpArray;
    }//end getJSONfromURL()

    /**
     * Gets JSON content from an uploaded file.
     *
     * @return never The parsed JSON content from the file or an error response.
     *
     * @throws \Exception If the file cannot be read or its content cannot be parsed as JSON.
     */
    private function getJSONfromFile(): never
    {
        // @todo: Implement file reading logic here.
        // For now, return a simple array to ensure code consistency.
        throw new Exception('File upload handling is not yet implemented');
    }//end getJSONfromFile()
}//end class
