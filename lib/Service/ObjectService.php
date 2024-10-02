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

		// Lets see if we need to save to an internal source
		//if ($register->getSource() === 'internal') {
			$objectEntity = new ObjectEntity();
			$objectEntity->setRegister($register->getId());
			$objectEntity->setSchema($schema->getId());
			$objectEntity->setObject($object);

			if (isset($object['id'])) {
				// Update existing object
				$objectEntity->setUuid($object['id']);
				return $this->objectEntityMapper->update($objectEntity);
			} else {
				// Create new object
				$objectEntity->setUuid(Uuid::v4());
				return $this->objectEntityMapper->insert($objectEntity);
			}
		//}

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
			return $this->objectEntityMapper->findByUuid($uuid);	
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
}
