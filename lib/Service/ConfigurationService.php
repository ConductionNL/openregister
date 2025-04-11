<?php
/**
 * OpenRegister Configuration Service
 *
 * This file contains the service class for handling configuration imports and exports
 * in the OpenRegister application, supporting various formats including OpenAPI.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright 2024 Conduction B.V. (https://conduction.nl)
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Symfony\Component\Yaml\Yaml;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationService
 *
 * Service for importing and exporting configurations in various formats.
 *
 * @package OCA\OpenRegister\Service
 */
class ConfigurationService
{

    /**
     * Schema mapper instance for handling schema operations.
     *
     * @var SchemaMapper The schema mapper instance.
     */
    private SchemaMapper $schemaMapper;

    /**
     * Register mapper instance for handling register operations.
     *
     * @var RegisterMapper The register mapper instance.
     */
    private RegisterMapper $registerMapper;

    /**
     * Object mapper instance for handling object operations.
     *
     * @var ObjectEntityMapper The object mapper instance.
     */
    private ObjectEntityMapper $objectEntityMapper;

    /**
     * Configuration mapper instance for handling configuration operations.
     *
     * @var ConfigurationMapper The configuration mapper instance.
     */
    private ConfigurationMapper $configurationMapper;

    /**
     * OpenConnector service instance for handling OpenConnector operations.
     *
     * @var OCA\OpenConnector\Service\ConfigurationService The OpenConnector service instance.
     */
    private $openConnectorConfigurationService;

    /**
     * App manager for checking installed apps.
     *
     * @var \OCP\App\IAppManager The app manager instance.
     */
    private $appManager;

    /**
     * Container for getting services.
     *
     * @var \Psr\Container\ContainerInterface The container instance.
     */
    private $container;

    /**
     * Schema property validator instance for validating schema properties.
     *
     * @var SchemaPropertyValidator The schema property validator instance.
     */
    private SchemaPropertyValidator $validator;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Map of registers indexed by slug during import, by id during export.
     *
     * @var array<string, Register> Registers indexed by slug during import, by id during export.
     */
    private array $registersMap = [];

    /**
     * Map of schemas indexed by slug during import, by id during export.
     *
     * @var array<string, Schema> Schemas indexed by slug during import, by id during export.
     */
    private array $schemasMap = [];

    /**
     * HTTP Client for making external requests.
     *
     * @var Client The HTTP client instance.
     */
    private Client $client;

    /**
     * Constructor
     *
     * @param SchemaMapper            $schemaMapper        The schema mapper instance
     * @param RegisterMapper          $registerMapper      The register mapper instance
     * @param ObjectEntityMapper      $objectEntityMapper  The object mapper instance
     * @param ConfigurationMapper     $configurationMapper The configuration mapper instance
     * @param SchemaPropertyValidator $validator           The schema property validator instance
     * @param LoggerInterface         $logger              The logger instance
     * @param \OCP\App\IAppManager    $appManager          The app manager instance
     * @param \Psr\Container\ContainerInterface $container The container instance
     * @param Client                  $client              The HTTP client instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectEntityMapper $objectEntityMapper,
        ConfigurationMapper $configurationMapper,
        SchemaPropertyValidator $validator,
        LoggerInterface $logger,
        IAppManager $appManager,
        ContainerInterface $container,
        Client $client
    ) {
        $this->schemaMapper        = $schemaMapper;
        $this->registerMapper      = $registerMapper;
        $this->objectEntityMapper  = $objectEntityMapper;
        $this->configurationMapper = $configurationMapper;
        $this->validator           = $validator;
        $this->logger              = $logger;
        $this->appManager          = $appManager;
        $this->container           = $container;
        $this->client              = $client;
    }//end __construct()

    
	/**
	 * Attempts to retrieve the OpenConnector service from the container.
	 *
	 * @return bool True if the OpenConnector service is available, false otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	public function getOpenConnector(): bool
	{
		if (in_array(needle: 'openconnector', haystack: $this->appManager->getInstalledApps()) === true) {
			try {
				// Attempt to get the OpenConnector service from the container
				$this->openConnectorConfigurationService = $this->container->get('OCA\OpenConnector\Service\ConfigurationService');
                return true;
			} catch (Exception $e) {
				// If the service is not available, return false
				return false;
			}
		}

		return false;
	}//end getOpenConnector()


    /**
     * Build OpenAPI Specification from configuration or register
     *
     * @param array|Configuration|Register $input          The configuration array, Configuration object, or Register object to build the OAS from.
     * @param bool                         $includeObjects Whether to include objects in the registers.
     *
     * @return array The OpenAPI specification.
     *
     * @throws Exception If configuration is invalid.
     *
     * @phpstan-param array<string, mixed>|Configuration|Register $input
     * @psalm-param   array<string, mixed>|Configuration|Register $input
     */
    public function exportConfig(array | Configuration | Register $input=[], bool $includeObjects=false): array
    {
        // Reset the maps for this export.
        $this->registersMap = [];
        $this->schemasMap   = [];

        // Initialize OpenAPI specification with default values.
        $openApiSpec = [
            'openapi'    => '3.0.0',
            'components' => [
                'registers'        => [],
                'schemas'          => [],
                'endpoints'        => [],
                'sources'          => [],
                'mappings'         => [],
                'jobs'             => [],
                'synchronizations' => [],
                'rules'            => [],
                'objects'          => [],
            ],
        ];

        // Determine if input is an array, Configuration, or Register object.
        if ($input instanceof Configuration) {
            $configuration = $input;

            // Set the info from the configuration.
            $openApiSpec['info'] = [
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];
        } else if ($input instanceof Register) {
            // Pass the register as an array to the exportConfig function.
            $registers = [$input];
            // Set the info from the register.
            $openApiSpec['info'] = [
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];
        } else {
            // Get all registers associated with this configuration.
            $configuration = $this->configurationMapper->find($input['id']);
            
            // Set the info from the configuration.
            $openApiSpec['info'] = [
                'title'       => $input['title'] ?? 'Default Title',
                'description' => $input['description'] ?? 'Default Description',
                'version'     => $input['version'] ?? '1.0.0',
            ];
        }//end if

        // Get all registers associated with this configuration.
        $registers = $configuration->getRegisters();

        // Export each register and its schemas.
        foreach ($registers as $register) {
            if ($register instanceof Register === false && is_int($register) === true) {
                $register = $this->registerMapper->find($register);
            }

            // Store register in map by ID for reference.
            $this->registersMap[$register->getId()] = $register;

            // Set the base register.
            $openApiSpec['components']['registers'][$register->getSlug()] = $this->exportRegister($register);
            // Drop the schemas from the register (we need to slugify those).
            $openApiSpec['components']['registers'][$register->getSlug()]['schemas'] = [];

            // Get and export schemas associated with this register.
            $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
            foreach ($schemas as $schema) {
                // Store schema in map by ID for reference.
                $this->schemasMap[$schema->getId()] = $schema;

                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema($schema);
                $openApiSpec['components']['registers'][$register->getSlug()]['schemas'][] = $schema->getSlug();
            }

            // Optionally include objects in the register.
            if ($includeObjects === true) {
                $objects = $this->objectEntityMapper->findAll(
                    filters: ['registerId' => $register->getId()],
                    includeDeleted: false
                );
                foreach ($objects as $object) {
                    $openApiSpec['components']['objects'][$object->getSlug()] = $this->exportObject($object);
                    // Use maps to get slugs.
                    $openApiSpec['components']['objects'][$object->getSlug()]['register'] = $this->registersMap[$object->getRegisterId()]->getSlug();
                    $openApiSpec['components']['objects'][$object->getSlug()]['schema']   = $this->schemasMap[$object->getSchemaId()]->getSlug();
                }
            }

            // Get the OpenConnector service.
            $openConnector = $this->getOpenConnector();
            if ($openConnector === true) {
                $openConnectorConfig = $this->openConnectorConfigurationService->exportConfig($register->getId());
                
                // Merge the OpenAPI specification over the OpenConnector configuration.
                $openApiSpec = array_replace_recursive(
                    $openConnectorConfig,
                    $openApiSpec
                );
            }
        }//end foreach

        return $openApiSpec;

    }//end exportConfig()


    /**
     * Export a register to OpenAPI format
     *
     * @param Register $register The register to export
     *
     * @return array The OpenAPI register specification
     */
    private function exportRegister(Register $register): array
    {
        // Use jsonSerialize to get the JSON representation of the register.
        $registerArray = $register->jsonSerialize();

        // Unset id and uuid if they are present.
        unset($registerArray['id'], $registerArray['uuid']);

        return $registerArray;

    }//end exportRegister()


    /**
     * Export a schema to OpenAPI format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The OpenAPI schema specification
     */
    private function exportSchema(Schema $schema): array
    {
        // Use jsonSerialize to get the JSON representation of the schema.
        $schemaArray = $schema->jsonSerialize();

        // Unset id and uuid if they are present.
        unset($schemaArray['id'], $schemaArray['uuid']);

        return $schemaArray;

    }//end exportSchema()


    /**
     * Export an object to OpenAPI format
     *
     * @param ObjectEntity $object The object to export
     *
     * @return array The OpenAPI object specification
     */
    private function exportObject(ObjectEntity $object): array
    {
        // Use jsonSerialize to get the JSON representation of the object.
        return $object->jsonSerialize();

    }//end exportObject()


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
    public function getUploadedJson(array $data, ?array $uploadedFiles): array|JSONResponse
    {
        // Define the allowed keys
		$allowedKeys = ['url', 'json'];

		// Find which of the allowed keys are in the array
		$matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

		// Check if there is no matching key / no input.
		if (count($matchingKeys) === 0 && empty($uploadedFiles) === true) {
			return new JSONResponse(data: ['error' => 'Missing one of these keys in your POST body: url or json. Or the key file in form-data.'], statusCode: 400);
		}

		// [if] Check if we need to create or update object(s) using uploaded file(s).
		if (empty($uploadedFiles) === false) {
			if (count($uploadedFiles) === 1) {
				return $this->getJSONfromFile(uploadedFile: $uploadedFiles[array_key_first($uploadedFiles)]);
			}

			return new JSONResponse(data: ['message' => 'Expected only 1 file.'], statusCode: 400);
		}

		// [elseif] Check if we need to create or update object using given url from the post body.
		if (empty($data['url']) === false) {
			return $this->getJSONfromURL(url: $data['url']);
		}

		// [else] Create or update object using given json blob from the post body.
		return $this->getJSONfromBody($data['json']);
	}


    /**
	 * A function used to decode file content or the response of an url get call.
	 * Before the data can be used to create or update an object.
	 *
	 * @param string $data The file content or the response body content.
	 * @param string|null $type The file MIME type or the response Content-Type header.
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
				// If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML
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

		return $phpArray;
	}

    /**
	 * Gets uploaded file content from a file in the api request as PHP array and use it for creating/updating an object.
	 *
	 * @param array $uploadedFile The uploaded file.
	 * @param string|null $type If the uploaded file should be a specific type of object.
	 *
	 * @return array A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 */
	private function getJSONfromFile(array $uploadedFile, ?string $type = null): array|JSONResponse
	{
		// Check for upload errors
		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'File upload error: '.$uploadedFile['error']], statusCode: 400);
		}

		$fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
		$fileContent = file_get_contents(filename: $uploadedFile['tmp_name']);

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
     * @throws GuzzleException
     *
     * @return array|JSONResponse The response from the call converted to PHP array or JSONResponse in case of an error.
     */
    private function getJSONfromURL(string $url): array|JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()], statusCode: 400);
        }

        $responseBody = $response->getBody()->getContents();

		// Use Content-Type header to determine the format
		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray = $this->decode(data: $responseBody, type: $contentType);

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
	 * @param string|null $type If the object should be a specific type of object.
	 *
	 * @return array A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 */
	private function getJSONfromBody(array|string $phpArray): array|JSONResponse
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

		return $phpArray;
	}//end getJSONfromBody()


    /**
     * Import configuration from a JSON file
     *
     * @param string      $jsonContent    The configuration JSON content.
     * @param bool        $includeObjects Whether to include objects in the import.
     * @param string|null $owner          The owner of the schemas and registers.
     *
     * @throws JsonException If JSON parsing fails.
     * @throws Exception If schema validation fails or format is unsupported.
     * @return array Array of created/updated entities.
     */
    public function importFromJson(string $jsonContent, bool $includeObjects=false, ?string $owner=null): array
    {
        // Reset the maps for this import.
        $this->registersMap = [];
        $this->schemasMap   = [];

        try {
            $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to parse JSON: '.$e->getMessage());
            throw new Exception('Invalid JSON format: '.$e->getMessage());
        }

        $result = [
            'registers'        => [],
            'schemas'          => [],
            'endpoints'        => [],
            'sources'          => [],
            'mappings'         => [],
            'jobs'             => [],
            'synchronizations' => [],
            'rules'            => [],
            'objects'          => [],
        ];

        // Import schemas first.
        if (isset($data['components']['schemas']) === true) {
            foreach ($data['components']['schemas'] as $slug => $schemaData) {
                $schema = $this->importSchema($schemaData, $owner);
                if ($schema !== null) {
                    // Store schema in map by slug for reference.
                    $this->schemasMap[$slug] = $schema;
                    $result['schemas'][]     = $schema;
                }
            }
        }

        // Import registers after schemas so we can link them.
        if (isset($data['components']['registers']) === true) {
            foreach ($data['components']['registers'] as $slug => $registerData) {
                // Convert schema slugs to IDs.
                if (isset($registerData['schemas']) === true && is_array($registerData['schemas']) === true) {
                    $schemaIds = [];
                    foreach ($registerData['schemas'] as $schemaSlug) {
                        if (isset($this->schemasMap[$schemaSlug]) === true) {
                            $schemaIds[] = $this->schemasMap[$schemaSlug]->getId();
                        } else {
                            $this->logger->warning('Schema with slug '.$schemaSlug.' not found during register import.');
                        }
                    }

                    $registerData['schemas'] = $schemaIds;
                }

                $register = $this->importRegister($registerData, $owner);
                if ($register !== null) {
                    // Store register in map by slug for reference.
                    $this->registersMap[$slug] = $register;
                    $result['registers'][]     = $register;
                }
            }//end foreach
        }//end if

        // Import objects if includeObjects is true.
        if ($includeObjects === true && isset($data['components']['objects']) === true) {
            foreach ($data['components']['objects'] as $slug => $objectData) {
                // Use maps to get IDs from slugs.
                if (isset($objectData['register']) === true && isset($this->registersMap[$objectData['register']]) === true) {
                    $objectData['registerId'] = $this->registersMap[$objectData['register']]->getId();
                } else {
                    $this->logger->warning('Register with slug '.$objectData['register'].' not found during object import.');
                    continue;
                }

                if (isset($objectData['schema']) === true && isset($this->schemasMap[$objectData['schema']]) === true) {
                    $objectData['schemaId'] = $this->schemasMap[$objectData['schema']]->getId();
                } else {
                    $this->logger->warning('Schema with slug '.$objectData['schema'].' not found during object import.');
                    continue;
                }

                $object = $this->importObject($objectData, $owner);
                if ($object !== null) {
                    $result['objects'][] = $object;
                }
            }//end foreach
        }//end if

        // Get the OpenConnector service.
        $openConnector = $this->getOpenConnector();
        if ($openConnector === true) {
            $openConnectorResult = $this->openConnectorConfigurationService->importConfig($data);
            
            // Merge the OpenAPI specification over the OpenConnector configuration.
            $result = array_replace_recursive(
                $openConnectorResult,
                $result
            );
        }

        return $result;

    }//end importFromJson()


    /**
     * Import a register from configuration data
     *
     * @param array       $data  The register data.
     * @param string|null $owner The owner of the register.
     *
     * @return Register|null The imported register or null if skipped.
     */
    private function importRegister(array $data, ?string $owner=null): ?Register
    {
        try {
            // Remove id and uuid from the data.
            unset($data['id'], $data['uuid']);
            
            // Check if register already exists by slug.
            $existingRegister = null;
            try {
                $existingRegister = $this->registerMapper->find($data['slug']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Register doesn't exist, we'll create a new one.
            }

            if ($existingRegister !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if (version_compare($data['version'], $existingRegister->getVersion(), '<=') === true) {
                    $this->logger->info('Skipping register import as existing version is newer or equal.');
                    return null;
                }

                // Update existing register.
                $existingRegister->hydrate($data);
                if ($owner !== null) {
                    $existingRegister->setOwner($owner);
                }

                return $this->registerMapper->update($existingRegister);
            }

            // Create new register.
            $register = new Register();
            $register->hydrate($data);
            if ($owner !== null) {
                $register->setOwner($owner);
            }

            return $this->registerMapper->insert($register);
        } catch (Exception $e) {
            $this->logger->error('Failed to import register: '.$e->getMessage());
            throw new Exception('Failed to import register: '.$e->getMessage());
        }//end try

    }//end importRegister()


    /**
     * Import a schema from configuration data
     *
     * @param array       $data  The schema data.
     * @param string|null $owner The owner of the schema.
     *
     * @return Schema|null The imported schema or null if skipped.
     */
    private function importSchema(array $data, ?string $owner=null): ?Schema
    {
        try {
            // Remove id and uuid from the data.
            unset($data['id'], $data['uuid']);

            // Check if schema already exists by slug.
            $existingSchema = null;
            try {
                $existingSchema = $this->schemaMapper->find($data['slug']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Schema doesn't exist, we'll create a new one.
            }

            if ($existingSchema !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if (version_compare($data['version'], $existingSchema->getVersion(), '<=') === true) {
                    $this->logger->info('Skipping schema import as existing version is newer or equal.');
                    return null;
                }

                // Update existing schema.
                $existingSchema->hydrate($data, $this->validator);
                if ($owner !== null) {
                    $existingSchema->setOwner($owner);
                }

                return $this->schemaMapper->update($existingSchema);
            }

            // Create new schema.
            $schema = new Schema();
            $schema->hydrate($data, $this->validator);
            if ($owner !== null) {
                $schema->setOwner($owner);
            }

            return $this->schemaMapper->insert($schema);
        } catch (Exception $e) {
            $this->logger->error('Failed to import schema: '.$e->getMessage());
            throw new Exception('Failed to import schema: '.$e->getMessage());
        }//end try

    }//end importSchema()


    /**
     * Import an object from configuration data
     *
     * @param array       $data  The object data.
     * @param string|null $owner The owner of the object.
     *
     * @return ObjectEntity|null The imported object or null if skipped.
     */
    private function importObject(array $data, ?string $owner=null): ?ObjectEntity
    {
        try {
            // Check if object already exists by UUID.
            $existingObject = null;
            try {
                $existingObject = $this->objectEntityMapper->find($data['uuid']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object doesn't exist, we'll create a new one.
            }

            if ($existingObject !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if (version_compare($data['version'], $existingObject->getVersion(), '<=') === true) {
                    $this->logger->info('Skipping object import as existing version is newer or equal.');
                    return null;
                }

                // Update existing object.
                $existingObject->hydrate($data);
                if ($owner !== null) {
                    $existingObject->setOwner($owner);
                }

                return $this->objectEntityMapper->update($existingObject);
            }

            // Create new object.
            $object = new ObjectEntity();
            $object->hydrate($data);
            if ($owner !== null) {
                $object->setOwner($owner);
            }

            return $this->objectEntityMapper->insert($object);
        } catch (Exception $e) {
            $this->logger->error('Failed to import object: '.$e->getMessage());
            throw new Exception('Failed to import object: '.$e->getMessage());
        }//end try

    }//end importObject()


}//end class
