<?php

namespace OCA\OpenRegister\Service;

use Adbar\Dot;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use OC\URLGenerator;
use OCA\OpenRegister\Db\File;
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
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
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
		private readonly IAppManager $appManager,
		private readonly IAppConfig $config,
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
	 * Fetch schema from URL
	 *
	 * @param Uri $uri The URI registered by the resolver.
	 *
	 * @return string The resulting json object.
	 *
	 * @throws GuzzleException
	 */
	public function resolveSchema(Uri $uri): string
	{
		if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
			&& str_contains(haystack: $uri->path(), needle: '/api/schemas') === true
		) {
			$exploded = explode(separator: '/', string: $uri->path());
			$schema   =  $this->schemaMapper->find(end($exploded));

			return json_encode($schema->getSchemaObject($this->urlGenerator));
		}

		if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
			&& str_contains(haystack: $uri->path(), needle: '/api/files/schema') === true
		) {
			$exploded = explode(separator: '/', string: $uri->path());
			return File::getSchema($this->urlGenerator);
		}

		// @TODO: Validate file schema

		if ($this->config->getValueBool(app: 'openregister', key: 'allowExternalSchemas') === true) {
			$client = new Client();
			$result = $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()));

			return $result->getBody()->getContents();
		}

		return '';
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

		if($schemaObject->properties === []) {
			$schemaObject->properties = new stdClass();
		}

		$validator = new Validator();
		$validator->setMaxErrors(100);
		$validator->parser()->getFormatResolver()->register('string', 'bsn', new BsnFormat());
		$validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);


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

		$validationResult = $this->validateObject(object: $object, schemaId: $schema);

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

        // Ensure UUID exists //@todo: this is not needed anymore? this kinde of uuid is set in the handleLinkRelations function
        if (empty($objectEntity->getUuid()) === true) {
            $objectEntity->setUuid(Uuid::v4());
        }

        // Let grap any links that we can
        $objectEntity = $this->handleLinkRelations($objectEntity, $object);

		$schemaObject = $this->schemaMapper->find($schema);

        // Handle object properties that are either nested objects or files
		if ($schemaObject->getProperties() !== null && is_array($schemaObject->getProperties()) === true) {
			$objectEntity = $this->handleObjectRelations($objectEntity, $object, $schemaObject->getProperties(), $register, $schema);
		}

		$objectEntity->setUri($this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('openregister.Objects.show', ['id' => $objectEntity->getUuid()])));

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

				if (is_array($value) === true) {
					// Recurse into nested arrays
					$findRelations($value, $currentPath);
				} else if (is_string($value) === true) {
					// Check for URLs and UUIDs
					if ((filter_var($value, FILTER_VALIDATE_URL) !== false
						|| preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1)
						&& in_array($value, $selfIdentifiers, true) === false
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
	 * Adds a subobject based upon given parameters and adds it to the main object.
	 *
	 * @param array $property      		 The property to handle
	 * @param string $propertyName 		 The name of the property
	 * @param array $item 		   		 The contents of the property
	 * @param ObjectEntity $objectEntity The objectEntity the data belongs to
	 * @param int $register   			 The register connected to the objectEntity
	 * @param int $schema     			 The schema connected to the objectEntity
	 * @param int|null $index 			 If the subobject is in an array, the index of the object in the array.
	 *
	 * @return string The updated item
	 * @throws ValidationException
	 */
	private function addObject
	(
		array  		 $property,
		string 		 $propertyName,
		array  		 $item,
		ObjectEntity $objectEntity,
		int    	 	 $register,
		int	   	 	 $schema,
		?int   	 	 $index = null,
	): string
	{
		$subSchema = $schema;
		if(is_int($property['$ref']) === true) {
			$subSchema = $property['$ref'];
		} else if (filter_var(value: $property['$ref'], filter: FILTER_VALIDATE_URL) !== false) {
			$parsedUrl = parse_url($property['$ref']);
			$explodedPath = explode(separator: '/', string: $parsedUrl['path']);
			$subSchema = end($explodedPath);
		}

		// Handle nested object in array
		$nestedObject = $this->saveObject(
			register: $register,
			schema: $subSchema,
			object: $item
		);

		if($index === null) {
			// Store relation and replace with reference
			$relations = $objectEntity->getRelations() ?? [];
			$relations[$propertyName] = $nestedObject->getUri();
			$objectEntity->setRelations($relations);
		} else {
			$relations = $objectEntity->getRelations() ?? [];
			$relations[$propertyName . '.' . $index] = $nestedObject->getUri();
			$objectEntity->setRelations($relations);
		}

		return $nestedObject->getUuid();
	}

	/**
	 * Handles a property that is of the type array.
	 *
	 * @param array $property			 The property to handle
	 * @param string $propertyName  	 The name of the property
	 * @param array $item			     The contents of the property
	 * @param ObjectEntity $objectEntity The objectEntity the data belongs to
	 * @param int $register				 The register connected to the objectEntity
	 * @param int $schema				 The schema connected to the objectEntity
	 *
	 * @return string The updated item
	 */
	private function handleObjectProperty(
		array        $property,
		string       $propertyName,
		array        $item,
		ObjectEntity $objectEntity,
		int          $register,
		int          $schema
	): string
	{
		return $this->addObject(
			property: $property,propertyName: $propertyName, item: $item, objectEntity: $objectEntity, register: $register, schema: $schema
		);
	}

	/**
	 * Handles a property that is of the type array.
	 *
	 * @param array $property			 The property to handle
	 * @param string $propertyName  	 The name of the property
	 * @param array $items			     The contents of the property
	 * @param ObjectEntity $objectEntity The objectEntity the data belongs to
	 * @param int $register				 The register connected to the objectEntity
	 * @param int $schema				 The schema connected to the objectEntity
	 *
	 * @return array The updated item
	 * @throws GuzzleException
	 */
	private function handleArrayProperty(
		array        $property,
		string       $propertyName,
		array        $items,
		ObjectEntity $objectEntity,
		int          $register,
		int          $schema
	): array
	{
		if(isset($property['items']) === false) {
			return $items;
		}

		if(isset($property['items']['oneOf'])) {
			foreach($items as $index=>$item) {
				$items[$index] = $this->handleOneOfProperty(
					property: $property['items']['oneOf'],
					propertyName: $propertyName,
					item: $item,
					objectEntity: $objectEntity,
					register: $register,
					schema: $schema,
					index: $index
				);
			}
			return $items;
		}

		if ($property['items']['type'] !== 'object'
			&& $property['items']['type'] !== 'file'
		) {
			return $items;
		}

		if ($property['items']['type'] === 'file')
		{
			foreach($items as $index => $item) {
				$items[$index] = $this->handleFileProperty(
					objectEntity: $objectEntity,
					object: [$propertyName => [$index => $item]],
					propertyName: $propertyName . '.' . $index
				)[$propertyName];
			}
			return $items;
		}

		foreach($items as $index=>$item) {
			$items[$index] = $this->addObject(
				property: $property['items'],
				propertyName: $propertyName,
				item: $item,
				objectEntity: $objectEntity,
				register: $register,
				schema: $schema,
				index: $index
			);
		}

		return $items;
	}

	/**
	 * Handles a property that of the type oneOf.
	 *
	 * @param array $property			 The property to handle
	 * @param string $propertyName  	 The name of the property
	 * @param string|array $item		 The contents of the property
	 * @param ObjectEntity $objectEntity The objectEntity the data belongs to
	 * @param int $register				 The register connected to the objectEntity
	 * @param int $schema				 The schema connected to the objectEntity
	 * @param int|null $index			 If the oneOf is in an array, the index within the array
	 *
	 * @return string|array The updated item
	 * @throws GuzzleException
	 */
	private function handleOneOfProperty(
		array        $property,
		string       $propertyName,
		string|array $item,
		ObjectEntity $objectEntity,
		int          $register,
		int          $schema,
		?int		 $index = null
	): string|array
	{
		if (array_is_list($property) === false) {
			return $item;
		}

		if (in_array(needle:'file', haystack: array_column(array: $property, column_key: 'type')) === true
			&& is_array($item) === true
			&& $index !== null
		) {
			return $this->handleFileProperty(
				objectEntity: $objectEntity,
				object: [$propertyName => [$index => $item]],
				propertyName: $propertyName
			);
		}
		if (in_array(needle:'file', haystack: array_column(array: $property, column_key: 'type')) === true
			&& is_array($item) === true
			&& $index === null
		) {
			return $this->handleFileProperty(
				objectEntity: $objectEntity,
				object: [$propertyName => $item],
				propertyName: $propertyName
			);
		}

		if (array_column(array: $property, column_key: '$ref') === []) {
			return $item;
		}

		if (is_array($item) === false) {
			return $item;
		}

		$oneOf = array_filter(
			array: $property,
			callback: function (array $option) {
				return isset($option['$ref']) === true;
			}
		)[0];

		return $this->addObject(
			property: $oneOf,
			propertyName: $propertyName,
			item: $item,
			objectEntity: $objectEntity,
			register: $register,
			schema: $schema,
			index: $index
		);
	}

	/**
	 * Rewrites subobjects stored in separate objectentities to the Uuid of that object,
	 * rewrites files to the chosen format
	 *
	 * @param array $property	   		 The content of the property in the schema
	 * @param string $propertyName 		 The name of the property
	 * @param int $register		   		 The register the main object is in
	 * @param int $schema		   		 The schema of the main object
	 * @param array $object		   		 The object to rewrite
	 * @param ObjectEntity $objectEntity The objectEntity to write the object in
	 *
	 * @return array The resulting object
	 * @throws GuzzleException
	 */
	private function handleProperty (
		array $property,
		string $propertyName,
		int $register,
		int $schema,
		array $object,
		ObjectEntity $objectEntity
	): array
	{
		switch($property['type']) {
			case 'object':
				$object[$propertyName] = $this->handleObjectProperty(
					property: $property,
					propertyName: $propertyName,
					item: $object[$propertyName],
					objectEntity: $objectEntity,
					register: $register,
					schema: $schema,
				);
				break;
			case 'array':
				$object[$propertyName] = $this->handleArrayProperty(
					property: $property,
					propertyName: $propertyName,
					items: $object[$propertyName],
					objectEntity: $objectEntity,
					register: $register,
					schema: $schema,
				);
				break;
			case 'oneOf':
				$object[$propertyName] = $this->handleOneOfProperty(
					property: $property['oneOf'],
					propertyName: $propertyName,
					item: $object[$propertyName],
					objectEntity: $objectEntity,
					register: $register,
					schema: $schema);
				break;
			case 'file':
				$object[$propertyName] = $this->handleFileProperty(
					objectEntity: $objectEntity,
					object: $object,
					propertyName: $propertyName
				);
			default:
				break;
		}

		return $object;
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

			$object = $this->handleProperty(
				property: $property,
				propertyName: $propertyName,
				register: $register,
				schema: $schema,
				object: $object,
				objectEntity: $objectEntity,
			);
		}

		$objectEntity->setObject($object);

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
			$encodedUrl = rawurlencode($objectDot->get("$propertyName.accessUrl")); //@todo hardcoded .downloadUrl

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
