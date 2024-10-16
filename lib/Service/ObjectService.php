<?php

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\MongoDbService;
use Exception;

/**
 * ObjectService
 *
 * This service class handles operations related to objects in the OpenRegister system.
 */
class ObjectService
{

	/**
	 * The constructor sets al needed variables.
	 *
	 * @param ObjectEntityMapper $objectEntityMapper The ObjectEntity Mapper
	 * @param RegisterMapper 	 $registerMapper 	 The Register Mapper
	 * @param SchemaMapper 		 $schemaMapper 		 The Schema Mapper
	 * @param SourceMapper 		 $sourceMapper 		 The Source Mapper
	 * @param MongoDbService 	 $mongoDbService 	 The MongoDB Service
	 */
	public function __construct (
		private readonly ObjectEntityMapper $objectEntityMapper,
		private readonly RegisterMapper 	$registerMapper,
		private readonly SchemaMapper 		$schemaMapper,
		private readonly SourceMapper 		$sourceMapper,
		private readonly MongoDbService 	$mongoDbService
	)
	{
	}//end __construct()


	/**
	 * Validates the mongodb source.
	 *
	 * @param Source $source The source to validate.
	 *
	 * @throws Exception
	 */
	private function validateMongoDBSource(Source $source): void
	{
		if ($source->getDatabaseUrl() === null) {
			throw new Exception('Source database url is not set, cannot save object.');
		}
		if ($source->getDatabaseAuth() === null) {
			throw new Exception('Source database authorization is not set, cannot save object.');
		}
	}//end validateMongoDBSource()

	/**
	 * Get the MongoDB config.
	 *
	 * @param Source $source The source to get the config for.
	 *
	 * @return array The MongoDB config.
	 */
	private function getMongoDBConfig(Source $source): array
	{
		$headers = ['apiKey' => $source->getDatabaseAuth()];
		$config = ['base_uri' => $source->getDatabaseUrl(), 'headers' => $headers, 'dataSource' => $source->getTitle()];

		return $config;
	}//end getMongoDBConfig()

	/**
	 * Save an object to internal database or external source.
	 *
	 * An external source could be mongodb or other databases.
	 *
	 * @param array    $object	The data to be saved.
	 * @param string|null $id  	The id of the object to be saved, might be null when creating a new object.
	 *
	 * @throws Exception
	 *
	 * @return ObjectEntity|array The resulting object.
	 */
	public function saveObject(array $object, string $id = null)
	{

		// Convert register and schema to their respective objects if they are strings
		if (isset($object['register']) === true) {
			$register = $this->registerMapper->find(id: (int) $object['register']);
		}

		if (isset($object['schema']) === true) {
			$schema = $this->schemaMapper->find(id: (int) $object['schema']);
		}

		if ($register === null) {
			throw new Exception('Register not given or found');
		}
		if ($schema === null) {
			throw new Exception('Schema not given or found');
		}

		// What rules do we need to check before we are allowed to save a ObjectEntity?
		// - Object->register must be set?
		// - Object->register->source must be set?
		// - Object->schema must be set?
		// - Validate if the given schema is in the given register->schemas array?
		// - Validate object against schema?

		// Check the source of the register.
		if ($register->getSource() !== null) {
			$source = $this->sourceMapper->find(id: (int) $register->getSource());
			if ($source === null) {
				throw new Exception("Source not found with id: {$register->getSource()}");
			}


			// Check if we need to use the mongodb service.
			if ($source->getType() === 'mongodb') {
				$this->validateMongoDBSource(source: $source);
				$config = $this->getMongoDBConfig(source: $source);

				if (isset($id) === true) {
					// Update the object in mongodb.
					$object['id'] = $id;
					$object['_id'] = $id;
					$object = $this->mongoDbService->updateObject(filters: ['_id' => $id], update: $object, config: $config);
					$object['uuid'] = $object['_id'];
					unset($object['_id']);

					return $object;
				} else {
					// Create the object in mongodb.
					$object = $this->mongoDbService->saveObject(data: $object, config: $config);
					$object['uuid'] = $object['_id'];
					unset($object['_id']);

					return $object;
				}
			}

			// Throw exception if we do not support the source type.
			throw new Exception('Unsupported source type, cannot save object.');
		}

		// Does the object already exist?
		if (isset($id) === true) {
			// Default to internal database.
			unset($object['id']);
			return $this->objectEntityMapper->updateFromArray(id: $id, object: $object);
		}

		// If we don't have an object, create a new one.
		return $this->objectEntityMapper->createFromArray($object);
	}//end saveObject()


	/**
	 * Get an object
	 *
	 * @param int   $register The register to get the object from.
	 * @param string $id      The id of the object to get
	 *
	 * @throws Exception
	 *
	 * @return ObjectEntity|array The resulting object.
	 */
	public function getObject(int $register, string $id): ObjectEntity|array
	{
		$register = $this->registerMapper->find($register);
		if ($register === null) {
			throw new Exception("Register not found with id: $register");
		}

		$source = $this->sourceMapper->find($register->getSource());
		if ($source === null) {
			throw new Exception("Source not found with id: $register->getSource()");
		}

		// Fetch from internal database.
		if ($register->getSource() === 'internal') {
			$schemas = $register->getSchemas();
			if (empty($schemas) === true) {
				throw new Exception("No schemas found for register with id: $register");
			}

			$schema = $this->schemaMapper->find($schemas[0]);

			return $this->objectEntityMapper->findByUuid(register: $register, schema: $schema, uuid: $id);
		}

		// Fetch from mongodb.
		if ($source->getType() === 'mongodb') {
			$this->validateMongoDBSource(source: $source);
			$config = $this->getMongoDBConfig(source: $source);

			$result = $this->mongoDbService->findObject(filters: ['_id' => $id], config: $config);
			$result['uuid'] = $result['_id'];
			unset($result['_id']);

			return $result;
		}

		// Handle external source here if needed
		throw new Exception('Unsupported source type');
	}//end getObject()


	/**
	 * Get objects
	 *
	 * @param int   $register The register to get the object from.
	 *
	 * @throws Exception
	 *
	 * @return ObjectEntity|array The resulting object.
	 */
	public function getObjects(int $register): ObjectEntity|array
	{
		$register = $this->registerMapper->find($register);
		if ($register === null) {
			throw new Exception("Register not found with id: $register");
		}

		$source = $this->sourceMapper->find($register->getSource());
		if ($source === null) {
			throw new Exception("Source not found with id: $register->getSource()");
		}

		// Fetch from internal database.
		if ($register->getSource() === 'internal') {
			$schemas = $register->getSchemas();
			if (empty($schemas) === true) {
				throw new Exception("No schemas found for register with id: $register");
			}

			$schema = $this->schemaMapper->find($schemas[0]);

			$objects = $this->objectEntityMapper->findByRegisterAndSchema(register: $register->getId(), schema: $schema->getId());

			return ['results' => $objects, 'total' => count($objects)];
		}//endif

		// Fetch from mongodb.
		if ($source->getType() === 'mongodb') {
			$this->validateMongoDBSource(source: $source);
			$config = $this->getMongoDBConfig(source: $source);

			$documents = $this->mongoDbService->findObjects(filters: ['register' => $register->getId()], config: $config);
			if (isset($documents['documents']) === false) {
				throw new Exception("No objects found for register with id: $register, and source with id: $source");
			}

			$results = [];
			foreach ($documents['documents'] as $key => $document) {
				$results[$key] = $document;
				$results[$key]['uuid'] = $document['_id'];
				unset($results[$key]['_id']);
			}

			return ['results' => $results, 'total' => count($documents['documents'])];
		}//endif

		// Handle external source here if needed
		throw new Exception('Unsupported source type');
	}//end getObject()

	/**
	* Delete an object
	*
	* @param int $register	The register to delete the object from.
	* @param int|string $id	The uuid of the object to delete
	*
	* @throws Exception
	*
	* @return void
	*/
   public function deleteObject(int $register, $id): void
   {
		$register = $this->registerMapper->find($register);
		if ($register === null) {
			throw new Exception("Register not found with id: $register");
		}

		$source = $this->sourceMapper->find($register->getSource());
		if ($source === null) {
			throw new Exception("Source not found with id: $register->getSource()");
		}


		// Lets see if we need to save to an internal source
		if ($source->getType() === 'internal') {
			// do we need to fetch before we can delete?
			// $object = $this->objectEntityMapper->findByUuid((int) $id);

			// @todo objectEntityMapper needs a delete method
			// $this->objectEntityMapper->delete($object);

			throw new Exception("Deleting objects from internal source is not supported yet.");
		}//endif


		// Delete from mongodb.
		if ($source->getType() === 'mongodb') {
			$this->validateMongoDBSource(source: $source);
			$config = $this->getMongoDBConfig(source: $source);
			$this->mongoDbService->deleteObject(filters: ['_id' => $id], config: $config);

			return;
		}//endif

		// Handle external source here if needed
		throw new Exception('Unsupported source type');
	}//end deleteObject()

	/**
	 * Gets the appropriate mapper based on the object type.
	 *
	 * @param string $objectType The type of object to retrieve the mapper for.
	 * @return mixed The appropriate mapper.
	 * @throws \InvalidArgumentException If an unknown object type is provided.
	 * @throws \Exception If OpenRegister service is not available or if register/schema is not configured.
	 */
	public function getMapper(string $objectType)
	{
		// If the source is internal, return the appropriate mapper based on the object type
		switch ($objectType) {
			case 'register':
				return $this->registerMapper;
			case 'schema':
				return $this->schemaMapper;
			case 'objectEntity':
				return $this->objectEntityMapper;
			default:
				throw new \InvalidArgumentException("Unknown object type: $objectType");
		}//endswitch
	}//end getMapper()

	/**
	 * Gets multiple objects based on the object type and ids.
	 *
	 * @param string $objectType The type of objects to retrieve.
	 * @param array $ids The ids of the objects to retrieve.
	 * @return array The retrieved objects.
	 * @throws \InvalidArgumentException If an unknown object type is provided.
	 */
	public function getMultipleObjects(string $objectType, array $ids)
	{
		// Process the ids
		$processedIds = array_map(function($id) {
			if (is_object($id) && method_exists($id, 'getId')) {
				return $id->getId();
			} elseif (is_array($id) && isset($id['id'])) {
				return $id['id'];
			} else {
				return $id;
			}
		}, $ids);

		// Clean up the ids if they are URIs
		$cleanedIds = array_map(function($id) {
			// If the id is a URI, get only the last part of the path
			if (filter_var($id, FILTER_VALIDATE_URL)) {
				$parts = explode('/', rtrim($id, '/'));
				return end($parts);
			}
			return $id;
		}, $processedIds);

		// Get the appropriate mapper for the object type
		$mapper = $this->getMapper($objectType);

		// Use the mapper to find and return multiple objects based on the provided cleaned ids
		return $mapper->findMultiple($cleanedIds);
	}//end getMultipleObjects()

	/**
	 * Extends an entity with related objects based on the extend array.
	 *
	 * @param mixed $entity The entity to extend
	 * @param array $extend An array of properties to extend
	 * @return array The extended entity as an array
	 * @throws \Exception If a property is not present on the entity
	 */
	public function extendEntity(array $entity, array $extend): array
	{
		// Convert the entity to an array if it's not already one
		if (is_array($entity) === true) {
			$result = $entity;
		} else {
			$result = $entity->jsonSerialize();
		}

		// Iterate through each property to be extended
		foreach ($extend as $property) {
			// Create a singular property name
			$singularProperty = rtrim($property, 's');

			// Check if property or singular property are keys in the array
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

			// Get a mapper for the property
			$propertyObject = $property;
			try {
				$mapper = $this->getMapper(objectType: $property);
				$propertyObject = $singularProperty;
			} catch (\Exception $e) {
				try {
					$mapper = $this->getMapper(objectType: $singularProperty);
					$propertyObject = $singularProperty;
				} catch (\Exception $e) {
					// If still no mapper, throw a no mapper available error
					throw new \Exception(message: "No mapper available for property '$property'.");
				}
			}

			// Update the values
			if (is_array($value) === true) {
				// If the value is an array, get multiple related objects
				$result[$property] = $this->getMultipleObjects(objectType: $propertyObject, ids: $value);
			} else {
				// If the value is not an array, get a single related object
				$objectId = is_object(value: $value) ? $value->getId() : $value;
				$result[$property] = $mapper->find($objectId);
			}
		}

		// Return the extended entity as an array
		return $result;
	}//end extendEntity()

	/**
	 * Get the registers extended with schemas for this instance of OpenRegisters
	 *
	 * @return array The registers of this OpenRegisters instance extended with schemas
	 * @throws \Exception
	 */
   public function getRegisters(): array
   {
	   $registers = $this->registerMapper->findAll();


	   // Convert entity objects to arrays using jsonSerialize
	   $registers = array_map(function($object) {
		   return $object->jsonSerialize();
	   }, $registers);

	   $extend = ['schemas'];
	   // Extend the objects if the extend array is not empty
	   if(empty($extend) === false) {
		   $registers = array_map(function($object) use ($extend) {
			   return $this->extendEntity(entity: $object, extend: $extend);
		   }, $registers);
	   }

	   return $registers;
	}//end getRegisters()
}
