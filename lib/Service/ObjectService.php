<?php

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use OC\URLGenerator;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Symfony\Component\Uid\Uuid;
use GuzzleHttp\Client;

/**
 * Service class for handling object operations
 *
 * This service provides methods for CRUD operations on objects, including:
 * - Creating, reading, updating and deleting objects
 * - Finding objects by ID/UUID
 * - Getting audit trails
 * - Extending objects with related data
 *
 * @package OCA\OpenRegister\Service
 */
class ObjectService
{
    /** @var int The current register ID */
    private int $register;

    /** @var int The current schema ID */
    private int $schema;

    /** @var AuditTrailMapper For tracking object changes */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Constructor for ObjectService
     *
     * Initializes the service with required mappers for database operations
     *
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities
     * @param RegisterMapper $registerMapper Mapper for registers
     * @param SchemaMapper $schemaMapper Mapper for schemas
     * @param AuditTrailMapper $auditTrailMapper Mapper for audit trails
     */
    public function __construct(
        ObjectEntityMapper $objectEntityMapper,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        AuditTrailMapper $auditTrailMapper,
		private ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly FileService $fileService,
		private readonly IAppManager $appManager
    )
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;
        $this->auditTrailMapper = $auditTrailMapper;
    }

	/**
	 * Attempts to retrieve the OpenConnector service from the container.
	 *
	 * @return mixed|null The OpenConnector service if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	public function getOpenConnector(string $filePath = '\Service\ObjectService'): mixed
	{
		if (in_array(needle: 'openconnector', haystack: $this->appManager->getInstalledApps()) === true) {
			try {
				// Attempt to get a OpenConnector file from the container
				return $this->container->get("OCA\OpenConnector$filePath");
			} catch (Exception $e) {
				// If the file is not available, return null
				return null;
			}
		}

		return null;
	}

	/**
	 * Validate an object with a schema.
	 * If schema is not given and schemaObject is filled, the object will validate to the schemaObject.
	 *
	 * @param array    $object		 The object to validate.
	 * @param int|null $schemaId		 The id of the schema to validate to.
	 * @param object   $schemaObject A schema object to validate to.
	 *
	 * @return ValidationResult The validation result from opis/json-schema.
	 */
	public function validateObject(array $object, ?int $schemaId = null, object $schemaObject = new stdClass()): ValidationResult
	{
		if ($schemaObject === new stdClass() || $schemaId !== null) {
			$schemaObject = $this->schemaMapper->find($schemaId)->getSchemaObject($this->urlGenerator);
		}

		$validator = new Validator();
		$validator->setMaxErrors(100);
		$validator->parser()->getFormatResolver()->register('string', 'bsn', new BsnFormat());

		return $validator->validate(data: json_decode(json_encode($object)), schema: $schemaObject);

	}

	/**
	 * Find an object by ID or UUID
	 *
	 * @param int|string $id The ID or UUID to search for
	 * @param array|null $extend Properties to extend with related data
	 *
	 * @return ObjectEntity The found object
	 * @throws Exception
	 */
    public function find(int|string $id, ?array $extend = []): ObjectEntity
	{
        return $this->getObject(
            register: $this->registerMapper->find($this->getRegister()),
            schema: $this->schemaMapper->find($this->getSchema()),
            uuid: $id,
            extend: $extend
        );
    }

	/**
	 * Create a new object from array data
	 *
	 * @param array $object The object data
	 *
	 * @return ObjectEntity The created object
	 * @throws ValidationException
	 */
    public function createFromArray(array $object): ObjectEntity
	{
        return $this->saveObject(
            register: $this->getRegister(),
            schema: $this->getSchema(),
            object: $object
        );
    }

	/**
	 * Update an existing object from array data
	 *
	 * @param string $id The object ID to update
	 * @param array $object The new object data
	 * @param bool $updatedObject Whether this is an update operation
	 *
	 * @return ObjectEntity The updated object
	 * @throws ValidationException
	 */
    public function updateFromArray(string $id, array $object, bool $updatedObject, bool $patch = false): ObjectEntity
	{
        // Add ID to object data for update
        $object['id'] = $id;

		// If we want the update to behave like patch, merge with existing object.
		if ($patch === true) {
			$oldObject = $this->getObject($this->registerMapper->find($this->getRegister()), $this->schemaMapper->find($this->getSchema()), $id)->jsonSerialize();

			$object = array_merge($oldObject, $object);
		}

		return $this->saveObject(
            register: $this->getRegister(),
            schema: $this->getSchema(),
            object: $object
        );
    }

	/**
	 * Delete an object
	 *
	 * @param array|\JsonSerializable $object The object to delete
	 *
	 * @return bool True if deletion was successful
	 * @throws Exception
	 */
    public function delete(array|\JsonSerializable $object): bool
    {
        // Convert JsonSerializable objects to array
        if ($object instanceof \JsonSerializable === true) {
            $object = $object->jsonSerialize();
        }

        return $this->deleteObject(
            register: $this->registerMapper->find($this->getRegister()),
            schema: $this->schemaMapper->find($this->getSchema()),
            uuid: $object['id']
        );
    }

	/**
	 * Find all objects matching given criteria
	 *
	 * @param int|null $limit Maximum number of results
	 * @param int|null $offset Starting offset for pagination
	 * @param array $filters Filter criteria
	 * @param array $sort Sorting criteria
	 * @param string|null $search Search term
	 * @param array|null $extend Properties to extend with related data
	 *
	 * @return array List of matching objects
	 */
    public function findAll(?int $limit = null, ?int $offset = null, array $filters = [], array $sort = [], ?string $search = null, ?array $extend = []): array
    {
        $objects = $this->getObjects(
            register: $this->getRegister(),
            schema: $this->getSchema(),
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $sort,
            search: $search
        );

        return $objects;
    }

    /**
     * Count total objects matching filters
     *
     * @param array $filters Filter criteria
     * @param string|null $search Search term
     * @return int Total count
     */
    public function count(array $filters = [], ?string $search = null): int
    {
        // Add register and schema filters if set
        if ($this->getSchema() !== null && $this->getRegister() !== null) {
            $filters['register'] = $this->getRegister();
            $filters['schema']   = $this->getSchema();
        }

        return $this->objectEntityMapper
            ->countAll(filters: $filters, search: $search);
    }

	/**
	 * Find multiple objects by their IDs
	 *
	 * @param array $ids Array of object IDs to find
	 *
	 * @return array Array of found objects
	 * @throws Exception
	 */
    public function findMultiple(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[] = $this->find($id);
        }

        return $result;
    }

    /**
     * Get aggregations for objects matching filters
     *
     * @param array $filters Filter criteria
     * @param string|null $search Search term
	 *
     * @return array Aggregation results
     */
    public function getAggregations(array $filters, ?string $search = null): array
    {
        $mapper = $this->getMapper(objectType: 'objectEntity');

        $filters['register'] = $this->getRegister();
        $filters['schema']   = $this->getSchema();

        // Only ObjectEntityMapper supports facets
        if ($mapper instanceof ObjectEntityMapper === true) {
            $facets = $this->objectEntityMapper->getFacets($filters, $search);
            return $facets;
        }

        return [];
    }

	/**
	 * Extract object data from an entity
	 *
	 * @param mixed $object The object to extract data from
	 * @param array|null $extend Properties to extend with related data
	 *
	 * @return mixed The extracted object data
	 */
    private function getDataFromObject(mixed $object, ?array $extend = []): mixed
	{
        return $object->getObject();
    }

	/**
	 * Gets all objects of a specific type.
	 *
	 * @param string|null $objectType The type of objects to retrieve.
	 * @param int|null $register
	 * @param int|null $schema
	 * @param int|null $limit The maximum number of objects to retrieve.
	 * @param int|null $offset The offset from which to start retrieving objects.
	 * @param array $filters
	 * @param array $sort
	 * @param string|null $search
	 * @param array|null $extend Properties to extend with related data
	 *
	 * @return array The retrieved objects.
	 */
    public function getObjects(?string $objectType = null, ?int $register = null, ?int $schema = null, ?int $limit = null, ?int $offset = null, array $filters = [], array $sort = [], ?string $search = null, ?array $extend = []): array
    {
        // Set object type and filters if register and schema are provided
        if ($objectType === null && $register !== null && $schema !== null) {
            $objectType          = 'objectEntity';
            $filters['register'] = $register;
            $filters['schema']   = $schema;
        }

        // Get the appropriate mapper for the object type
        $mapper = $this->getMapper($objectType);

        // Use the mapper to find and return all objects of the specified type
        return $mapper->findAll(limit: $limit, offset: $offset, filters: $filters, sort: $sort, search: $search);
    }

  	/**
	 * Save an object
	 *
	 * @param int $register The register to save the object to.
	 * @param int $schema The schema to save the object to.
	 * @param array $object The data to be saved.
	 *
	 * @return ObjectEntity The resulting object.
	 * @throws ValidationException When the validation fails and returns an error.
	 * @throws Exception
	 */
    public function saveObject(int $register, int $schema, array $object): ObjectEntity
    {
        // Remove system properties (starting with _)
        $object = array_filter($object, function($key) {
            return !str_starts_with($key, '_');
        }, ARRAY_FILTER_USE_KEY);

        // Convert register and schema to their respective objects if they are strings // @todo ???
        if (is_string($register)) {
            $register = $this->registerMapper->find($register);
        }

        if (is_string($schema)) {
            $schema = $this->schemaMapper->find($schema);
        }

        // Check if object already exists
        if (isset($object['id']) === true) {
            $objectEntity = $this->objectEntityMapper->findByUuid(
                $this->registerMapper->find($register),
                $this->schemaMapper->find($schema),
                $object['id']
            );
        }

        // Create new entity if none exists
        if (isset($object['id']) === false || $objectEntity === null) {
            $objectEntity = new ObjectEntity();
            $objectEntity->setRegister($register);
            $objectEntity->setSchema($schema);
        }

        // Handle UUID assignment
        if (isset($object['id']) && !empty($object['id'])) {
            $objectEntity->setUuid($object['id']);
        } else {
            $objectEntity->setUuid(Uuid::v4());
            $object['id'] = $objectEntity->getUuid();
        }

        // Store old version for audit trail
        $oldObject = clone $objectEntity;
        $objectEntity->setObject($object);

        // Ensure UUID exists
        if (empty($objectEntity->getUuid())) {
            $objectEntity->setUuid(Uuid::v4());
        }
        
        // Let grap any links that we can
        $objectEntity = $this->handleLinkRelations($objectEntity, $object);

        $schemaObject = $this->schemaMapper->find($schema);

        // Handle object properties that are either nested objects or files
        if ($schemaObject->getProperties() !== null && is_array($schemaObject->getProperties())) {
            $objectEntity = $this->handleObjectRelations($objectEntity, $object, $schemaObject->getProperties(), $register, $schema);
            $objectEntity->setObject($object);
        }           

        $objectEntity->setUri($this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('openregister.Objects.show', ['id' => $objectEntity->getUuid()])));

        if ($objectEntity->getId()) {
            $objectEntity = $this->objectEntityMapper->update($objectEntity);
            $this->auditTrailMapper->createAuditTrail(new: $objectEntity, old: $oldObject);
        } else {
            $objectEntity =  $this->objectEntityMapper->insert($objectEntity);
            $this->auditTrailMapper->createAuditTrail(new: $objectEntity);
        }

        return $objectEntity;
    }

	/**
     * Handle link relations efficiently using JSON path traversal
     * 
     * Finds all links or UUIDs in the object and adds them to the relations 
     * using dot notation paths for nested properties
     * 
     * @param ObjectEntity $objectEntity The object entity to handle relations for
     * @param array $object The object data
     * 
     * @return ObjectEntity Updated object data
     */
	private function handleLinkRelations(ObjectEntity $objectEntity): ObjectEntity
	{
		$relations = $objectEntity->getRelations() ?? [];
		
		// Get object's own identifiers to skip self-references
		$selfIdentifiers = [
			$objectEntity->getUri(),
			$objectEntity->getUuid(),
			$objectEntity->getId()
		];
		
		// Function to recursively find links/UUIDs and build dot notation paths
		$findRelations = function($data, $path = '') use (&$findRelations, &$relations, $selfIdentifiers) {
			foreach ($data as $key => $value) {
				$currentPath = $path ? "$path.$key" : $key;
				
				if (is_array($value)) {
					// Recurse into nested arrays
					$findRelations($value, $currentPath);
				} else if (is_string($value)) {
					// Check for URLs and UUIDs
					if ((filter_var($value, FILTER_VALIDATE_URL) !== false 
						|| preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value))
						&& !in_array($value, $selfIdentifiers, true)
					) {
						$relations[$currentPath] = $value;
					}
				}
			}
		};

		// Process the entire object structure
		$findRelations($objectEntity->getObject());
		
		$objectEntity->setRelations($relations);
		return $objectEntity;
	}

	/**
	 * Handle object relations and file properties in schema properties and array items
	 *
	 * @param ObjectEntity $objectEntity The object entity to handle relations for
	 * @param array $object The object data
	 * @param array $properties The schema properties
	 * @param int $register The register ID
	 * @param int $schema The schema ID
	 *
	 * @return ObjectEntity Updated object with linked data
	 * @throws Exception|ValidationException When file handling fails
	 */
	private function handleObjectRelations(ObjectEntity $objectEntity, array $object, array $properties, int $register, int $schema): ObjectEntity
	{
        // @todo: Multidimensional suport should be added
		foreach ($properties as $propertyName => $property) {
			// Skip if property not in object
			if (isset($object[$propertyName]) === false) {
				continue;
			}

			// Handle array type with items that may contain objects/files
			if ($property['type'] === 'array' && isset($property['items']) === true) {
				// Skip if not array in data
				if (is_array($object[$propertyName]) === false) {
					continue;
				}

				// Process each array item
				foreach ($object[$propertyName] as $index => $item) {
					if ($property['items']['type'] === 'object') {
						$subSchema = $schema;

						if(is_int($property['items']['$ref']) === true) {
							$subSchema = $property['items']['$ref'];
						} else if (filter_var(value: $property['items']['$ref'], filter: FILTER_VALIDATE_URL) !== false) {
							$parsedUrl = parse_url($property['items']['$ref']);
							$explodedPath = explode(separator: '/', string: $parsedUrl['path']);
							$subSchema = end($explodedPath);
						}

						if(is_array($item) === true) {
							// Handle nested object in array
							$nestedObject = $this->saveObject(
								register: $register,
								schema: $subSchema,
								object: $item
							);

							// Store relation and replace with reference
							$relations = $objectEntity->getRelations() ?? [];
							$relations[$propertyName . '_' . $index] = $nestedObject->getUuid();
							$objectEntity->setRelations($relations);
							$object[$propertyName][$index] = $nestedObject->getUuid();

						} else {
							$relations = $objectEntity->getRelations() ?? [];
							$relations[$propertyName . '_' . $index] = $item;
							$objectEntity->setRelations($relations);
						}

					} else if ($property['items']['type'] === 'file') {
						// Handle file in array
						$object[$propertyName][$index] = $this->handleFileProperty(
							objectEntity: $objectEntity,
							object: [$propertyName => [$index => $item]],
							propertyName: $propertyName . '.' . $index
						)[$propertyName];
					}
				}
			}
            
			// Handle single object type
			else if ($property['type'] === 'object') {

				$subSchema = $schema;

                // $ref is a int, id or uuid
				if(is_int($property['$ref']) === true || is_numeric($property['$ref']) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $property['$ref'])) {
					$subSchema = $property['$ref'];
				} else if (filter_var(value: $property['$ref'], filter: FILTER_VALIDATE_URL) !== false) {
					$parsedUrl = parse_url($property['$ref']);
					$explodedPath = explode(separator: '/', string: $parsedUrl['path']);
					$subSchema = end($explodedPath);
				}

				if(is_array($object[$propertyName]) === true) {
					$nestedObject = $this->saveObject(
						register: $register,
						schema: $subSchema,
						object: $object[$propertyName]
					);

					// Store relation and replace with reference
					$relations = $objectEntity->getRelations() ?? [];
					$relations[$propertyName] = $nestedObject->getUri();
					$objectEntity->setRelations($relations);
					$object[$propertyName] = $nestedObject->getUri();

				} else {
					$relations = $objectEntity->getRelations() ?? [];
					$relations[$propertyName] = $object[$propertyName];
					$objectEntity->setRelations($relations);
				}

			}
			// Handle single file type
			else if ($property['type'] === 'file') {

				$object = $this->handleFileProperty(
					objectEntity: $objectEntity,
					object: $object,
					propertyName: $propertyName
				);
			}
		}

		return $objectEntity;
	}

	/**
	 * Handle file property processing
	 *
	 * @param ObjectEntity $objectEntity The object entity
	 * @param array $object The object data
	 * @param string $propertyName The name of the file property
	 *
	 * @return array Updated object data
	 * @throws Exception|GuzzleException When file handling fails
	 */
	private function handleFileProperty(ObjectEntity $objectEntity, array $object, string $propertyName): array
	{
		$fileName = str_replace('.', '_', $propertyName);
		$objectDot = new Dot($object);

		// Check if it's a Nextcloud file URL
//		if (str_starts_with($object[$propertyName], $this->urlGenerator->getAbsoluteURL())) {
//			$urlPath = parse_url($object[$propertyName], PHP_URL_PATH);
//			if (preg_match('/\/f\/(\d+)/', $urlPath, $matches)) {
//				$files = $objectEntity->getFiles() ?? [];
//				$files[$propertyName] = (int)$matches[1];
//				$objectEntity->setFiles($files);
//				$object[$propertyName] = (int)$matches[1];
//				return $object;
//			}
//		}

		// Handle base64 encoded file
		if (is_string($objectDot->get($propertyName)) === true
			&& preg_match('/^data:([^;]*);base64,(.*)/', $objectDot->get($propertyName), $matches)
		) {
			$fileContent = base64_decode($matches[2], true);
			if ($fileContent === false) {
				throw new Exception('Invalid base64 encoded file');
			}
		}
		// Handle URL file
		else {
			// Encode special characters in the URL
			$encodedUrl = rawurlencode($objectDot->get("$propertyName.downloadUrl")); //@todo hardcoded .downloadUrl

			// Decode valid path separators and reserved characters
			$encodedUrl = str_replace(['%2F', '%3A', '%28', '%29'], ['/', ':', '(', ')'], $encodedUrl);

			if (filter_var($encodedUrl, FILTER_VALIDATE_URL)) {
				try {
					// @todo hacky tacky
					// Regular expression to get the filename and extension from url //@todo hardcoded .downloadUrl
					if (preg_match("/\/([^\/]+)'\)\/\\\$value$/", $objectDot->get("$propertyName.downloadUrl"), $matches)) {
						// @todo hardcoded way of getting the filename and extension from the url
						$fileNameFromUrl = $matches[1];
						// @todo use only the extension from the url ?
						// $fileName = $fileNameFromUrl;
						$extension = substr(strrchr($fileNameFromUrl, '.'), 1);
						$fileName = "$fileName.$extension";
					}

					if ($objectDot->has("$propertyName.source") === true) {
						$sourceMapper = $this->getOpenConnector(filePath: '\Db\SourceMapper');
						$source = $sourceMapper->find($objectDot->get("$propertyName.source"));

						$callService = $this->getOpenConnector(filePath: '\Service\CallService');
						if ($callService === null) {
							throw new Exception("OpenConnector service not available");
						}
						$endpoint = str_replace($source->getLocation(), "", $encodedUrl);


						$endpoint = urldecode($endpoint);

						$response = $callService->call(source: $source, endpoint: $endpoint, method: 'GET')->getResponse();

						$fileContent = $response['body'];

						if(
							$response['encoding'] === 'base64'
						) {
							$fileContent = base64_decode(string: $fileContent);
						}

					} else {
						$client = new \GuzzleHttp\Client();
						$response = $client->get($encodedUrl);
						$fileContent = $response->getBody()->getContents();
					}
				} catch (Exception|NotFoundExceptionInterface $e) {
					throw new Exception('Failed to download file from URL: ' . $e->getMessage());
				}
			} else if (str_contains($objectDot->get($propertyName), $this->urlGenerator->getBaseUrl()) === true) {
				return $object;
			} else {
				throw new Exception('Invalid file format - must be base64 encoded or valid URL');
			}
		}

		try {
			$schema = $this->schemaMapper->find($objectEntity->getSchema());
			$schemaFolder = $this->fileService->getSchemaFolderName($schema);
			$objectFolder = $this->fileService->getObjectFolderName($objectEntity);

			$this->fileService->createFolder(folderPath: 'Objects');
			$this->fileService->createFolder(folderPath: "Objects/$schemaFolder");
			$this->fileService->createFolder(folderPath: "Objects/$schemaFolder/$objectFolder");
			$filePath = "Objects/$schemaFolder/$objectFolder/$fileName";

			$succes = $this->fileService->updateFile(
				content: $fileContent,
				filePath: $filePath,
				createNew: true
			);
			if ($succes === false) {
				throw new Exception('Failed to upload this file: $filePath to NextCloud');
			}

			// Create or find ShareLink
			$share = $this->fileService->findShare(path: $filePath);
			if ($share !== null) {
				$shareLink = $this->fileService->getShareLink($share).'/download';
			} else {
				$shareLink = $this->fileService->createShareLink(path: $filePath).'/download';
			}

			$filesDot = new Dot($objectEntity->getFiles() ?? []);
			$filesDot->set($propertyName, $shareLink);
			$objectEntity->setFiles($filesDot->all());

			// Preserve the original uri in the object 'json blob'
			$objectDot = $objectDot->set($propertyName, $shareLink);
			$object = $objectDot->all();
		} catch (Exception $e) {
			throw new Exception('Failed to store file: ' . $e->getMessage());
		}

		return $object;
	}

    /**
     * Get an object
     *
     * @param Register $register The register to get the object from
     * @param Schema $schema The schema of the object
     * @param string $uuid The UUID of the object to get
     * @param array $extend Properties to extend with related data
     *
     * @return ObjectEntity The resulting object
     * @throws Exception If source type is unsupported
     */
    public function getObject(Register $register, Schema $schema, string $uuid, ?array $extend = []): ObjectEntity
    {

        // Handle internal source
        if ($register->getSource() === 'internal' || $register->getSource() === '') {
            return $this->objectEntityMapper->findByUuid($register, $schema, $uuid);
        }

        //@todo mongodb support

        throw new Exception('Unsupported source type');
    }

    /**
     * Delete an object
     *
     * @param Register $register The register to delete from
     * @param Schema $schema The schema of the object
     * @param string $uuid The UUID of the object to delete
     *
     * @return bool True if deletion was successful
     * @throws Exception If source type is unsupported
     */
    public function deleteObject(Register $register, Schema $schema, string $uuid): bool
    {
        // Handle internal source
        if ($register->getSource() === 'internal' || $register->getSource() === '') {
            $object = $this->objectEntityMapper->findByUuid(register: $register, schema: $schema, uuid: $uuid);
            $this->objectEntityMapper->delete($object);
            return true;
        }

        //@todo mongodb support

        throw new Exception('Unsupported source type');
    }

    /**
     * Gets the appropriate mapper based on the object type.
     *
     * @param string|null $objectType The type of object to retrieve the mapper for
     * @param int|null $register Optional register ID
     * @param int|null $schema Optional schema ID
     * @return mixed The appropriate mapper
     * @throws InvalidArgumentException If unknown object type
     */
    public function getMapper(?string $objectType = null, ?int $register = null, ?int $schema = null): mixed
    {
        // Return self if register and schema provided
        if ($register !== null && $schema !== null) {
            $this->setSchema($schema);
            $this->setRegister($register);
            return $this;
        }

        // Return appropriate mapper based on object type
        switch ($objectType) {
            case 'register':
                return $this->registerMapper;
            case 'schema':
                return $this->schemaMapper;
            case 'objectEntity':
                return $this->objectEntityMapper;
            default:
                throw new InvalidArgumentException("Unknown object type: $objectType");
        }
    }

    /**
     * Gets multiple objects based on the object type and ids.
     *
     * @param string $objectType The type of objects to retrieve
     * @param array $ids The ids of the objects to retrieve
     * @return array The retrieved objects
     * @throws InvalidArgumentException If unknown object type
     */
    public function getMultipleObjects(string $objectType, array $ids): array
	{
        // Process the ids to handle different formats
        $processedIds = array_map(function($id) {
            if (is_object($id) && method_exists($id, 'getId')) {
                return $id->getId();
            } elseif (is_array($id) && isset($id['id'])) {
                return $id['id'];
            } else {
                return $id;
            }
        }, $ids);

        // Clean up URIs to get just the ID portion
        $cleanedIds = array_map(function($id) {
            if (filter_var($id, FILTER_VALIDATE_URL)) {
                $parts = explode('/', rtrim($id, '/'));
                return end($parts);
            }
            return $id;
        }, $processedIds);

        // Get mapper and find objects
        $mapper = $this->getMapper($objectType);
        return $mapper->findMultiple($cleanedIds);
    }

    /**
     * Renders the entity by replacing the files and relations with their respective objects
     *
     * @param array $entity The entity to render
     * @param array|null $extend Optional array of properties to extend, defaults to files and relations if not provided
     * @return array The rendered entity with expanded files and relations
     */
    public function renderEntity(array $entity, ?array $extend = []): array
    {
        // check if entity has files or relations and if not just return the entity
        if (array_key_exists(key: 'files', array: $entity) === false && array_key_exists(key: 'relations', array: $entity) === false) {
            return $entity;
        }

        // Lets create a dot array of the entity
        $dotEntity = new Dot($entity);

        // loop through the files and replace the file ids with the file objects)
        if (array_key_exists(key: 'files', array: $entity) === true && empty($entity['files']) === false) {
            // Loop through the files array where key is dot notation path and value is file id
            foreach ($entity['files'] as $path => $fileId) {
                // Replace the value at the dot notation path with the file URL
                $dotEntity->set($path, $filesById[$fileId]->getUrl());
            }
        }

        // Loop through the relations and replace the relation ids with the relation objects if extended
        if (array_key_exists(key: 'relations', array: $entity) === true && empty($entity['relations']) === false) {
            // loop through the relations and replace the relation ids with the relation objects
            foreach ($entity['relations'] as $path => $relationId) {
                // if the relation is not in the extend array, skip it
                if (in_array(needle: $path, haystack: $extend) === false) {
                    continue;
                }
                // Replace the value at the dot notation path with the relation object
                $dotEntity->set($path, $this->getObject(register: $this->getRegister(), schema: $this->getSchema(), uuid: $relationId));
            }
        }

        // Update the entity with modified values
        $entity = $dotEntity->all();

        return $this->extendEntity(entity: $entity, extend: $extend);
    }

    /**
     * Extends an entity with related objects based on the extend array.
     *
     * @param mixed $entity The entity to extend
     * @param array $extend Properties to extend with related data
     * @return array The extended entity as an array
     * @throws Exception If property not found or no mapper available
     */
    public function extendEntity(array $entity, array $extend): array
    {
        // Convert entity to array if needed
        if (is_array($entity)) {
            $result = $entity;
        } else {
            $result = $entity->jsonSerialize();
        }

        // Process each property to extend
        foreach ($extend as $property) {
            $singularProperty = rtrim($property, 's');

            // Check if property exists
            if (array_key_exists(key: $property, array: $result) === true) {
                $value = $result[$property];
                if (empty($value)) {
                    continue;
                }
            } elseif (array_key_exists(key: $singularProperty, array: $result)) {
                $value = $result[$singularProperty];
            } else {
                throw new Exception("Property '$property' or '$singularProperty' is not present in the entity.");
            }

            // Try to get mapper for property
            $propertyObject = $property;
            try {
                $mapper = $this->getMapper(objectType: $property);
                $propertyObject = $singularProperty;
            } catch (Exception $e) {
                try {
                    $mapper = $this->getMapper(objectType: $singularProperty);
                    $propertyObject = $singularProperty;
                } catch (Exception $e) {
                    throw new Exception("No mapper available for property '$property'.");
                }
            }

            // Extend with related objects
            if (is_array($value) === true) {
                $result[$property] = $this->getMultipleObjects(objectType: $propertyObject, ids: $value);
            } else {
                $objectId = is_object(value: $value) ? $value->getId() : $value;
                $result[$property] = $mapper->find($objectId);
            }
        }

        return $result;
    }

    /**
     * Get all registers extended with their schemas
     *
     * @return array The registers with schema data
     * @throws Exception If extension fails
     */
    public function getRegisters(): array
    {
        // Get all registers
        $registers = $this->registerMapper->findAll();

        // Convert to arrays
        $registers = array_map(function($object) {
            return $object->jsonSerialize();
        }, $registers);

        // Extend with schemas
        $extend = ['schemas'];
        if (empty($extend) === false) {
            $registers = array_map(function($object) use ($extend) {
                return $this->extendEntity(entity: $object, extend: $extend);
            }, $registers);
        }

        return $registers;
    }

    /**
     * Get current register ID
     *
     * @return int The register ID
     */
    public function getRegister(): int
    {
        return $this->register;
    }

    /**
     * Set current register ID
     *
     * @param int $register The register ID to set
     */
    public function setRegister(int $register): void
    {
        $this->register = $register;
    }

    /**
     * Get current schema ID
     *
     * @return int The schema ID
     */
    public function getSchema(): int
    {
        return $this->schema;
    }

    /**
     * Set current schema ID
     *
     * @param int $schema The schema ID to set
     */
    public function setSchema(int $schema): void
    {
        $this->schema = $schema;
    }

    /**
     * Get the audit trail for a specific object
     *
     * @param string $id The object ID
     * @param int|null $register Optional register ID to override current register
     * @param int|null $schema Optional schema ID to override current schema
     * @return array The audit trail entries
     */
    public function getAuditTrail(string $id, ?int $register = null, ?int $schema = null): array
    {
        $register = $register ?? $this->getRegister();
        $schema = $schema ?? $this->getSchema();

        $filters = [
            'object' => $id,
            'register' => $register,
            'schema' => $schema
        ];

        return $this->auditTrailMapper->findAllUuid(idOrUuid: $id);
    }

    /**
     * Get all relations for a specific object
     * Returns objects that this object links to
     *
     * @param string $id The object ID
     * @param int|null $register Optional register ID to override current register
     * @param int|null $schema Optional schema ID to override current schema
     * @return array The related objects
     */
    public function getRelations(string $id, ?int $register = null, ?int $schema = null): array
    {
        $register = $register ?? $this->getRegister();
        $schema = $schema ?? $this->getSchema();

        // First get the object to access its relations
        $object = $this->find($id);
        $relations = $object->getRelations() ?? [];

        // Get all referenced objects
        $relatedObjects = [];
        foreach ($relations as $path => $relationId) {
            try {
                $relatedObjects[$path] = $this->find($relationId);
            } catch (Exception $e) {
                // Skip relations that can't be found
                continue;
            }
        }

        return $relatedObjects;
    }

    /**
     * Get all uses of a specific object
     * Returns objects that link to this object
     *
     * @param string $id The object ID
     * @param int|null $register Optional register ID to override current register
     * @param int|null $schema Optional schema ID to override current schema
     * @return array The objects using this object
     */
    public function getUses(string $id, ?int $register = null, ?int $schema = null): array
    {
        $register = $register ?? $this->getRegister();
        $schema = $schema ?? $this->getSchema();

        // Get the object to get its URI and UUID
        $object = $this->find($id);
        
        // Find objects that reference this object's URI or UUID
        $usingObjects = $this->objectEntityMapper->findByRelationUri(
            search: $object->getUuid(),
            partialMatch: true
        );

        // Filter out self-references if any
        return array_filter($usingObjects, function($usingObject) use ($id) {
            return $usingObject->getUuid() !== $id;
        });
    }
}
