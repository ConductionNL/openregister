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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for handling file and JSON uploads.
 *
 * This service processes uploaded JSON data, either directly via a POST body,
 * from a provided URL, or from an uploaded file. It supports multiple data
 * formats (e.g., JSON, YAML) and integrates with schemas and registers for
 * database updates.
 */
class UploadService
{

    /**
     * HTTP client
     *
     * @var Client
     */
    private Client $client;


    /**
     * Gets the uploaded json from the request data. And returns it as a PHP array.
     * Will first try to find an uploaded 'file', then if an 'url' is present in the body and lastly if a 'json' dump has been posted.
     *
     * @param array $data All request params.
     *
     * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getUploadedJson(array $data): array | JSONResponse
    {
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Define the allowed keys.
        $allowedKeys = ['file', 'url', 'json'];

        // Find which of the allowed keys are in the array.
        $matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

        // Check if there is exactly one matching key.
        if (count($matchingKeys) === 0) {
            return new JSONResponse(data: ['error' => 'Missing one of these keys in your POST body: file, url or json.'], statusCode: 400);
        }

        if (empty($data['file']) === false) {
            // @todo use .json file content from POST as $json.
            //
            return $this->getJSONfromFile();
        }

        if (empty($data['url']) === false) {
            $phpArray = $this->getJSONfromURL($data['url']);
            // JSONResponse doesn't implement ArrayAccess, convert to array.
            if (is_array($phpArray)) {
                $phpArray['source'] = $data['url'];
                return $phpArray;
            }

            // If it's a JSONResponse, extract data.
            if (method_exists($phpArray, 'getData')) {
                $phpArrayData = $phpArray->getData();
                if (is_array($phpArrayData)) {
                    $phpArrayData['source'] = $data['url'];
                    return $phpArrayData;
                }
            }

            // Fallback: return error response.
            return new JSONResponse(data: ['error' => 'Failed to parse JSON from URL'], statusCode: 400);
        }

        $phpArray = $data['json'];
        if (is_string($phpArray) === true) {
            $phpArray = json_decode($phpArray, associative: true);
        }

        if ($phpArray === null || $phpArray === false) {
            return new JSONResponse(data: ['error' => 'Failed to decode JSON input.'], statusCode: 400);
        }

        return $phpArray;

    }//end getUploadedJson()


    /**
     * Uses Guzzle to call the given URL and returns response as PHP array.
     *
     * @param string $url The URL to call.
     *
     * @throws GuzzleException
     *
     * @return array|JSONResponse The response from the call converted to PHP array or JSONResponse in case of an error.
     */
    private function getJSONfromURL(string $url): array | JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()], statusCode: 400);
        }

        $responseBody = $response->getBody()->getContents();

        // Use Content-Type header to determine the format.
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
        throw new \Exception('File upload handling is not yet implemented');

    }//end getJSONfromFile()


}//end class
