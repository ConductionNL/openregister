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
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use Symfony\Component\Uid\Uuid;
use GuzzleHttp\Client;

class ObjectService
{

	private int $register;
	private int $schema;

	private AuditTrailMapper $auditTrailMapper;

	/**
	 * The constructor sets al needed variables.
	 *
	 * @param ObjectEntityMapper  $objectEntityMapper The ObjectEntity Mapper
	 */
	public function __construct(
		ObjectEntityMapper $objectEntityMapper,
		RegisterMapper $registerMapper,
		SchemaMapper $schemaMapper,
		AuditTrailMapper $auditTrailMapper
	)
	{
		$this->objectEntityMapper = $objectEntityMapper;
		$this->registerMapper = $registerMapper;
		$this->schemaMapper = $schemaMapper;
		$this->auditTrailMapper = $auditTrailMapper;
	}

	public function find(int|string $id) {
		return $this->getObject(
			register: $this->registerMapper->find($this->getRegister()),
			schema: $this->schemaMapper->find($this->getSchema()),
			uuid: $id
		);
	}

	public function createFromArray(array $object) {
		return $this->saveObject(
			register: $this->getRegister(),
			schema: $this->getSchema(),
			object: $object
		);
	}

	public function updateFromArray(string $id, array $object, bool $updatedObject) {
		$object['id'] = $id;

		return $this->saveObject(
			register: $this->getRegister(),
			schema: $this->getSchema(),
			object: $object
		);
	}

	public function delete(array|\JsonSerializable $object): bool
	{
		if($object instanceof \JsonSerializable === true) {
			$object = $object->jsonSerialize();
		}

		return $this->deleteObject(
			register: $this->registerMapper->find($this->getRegister()),
			schema: $this->schemaMapper->find($this->getSchema()),
			uuid: $object['id']
		);
	}

	public function findAll(?int $limit = null, ?int $offset = null, array $filters = [], array $sort = [], ?string $search = null): array
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
//		$data = array_map([$this, 'getDataFromObject'], $objects);

		return $objects;
	}

	public function count(array $filters = [], ?string $search = null): int
	{
		if($this->getSchema() !== null && $this->getRegister() !== null) {
			$filters['register'] = $this->getRegister();
			$filters['schema']   = $this->getSchema();
		}
		$count = $this->objectEntityMapper
			->countAll(filters: $filters, search: $search);

		return $count;
	}

	public function findMultiple(array $ids): array
	{
		$result = [];
		foreach($ids as $id) {
			$result[] = $this->find($id);
		}

		return $result;
	}

	public function getAggregations(array $filters, ?string $search = null): array
	{
		$mapper = $this->getMapper(objectType: 'objectEntity');

		$filters['register'] = $this->getRegister();
		$filters['schema']   = $this->getSchema();

		if ($mapper instanceof ObjectEntityMapper === true) {
			$facets = $this->objectEntityMapper->getFacets($filters, $search);
			return $facets;
		}

		return [];
	}

	private function getDataFromObject(mixed $object) {

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
	 * @return array The retrieved objects.
	 * @throws \Exception
	 */
	public function getObjects(?string $objectType = null, ?int $register = null, ?int $schema = null, ?int $limit = null, ?int $offset = null, array $filters = [], array $sort = [], ?string $search = null): array
	{
		if($objectType === null && $register !== null && $schema !== null) {
			$objectType 		 = 'objectEntity';
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
	 * @param Register|string $register	The register to save the object to.
	 * @param Schema|string $schema		The schema to save the object to.
	 * @param array $object			The data to be saved.
	 *
	 * @return ObjectEntity The resulting object.
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

		if(isset($object['id']) === true) {
			// Does the object already exist?
			$objectEntity = $this->objectEntityMapper->findByUuid($this->registerMapper->find($register), $this->schemaMapper->find($schema), $object['id']);
		}

		if ($objectEntity === null) {
			$objectEntity = new ObjectEntity();
			$objectEntity->setRegister($register);
			$objectEntity->setSchema($schema);
			///return $this->objectEntityMapper->update($objectEntity);
		}
		// Does the object have an if?
		if (isset($object['id']) && !empty($object['id'])) {
			// Update existing object
			$objectEntity->setUuid($object['id']);
		} else {
			// Create new object
			$objectEntity->setUuid(Uuid::v4());
			$object['id'] = $objectEntity->getUuid();
		}

		$oldObject = $objectEntity->getObject();
		$objectEntity->setObject($object);
		
		// If the object has no uuid, create a new one
		if (empty($objectEntity->getUuid())) {
			$objectEntity->setUuid(Uuid::v4());
		}

		if($objectEntity->getId()){
			$objectEntity = $this->objectEntityMapper->update($objectEntity);
			$this->auditTrailMapper->createAuditTrail(new: $objectEntity, old: $oldObject);
		}
		else {
			$objectEntity =  $this->objectEntityMapper->insert($objectEntity);
			$this->auditTrailMapper->createAuditTrail(new: $objectEntity);
		}

		return $objectEntity;
	}


	/**
	 * Get an object
	 *
	 * @param Register $register	The register to save the object to.
	 * @param string $uuid	The uuid of the object to get
	 *
	 * @return ObjectEntity The resulting object.
	 */
	public function getObject(Register $register, Schema $schema, string $uuid): ObjectEntity
	{

		// Lets see if we need to save to an internal source
		if ($register->getSource() === 'internal' || $register->getSource() === '') {
			return $this->objectEntityMapper->findByUuid($register, $schema, $uuid);
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
   public function deleteObject(Register $register, Schema $schema, string $uuid): bool
   {
	// Lets see if we need to save to an internal source
	if ($register->getSource() === 'internal' || $register->getSource() === '') {
	   $object = $this->objectEntityMapper->findByUuid(register: $register, schema: $schema, uuid: $uuid);
	   $this->objectEntityMapper->delete($object);

	   return true;
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
	public function getMapper(?string $objectType = null, ?int $register = null, ?int $schema = null)
	{
		if($register !== null && $schema !== null) {
			$this->setSchema($schema);
			$this->setRegister($register);

			return $this;
		}

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

	public function getRegister(): int
	{
		return $this->register;
	}

	public function setRegister(int $register): void
	{
		$this->register = $register;
	}

	public function getSchema(): int
	{
		return $this->schema;
	}

	public function setSchema(int $schema): void
	{
		$this->schema = $schema;
	}
}
