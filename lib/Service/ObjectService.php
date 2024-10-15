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
	 * Save an object to internal database or external source.
	 *
	 * An external source could be mongodb or other databases.
	 *
	 * @param array    $object	The data to be saved.
	 * @param int|null $id  	The id of the object to be saved, might be null when creating a new object.
	 *
	 * @return ObjectEntity The resulting object.
	 */
	public function saveObject(array $object, ?int $id = null): ObjectEntity
	{

		// Convert register and schema to their respective objects if they are strings
		if (is_string($object['register']) === true) {
			$register = $this->registerMapper->find(id: (int) $object['register']);
		}
		if (is_string($object['schema']) === true) {
			$schema = $this->schemaMapper->find(id: (int) $object['schema']);
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

			// Check if we need to use the mongodb service.
			if (isset($source) === true && $source->getType() === 'mongodb' && $source->getDatabaseUrl() !== null) {
				if (isset($id) === true) {
					// Update the object in mongodb.
					unset($object['id']);
					$object = $this->mongoDbService->updateObject(filters: ['_id' => $id], update: $object, config: ['mongodbCluster' => $source->getDatabaseUrl()]);
				} else {
					// Create the object in mongodb.
					$object = $this->mongoDbService->saveObject(data: $object, config: ['mongodbCluster' => $source->getDatabaseUrl()]);
				}
			}

			// Throw exception if source is set and the database url is null.
			if (isset($source) === true && $source->getDatabaseUrl() === null) {
				// Handle external source here if needed
				throw new Exception('Source database url is not set, cannot save object.');
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
	 * @param Register $register	The register to save the object to.
	 * @param string $uuid	The uuid of the object to get
	 *
	 * @return ObjectEntity The resulting object.
	 */
	public function getObject(Register $register, string $uuid): ObjectEntity
	{
		// Lets see if we need to save to an internal source
		if ($register->getSource() === 'internal') {
			return $this->objectEntityMapper->findByUuid($register,$uuid);
		}

		//@todo mongodb support

		// Handle external source here if needed
		throw new \Exception('Unsupported source type');
	}//end getObject()

	/**
	* Delete an object
	*
	* @param Register $register	The register to delete the object from.
	* @param string $uuid	The uuid of the object to delete

	* @return ObjectEntity The resulting object.
	*/
   public function deleteObject(Register $register, string $uuid)
   {
		// Lets see if we need to save to an internal source
		if ($register->getSource() === 'internal') {
		$object = $this->objectEntityMapper->findByUuid($uuid);
		$this->objectEntityMapper->delete($object);
		}

		//@todo mongodb support

		// Handle external source here if needed
		throw new \Exception('Unsupported source type');
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
		}
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
