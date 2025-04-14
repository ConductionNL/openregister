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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\BadResponseException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
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
     * Constructor for the UploadService class.
     *
     * @param Client         $client         The Guzzle HTTP client.
     * @param SchemaMapper   $schemaMapper   The schema mapper.
     * @param RegisterMapper $registerMapper The register mapper.
     *
     * @return void
     */
    public function __construct(
        private Client $client,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
    ) {
        $this->client = new Client([]);

    }//end __construct()


	/**
	 * Gets the uploaded json from the request data. And returns it as a PHP array.
	 * Will first try to find an uploaded 'file', then if an 'url' is present in the body and lastly if a 'json' dump has been posted.
	 *
	 * @param array $data All request params.
	 *
	 * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 * @throws Exception
	 * @throws GuzzleException
	 */
    public function getUploadedJson(array $data): array|JSONResponse
    {
        foreach ($data as $key => $value) {
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
            return $this->getJSONfromFile();
        }

        if (empty($data['url']) === false) {
            $phpArray           = $this->getJSONfromURL($data['url']);
            $phpArray['source'] = $data['url'];
            return $phpArray;
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
     * Gets JSON content from an uploaded file.
     *
     * @return array|JSONResponse The parsed JSON content from the file or an error response.
     * @throws Exception If the file cannot be read or its content cannot be parsed as JSON.
     */
    private function getJSONfromFile(): array|JSONResponse
    {
        // Implement file reading logic here
        // For now, return a simple array to ensure code consistency
        throw new Exception('File upload handling is not yet implemented');
    }//end getJSONfromFile()


    /**
     * Uses Guzzle to call the given URL and returns response as PHP array.
     *
     * @param string $url The URL to call.
     *
     * @throws GuzzleException
     *
     * @return array|JSONResponse The response from the call converted to PHP array or JSONResponse in case of an error.
     */
    private function getJSONfromURL(string $url): array|JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (BadResponseException $e) {
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
     * Handles adding schemas to a register during upload.
     *
     * @param Register $register The register to add schemas to.
     * @param array    $phpArray The PHP array containing the uploaded json data.
     *
     * @throws \OCP\DB\Exception
     *
     * @return Register The updated register.
     */
    public function handleRegisterSchemas(Register $register, array $phpArray): Register
    {
        // Process and save schemas.
        foreach ($phpArray['components']['schemas'] as $schemaName => $schemaData) {
            // Check if a schema with this title already exists.
            $schema = $this->registerMapper->hasSchemaWithTitle(registerId: $register->getId(), schemaTitle: $schemaName);
            if ($schema === false) {
                // Check if a schema with this title already exists for this register.
                try {
                    $schemas = $this->schemaMapper->findAll(filters: ['title' => $schemaName]);
                    if (count($schemas) > 0) {
                        $schema = $schemas[0];
                    } else {
                        // None found so, Create a new schema.
                        $schema = new Schema();
                        $schema->setTitle($schemaName);
                        $schema->setUuid(Uuid::v4());
                        $this->schemaMapper->insert($schema);
                    }
                } catch (DoesNotExistException $e) {
                    // None found so, Create a new schema.
                    $schema = new Schema();
                    $schema->setTitle($schemaName);
                    $schema->setUuid(Uuid::v4());
                    $this->schemaMapper->insert($schema);
                }
            }//end if

            $schemaData['properties'] = array_map(function($property){
                if (isset($property['title'])) {
                    unset($property['title']);
                }
                return $property;
            }, $schemaData['properties']);

            $schema->hydrate($schemaData);
            $this->schemaMapper->update($schema);
            // Add the schema to the register.
            $schemas   = $register->getSchemas();
            $schemas[] = $schema->getId();
            $register->setSchemas($schemas);
            // Let's save the updated register.
            $register = $this->registerMapper->update($register);
        }//end foreach

        return $register;

    }//end handleRegisterSchemas()


}//end class
