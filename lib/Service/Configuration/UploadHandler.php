<?php
/**
 * OpenRegister Upload Handler
 *
 * This file contains the handler class for processing file uploads
 * and parsing JSON/YAML data in the OpenRegister application.
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

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Yaml\Yaml;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Class UploadHandler
 *
 * Handles file uploads and JSON/YAML parsing for configuration imports.
 *
 * @package OCA\OpenRegister\Service\Configuration
 */
class UploadHandler
{

    /**
     * HTTP Client for making external requests.
     *
     * @var Client The HTTP client instance.
     */
    private readonly Client $client;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor for UploadHandler.
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
     * Gets the uploaded json from the request data and returns it as a PHP array.
     *
     * Will first try to find an uploaded 'file', then if an 'url' is present in the body,
     * and lastly if a 'json' dump has been posted.
     *
     * @param array      $data          All request params.
     * @param array|null $uploadedFiles The uploaded files array.
     *
     * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
     *
     * @throws Exception
     * @throws GuzzleException
     */
    public function getUploadedJson(array $data, ?array $uploadedFiles): array | JSONResponse
    {
        // Define the allowed keys for input validation.
        $allowedKeys = ['url', 'json'];

        // Find which of the allowed keys are in the array for processing.
        $matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

        // Check if there is no matching key or no input provided.
        if (count($matchingKeys) === 0 && empty($uploadedFiles) === true) {
            $errorMessage = 'Missing required keys in POST body: url, json, or file in form-data.';
            return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
        }

        // Process uploaded files if present.
        if (empty($uploadedFiles) === false) {
            if (count($uploadedFiles) === 1) {
                return $this->getJSONfromFile(uploadedFile: $uploadedFiles[array_key_first($uploadedFiles)]);
            }

            return new JSONResponse(data: ['message' => 'Expected only 1 file.'], statusCode: 400);
        }

        // Process URL if provided in the post body.
        if (empty($data['url']) === false) {
            return $this->getJSONfromURL(url: $data['url']);
        }

        // Process JSON blob from the post body.
        return $this->getJSONfromBody($data['json']);

    }//end getUploadedJson()


    /**
     * Decode file content or URL response.
     *
     * This method decodes JSON or YAML data based on the content type.
     *
     * @param string      $data The file content or response body content.
     * @param string|null $type The file MIME type or response Content-Type header.
     *
     * @return array|null The decoded data or null.
     */
    private function decode(string $data, ?string $type): ?array
    {
        switch ($type) {
            case 'application/json':
                $phpArray = json_decode(json: $data, associative: true);
                break;
            case 'application/yaml':
                $phpArray = Yaml::parse(input: $data);
                break;
            default:
                // If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML.
                $phpArray = json_decode(json: $data, associative: true);
                if ($phpArray === null || $phpArray === false) {
                    try {
                        $phpArray = Yaml::parse(input: $data);
                    } catch (Exception $exception) {
                        $phpArray = null;
                    }
                }
                break;
        }

        if ($phpArray === null || $phpArray === false) {
            return null;
        }

        // Ensure all data is consistently arrays by converting any stdClass objects.
        $phpArray = $this->ensureArrayStructure($phpArray);

        return $phpArray;

    }//end decode()


    /**
     * Recursively converts stdClass objects to arrays to ensure consistent data structure.
     *
     * @param mixed $data The data to convert.
     *
     * @return array The converted array data.
     */
    private function ensureArrayStructure(mixed $data): array
    {
        if (is_object($data) === true) {
            $data = (array) $data;
        }

        if (is_array($data) === true) {
            foreach ($data as $key => $value) {
                if (is_object($value) === true) {
                    $data[$key] = $this->ensureArrayStructure($value);
                } else if (is_array($value) === true) {
                    $data[$key] = $this->ensureArrayStructure($value);
                }
            }
        }

        return $data;

    }//end ensureArrayStructure()


    /**
     * Gets uploaded file content from a file in the api request as PHP array.
     *
     * @param array       $uploadedFile The uploaded file.
     * @param string|null $type         If the uploaded file should be a specific type of object.
     *
     * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
     *
     * @psalm-return JSONResponse<400, array{error: string, 'MIME-type'?: string}, array<never, never>>|array
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function getJSONfromFile(array $uploadedFile, ?string $_type=null): array|JSONResponse
    {
        // Check for upload errors.
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                data: ['error' => 'File upload error: '.$uploadedFile['error']],
                statusCode: 400
            );
        }

        $fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
        $fileContent   = file_get_contents(filename: $uploadedFile['tmp_name']);

        $phpArray = $this->decode(data: $fileContent, type: $fileExtension);
        if ($phpArray === null) {
            return new JSONResponse(
                data: ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension],
                statusCode: 400
            );
        }

        return $phpArray;

    }//end getJSONfromFile()


    /**
     * Uses Guzzle to call the given URL and returns response as PHP array.
     *
     * @param string $url The URL to call.
     *
     * @return JSONResponse|array The response from the call converted to PHP array or JSONResponse in case of an error.
     *
     * @throws GuzzleException
     *
     * @psalm-return JSONResponse<400, array{error: string, 'Content-Type'?: string}, array<never, never>>|array
     */
    private function getJSONfromURL(string $url): array|JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            return new JSONResponse(
                data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()],
                statusCode: 400
            );
        }

        $responseBody = $response->getBody()->getContents();

        // Use Content-Type header to determine the format.
        $contentType = $response->getHeaderLine('Content-Type');
        $phpArray    = $this->decode(data: $responseBody, type: $contentType);

        if ($phpArray === null) {
            return new JSONResponse(
                data: ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType],
                statusCode: 400
            );
        }

        return $phpArray;

    }//end getJSONfromURL()


    /**
     * Uses the given string or array as PHP array for creating/updating an object.
     *
     * @param array|string $phpArray An array or string containing a json blob of data.
     * @param string|null  $type     If the object should be a specific type of object.
     *
     * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
     *
     * @psalm-return JSONResponse<400, array{error: 'Failed to decode JSON input'}, array<never, never>>|array
     */
    private function getJSONfromBody(array | string $phpArray): array|JSONResponse
    {
        if (is_string($phpArray) === true) {
            $phpArray = json_decode($phpArray, associative: true);
        }

        if ($phpArray === null || $phpArray === false) {
            return new JSONResponse(
                data: ['error' => 'Failed to decode JSON input'],
                statusCode: 400
            );
        }

        // Ensure all data is consistently arrays by converting any stdClass objects.
        $phpArray = $this->ensureArrayStructure($phpArray);

        return $phpArray;

    }//end getJSONfromBody()


}//end class
