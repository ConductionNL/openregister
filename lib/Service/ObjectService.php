<?php

namespace OCA\OpenRegister\Service;

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
use OCP\DB\Exception;
use OCP\IURLGenerator;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
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
		private readonly IURLGenerator $urlGenerator
    )
    {
        $this->objectEntityMapper = $objectEntityMapper;
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;
        $this->auditTrailMapper = $auditTrailMapper;
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
     * @param array $extend Properties to extend with related data
     * 
     * @return ObjectEntity The found object
     */
    public function find(int|string $id, ?array $extend = []) {
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
     * @return ObjectEntity The created object
     */
    public function createFromArray(array $object) {
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
     * @return ObjectEntity The updated object
     */
    public function updateFromArray(string $id, array $object, bool $updatedObject) {
        // Add ID to object data for update
        $object['id'] = $id;

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
     * @return bool True if deletion was successful
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
     * @param array $extend Properties to extend with related data
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
     * @return array Array of found objects
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
     * @param array $extend Properties to extend with related data
     * 
     * @return mixed The extracted object data
     */
    private function getDataFromObject(mixed $object, ?array $extend = []) {
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
     * @param array $extend Properties to extend with related data
     * 
     * @return array The retrieved objects.
     * @throws \Exception
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
        // Convert register and schema to their respective objects if they are strings
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

		$validationResult = $this->validateObject(object: $object, schemaId: $schema);

        // Create new entity if none exists
        if ($objectEntity === null) {
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

		$schemaObject = $this->schemaMapper->find($schema);

        // Handle object properties that are either nested objects or files
		if (isset($schemaObject->properties) && is_array($schemaObject->properties)) {
			$object = $this->handleObjectRelations($objectEntity, $object, $schemaObject->properties, $register, $schema);
			$objectEntity->setObject($object);
		}

		if ($objectEntity->getId() && ($schemaObject->getHardValidation() === false || $validationResult->isValid() === true)){
			$objectEntity = $this->objectEntityMapper->update($objectEntity);
			$this->auditTrailMapper->createAuditTrail(new: $objectEntity, old: $oldObject);
		} else if ($schemaObject->getHardValidation() === false || $validationResult->isValid() === true) {
			$objectEntity =  $this->objectEntityMapper->insert($objectEntity);
			$this->auditTrailMapper->createAuditTrail(new: $objectEntity);
		}

		if ($validationResult->isValid() === false) {
			throw new ValidationException(message: 'The object could not be validated', errors: $validationResult->error());
		}

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
	 * @return array Updated object data
	 * @throws Exception When file handling fails
	 */
	private function handleObjectRelations(ObjectEntity $objectEntity, array $object, array $properties, int $register, int $schema): array {
		foreach ($properties as $propertyName => $property) {
			// Skip if property not in object
			if (!isset($object[$propertyName])) {
				continue;
			}

			// Handle array type with items that may contain objects/files
			if ($property->type === 'array' && isset($property->items)) {
				// Skip if not array in data
				if (!is_array($object[$propertyName])) {
					continue;
				}

				// Process each array item
				foreach ($object[$propertyName] as $index => $item) {
					if ($property->items->type === 'object') {
						// Handle nested object in array
						$nestedObject = $this->saveObject(
							register: $register,
							schema: $schema,
							object: $item
						);
						
						// Store relation and replace with reference
						$relations = $objectEntity->getRelations() ?? [];
						$relations[$propertyName . '_' . $index] = $nestedObject->getId();
						$objectEntity->setRelations($relations);
						$object[$propertyName][$index] = $nestedObject->getId();
					}
					else if ($property->items->type === 'file') {
						// Handle file in array
						$object[$propertyName][$index] = $this->handleFileProperty(
							$objectEntity,
							[$propertyName => $item],
							$propertyName . '_' . $index
						)[$propertyName];
					}
				}
			}
			// Handle single object type
			else if ($property->type === 'object') {
				$nestedObject = $this->saveObject(
					register: $register,
					schema: $schema,
					object: $object[$propertyName]
				);
				
				// Store relation and replace with reference
				$relations = $objectEntity->getRelations() ?? [];
				$relations[$propertyName] = $nestedObject->getId();
				$objectEntity->setRelations($relations);
				$object[$propertyName] = $nestedObject->getId();
			}
			// Handle single file type
			else if ($property->type === 'file') {
				$object = $this->handleFileProperty($objectEntity, $object, $propertyName);
			}
		}
		
		return $object;
	}

	/**
	 * Handle file property processing
	 * 
	 * @param ObjectEntity $objectEntity The object entity
	 * @param array $object The object data
	 * @param string $propertyName The name of the file property
	 * 
	 * @return array Updated object data
	 * @throws Exception When file handling fails
	 */
	private function handleFileProperty(ObjectEntity $objectEntity, array $object, string $propertyName): array {
		$fileContent = null;
		$fileName = $propertyName;
		
		// Check if it's a Nextcloud file URL
		if (str_starts_with($object[$propertyName], $this->urlGenerator->getAbsoluteURL())) {
			$urlPath = parse_url($object[$propertyName], PHP_URL_PATH);
			if (preg_match('/\/f\/(\d+)/', $urlPath, $matches)) {
				$files = $objectEntity->getFiles() ?? [];
				$files[$propertyName] = (int)$matches[1];
				$objectEntity->setFiles($files);
				$object[$propertyName] = (int)$matches[1];
				return $object;
			}
		}

		// Handle base64 encoded file
		if (preg_match('/^data:([^;]*);base64,(.*)/', $object[$propertyName], $matches)) {
			$fileContent = base64_decode($matches[2], true);
			if ($fileContent === false) {
				throw new \Exception('Invalid base64 encoded file');
			}
		}
		// Handle URL file
		else if (filter_var($object[$propertyName], FILTER_VALIDATE_URL)) {
			try {
				$client = new \GuzzleHttp\Client();
				$response = $client->get($object[$propertyName]);
				$fileContent = $response->getBody()->getContents();
			} catch (\Exception $e) {
				throw new \Exception('Failed to download file from URL: ' . $e->getMessage());
			}
		} else {
			throw new \Exception('Invalid file format - must be base64 encoded or valid URL');
		}
		
		try {
			$file = $this->fileService->createOrUpdateFile(
				content: $fileContent,
				fileName: $fileName
			);
			
			$files = $objectEntity->getFiles() ?? [];
			$files[$propertyName] = $file->getId();
			$objectEntity->setFiles($files);
			
			$object[$propertyName] = $file->getId();
		} catch (\Exception $e) {
			throw new \Exception('Failed to store file: ' . $e->getMessage());
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
     * @throws \Exception If source type is unsupported
     */
    public function getObject(Register $register, Schema $schema, string $uuid, ?array $extend = []): ObjectEntity
    {
        // Handle internal source
        if ($register->getSource() === 'internal' || $register->getSource() === '') {
            return $this->objectEntityMapper->findByUuid($register, $schema, $uuid);
        }

        //@todo mongodb support

        throw new \Exception('Unsupported source type');
    }

    /**
     * Delete an object
     *
     * @param Register $register The register to delete from
     * @param Schema $schema The schema of the object
     * @param string $uuid The UUID of the object to delete
     *
     * @return bool True if deletion was successful
     * @throws \Exception If source type is unsupported
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

        throw new \Exception('Unsupported source type');
    }

    /**
     * Gets the appropriate mapper based on the object type.
     *
     * @param string|null $objectType The type of object to retrieve the mapper for
     * @param int|null $register Optional register ID
     * @param int|null $schema Optional schema ID
     * @return mixed The appropriate mapper
     * @throws \InvalidArgumentException If unknown object type
     */
    public function getMapper(?string $objectType = null, ?int $register = null, ?int $schema = null)
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
                throw new \InvalidArgumentException("Unknown object type: $objectType");
        }
    }

    /**
     * Gets multiple objects based on the object type and ids.
     *
     * @param string $objectType The type of objects to retrieve
     * @param array $ids The ids of the objects to retrieve
     * @return array The retrieved objects
     * @throws \InvalidArgumentException If unknown object type
     */
    public function getMultipleObjects(string $objectType, array $ids)
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
     * @throws \Exception If property not found or no mapper available
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
                throw new \Exception("Property '$property' or '$singularProperty' is not present in the entity.");
            }

            // Try to get mapper for property
            $propertyObject = $property;
            try {
                $mapper = $this->getMapper(objectType: $property);
                $propertyObject = $singularProperty;
            } catch (\Exception $e) {
                try {
                    $mapper = $this->getMapper(objectType: $singularProperty);
                    $propertyObject = $singularProperty;
                } catch (\Exception $e) {
                    throw new \Exception("No mapper available for property '$property'.");
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
     * @throws \Exception If extension fails
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
     * @todo: register and schema parameters are not needed anymore
     *
     * @param int $register The register ID
     * @param int $schema The schema ID
     * @param string $id The object ID
     * @return array The audit trail entries
     */
    public function getAuditTrail(int $register, int $schema, string $id): array
    {
        $filters = [
            'object' => $id
        ];

        return $this->auditTrailMapper->findAllUuid(idOrUuid: $id);
    }
}
