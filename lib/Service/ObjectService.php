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
use Symfony\Component\Uid\Uuid;
use GuzzleHttp\Client;

class ObjectService
{
	private $callLogMapper;

	/**
	 * The constructor sets al needed variables.
	 *
	 * @param ObjectEntityMapper  $objectEntityMapper The ObjectEntity Mapper
	 */
	public function __construct(ObjectEntityMapper $objectEntityMapper, RegisterMapper $registerMapper, SchemaMapper $schemaMapper)
	{
		$this->objectEntityMapper = $objectEntityMapper;
		$this->registerMapper = $registerMapper;
		$this->schemaMapper = $schemaMapper;
	}

	/**
	 * Save an object
	 *
	 * @param Register|string $register	The register to save the object to.
	 * @param Schema|string $schema		The schema to save the object to.
	 * @param array $object			The data to be saved.
	 *
	 * @return ObjectEntity The resulting object.
	 */
	public function saveObject($register, $schema, array $object): ObjectEntity
	{

		// Convert register and schema to their respective objects if they are strings
		if (is_string($register)) {
			$register = $this->registerMapper->find($register);
		}
		if (is_string($schema)) {
			$schema = $this->schemaMapper->find($schema);
		}

		// Does the object already exist?
		$objectEntity = $this->objectEntityMapper->findByUuid($register, $schema, $object['id']);

		if ($objectEntity === null){
			$objectEntity = new ObjectEntity();
			$objectEntity->setRegister($register->getId());
			$objectEntity->setSchema($schema->getId());
			///return $this->objectEntityMapper->update($objectEntity);
		}


		// Does the object have an if?
		if (isset($object['id'])) {
			// Update existing object
			$objectEntity->setUuid($object['id']);
		} else {
			// Create new object
			$objectEntity->setUuid(Uuid::v4());
			$object['id'] = $objectEntity->getUuid();
		}

		$objectEntity->setObject($object);

		if ($objectEntity->getId()){
			return $this->objectEntityMapper->update($objectEntity);
		}
		return $this->objectEntityMapper->insert($objectEntity);

		//@todo mongodb support

		// Handle external source here if needed
		throw new \Exception('Unsupported source type');
	}


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
	}

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
   }

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
	}

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
	}

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
		if(is_array($entity)) {
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
	}

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
   }
}
