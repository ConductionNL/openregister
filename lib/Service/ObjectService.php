<?php
/**
 * OpenRegister ObjectService
 *
 * Service class for managing objects in the OpenRegister application.
 *
 * This service acts as a facade for the various object handlers,
 * coordinating operations between them and maintaining state.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\RenderObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service class for managing objects in the OpenRegister application.
 *
 * This service acts as a facade for the various object handlers,
 * coordinating operations between them and maintaining state.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class ObjectService
{

    private ?Register $currentRegister = null;

    private ?Schema $currentSchema = null;

    private ?ObjectEntity $currentObject = null;


    /**
     * Constructor for ObjectService.
     *
     * @param DeleteObject   $deleteHandler   Handler for object deletion.
     * @param GetObject      $getHandler      Handler for object retrieval.
     * @param RenderObject   $renderHandler   Handler for object rendering.
     * @param SaveObject     $saveHandler     Handler for object saving.
     * @param ValidateObject $validateHandler Handler for object validation.
     */
    public function __construct(
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObject $saveHandler,
        private readonly ValidateObject $validateHandler,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper
    ) {

    }//end __construct()


    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return self
     */
    public function setRegister(Register | string | int $register): self
    {
        if (is_string($register) || is_int($register)) {
            // Look up the register by ID or UUID
            $register = $this->registerMapper->find($register);
        }

        $this->currentRegister = $register;
        return $this;

    }//end setRegister()


    /**
     * Set the current schema context.
     *
     * @param Schema|string|int $schema The schema object or its ID/UUID
     *
     * @return self
     */
    public function setSchema(Schema | string | int $schema): self
    {
        if (is_string($schema) || is_int($schema)) {
            // Look up the schema by ID or UUID
            $schema = $this->schemaMapper->find($schema);
        }

        $this->currentSchema = $schema;
        return $this;

    }//end setSchema()


    /**
     * Set the current object context.
     *
     * @param ObjectEntity|string|int $object The object entity or its ID/UUID
     *
     * @return self
     */
    public function setObject(ObjectEntity | string | int $object): self
    {
        if (is_string($object) || is_int($object)) {
            // Look up the object by ID or UUID
            $object = $this->objectEntityMapper->find($object);
        }

        $this->currentObject = $object;
        return $this;

    }//end setObject()


    /**
     * Finds an object by ID or UUID and renders it.
     *
     * @param int|string               $id       The object ID or UUID.
     * @param array|null               $extend   Properties to extend the object with.
     * @param bool                     $files    Whether to include file information.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return ObjectEntity|null The rendered object or null.
     *
     * @throws Exception If the object is not found.
     */
    public function find(int | string $id, ?array $extend=[], bool $files=false, Register | string | int | null $register=null, Schema | string | int | null $schema=null): ?ObjectEntity
    {
        // Check if a register is provided and set the current register context
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Retrieve the object using the current register, schema, ID, extend properties, and file information
        $object = $this->getHandler->find(
            $this->currentRegister,
            $this->currentSchema,
            $id,
            $extend,
            $files
        );

        // If the object is not found, return null
        if ($object === null) {
            return null;
        }

        // Render the object before returning
        return $this->renderHandler->renderEntity(
            entity: $object,
            extend: $extend,
            registers: [$this->currentRegister->getId() => $this->currentRegister],
            schemas: [$this->currentSchema->getId() => $this->currentSchema]
        );

    }//end find()


    /**
     * Creates a new object from an array.
     *
     * @param array                    $object   The object data to create.
     * @param array|null               $extend   Properties to extend the object with.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return array The created object.
     *
     * @throws Exception If there is an error during creation.
     */
    public function createFromArray(
        array $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null
    ): array {
        // Check if a register is provided and set the current register context
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Validate the object against the current schema
        $result = $this->validateHandler->validateObject($object, $this->currentSchema);
        if ($result->isValid() === false) {
            throw new ValidationException($result->error()->message());
        }

        // Save the object using the current register and schema
        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object
        );

        // Render and return the saved object 
        return $this->renderHandler->renderEntity(
            entity: $object,
            extend: $extend,
            registers: [$this->currentRegister->getId() => $this->currentRegister],
            schemas: [$this->currentSchema->getId() => $this->currentSchema]
        );

    }//end createFromArray()


    /**
     * Updates an object from an array.
     *
     * @param string                   $id            The ID of the object to update.
     * @param array                    $object        The updated object data.
     * @param bool                     $updateVersion Whether to update the version.
     * @param bool                     $patch         Whether this is a patch update.
     * @param array|null               $extend        Properties to extend the object with.
     * @param Register|string|int|null $register      The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema        The schema object or its ID/UUID.
     *
     * @return array The updated object.
     *
     * @throws Exception If there is an error during update.
     */
    public function updateFromArray(
        string $id,
            array $object,
        bool $updateVersion,
        bool $patch=false,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null
    ): array {
        // Check if a register is provided and set the current register context
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Retrieve the existing object by its UUID
        $existingObject = $this->getHandler->find($id);
        if ($existingObject === null) {
            throw new DoesNotExistException('Object not found');
        }

        // If patch is true, merge the existing object with the new data
        if ($patch === true) {
            $object = array_merge($existingObject->getObject(), $object);
        }

        // Validate the object against the current schema
        $result = $this->validateHandler->validateObject($object, $this->currentSchema);
        if ($result->isValid() === false) {
            throw new ValidationException($result->error()->message());
        }

        // Save the object using the current register and schema
        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object
        );

        // Render and return the saved object 
        return $this->renderHandler->renderEntity(
            entity: $object,
            extend: $extend,
            registers: [$this->currentRegister->getId() => $this->currentRegister],
            schemas: [$this->currentSchema->getId() => $this->currentSchema]
        );

    }//end updateFromArray()


    /**
     * Deletes an object.
     *
     * @param array|JsonSerializable $object The object to delete.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     */
    public function delete(array | JsonSerializable $object): bool
    {
        return $this->deleteHandler->delete($object);

    }//end delete()


    /**
     * Find all objects matching the configuration.
     *
     * @param array $config Configuration array containing:
     *                      - limit: Maximum number of objects to return
     *                      - offset: Number of objects to skip
     *                      - filters: Filter criteria
     *                      - sort: Sort criteria
     *                      - search: Search term
     *                      - extend: Properties to extend
     *                      - files: Whether to include file information
     *                      - uses: Filter by object usage
     *                      - register: Optional register to filter by
     *                      - schema: Optional schema to filter by
     *                      - unset: Fields to unset from results
     *                      - fields: Fields to include in results
     *                      - ids: Array of IDs or UUIDs to filter by
     *
     * @return array Array of objects matching the configuration
     */
    public function findAll(array $config=[]): array
    {
        // Convert extend to an array if it's a string
        if (is_string($config['extend'])) {
            $config['extend'] = explode(',', $config['extend']);
        }

        // Set the current register context if a register is provided
        if (isset($config['filters']['register'])) {
            $this->setRegister($config['filters']['register']);
        }

        // Set the current schema context if a schema is provided
        if (isset($config['filters']['schema'])) {
            $this->setSchema($config['filters']['schema']);
        }

        // Delegate the findAll operation to the handler
        $objects = $this->getHandler->findAll(
            limit: $config['limit'] ?? null,
            offset: $config['offset'] ?? null,
            filters: $config['filters'] ?? [],
            sort: $config['sort'] ?? [],
            search: $config['search'] ?? null,
            files: $config['files'] ?? false,
            uses: $config['uses'] ?? null,
            ids: $config['ids'] ?? null
        );
        
        // Determine if register and schema should be passed to renderEntity
        $registers = isset($config['filters']['register']) ? [$this->currentRegister->getId() => $this->currentRegister] : null;
        $schemas = isset($config['filters']['schema']) ? [$this->currentSchema->getId() => $this->currentSchema] : null;

        // Check if '@self.schema' or '@self.register' is in extend but not in filters
        if (isset($config['extend']) && in_array('@self.schema', (array)$config['extend'], true) && $schemas === null) {
            $schemaIds = array_unique(array_filter(array_map(fn($object) => $object->getSchema() ?? null, $objects)));
            $schemas = $this->schemaMapper->findMultiple(ids: $schemaIds);
            $schemas = array_combine(array_map(fn($schema) => $schema->getId(), $schemas), $schemas);
        }

        if (isset($config['extend']) && in_array('@self.register', (array)$config['extend'], true) && $registers === null) {
            $registerIds = array_unique(array_filter(array_map(fn($object) => $object->getRegister() ?? null, $objects)));
            $registers = $this->registerMapper->findMultiple(ids:  $registerIds);
            $registers = array_combine(array_map(fn($register) => $register->getId(), $registers), $registers);
        }

        // Render each object through the object service
        foreach ($objects as $key => $object) {
            $objects[$key] = $this->renderHandler->renderEntity(
                entity: $object,
                extend: $config['extend'] ?? [],
                filter: $config['unset'] ?? null,
                fields: $config['fields'] ?? null,
                registers: $registers,
                schemas: $schemas
            );
        }

        return $objects;

    }//end findAll()


    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array       $filters Filter criteria.
     * @param string|null $search  Search term.
     *
     * @return int The number of matching objects.
     * @throws \Exception If register or schema is not set
     */
    public function count(
        array $config=[]
    ): int {
        // Add register and schema IDs to filters// Ensure we have both register and schema set
        if ($this->currentRegister !== null && empty($config['filers']['register']) === true ) {
            $filters['register'] = $this->currentRegister->getId();
        }

        if ($this->currentSchema !== null && empty($config['filers']['schema']) === true ) {
            $config['filers']['schema']   = $this->currentSchema->getId();
        }
        
        unset($config['limit']);

        return count($this->findAll($config));

    }//end count()


    /**
     * Finds objects by their relations.
     *
     * @param string $search       The URI or UUID to search for in relations.
     * @param bool   $partialMatch Whether to search for partial matches (default: true).
     *
     * @return array An array of ObjectEntities that have the specified URI/UUID in their relations.
     */
    public function findByRelations(string $search, bool $partialMatch=true): array
    {
        // Use the findByRelation method from the ObjectEntityMapper to find objects by their relations.
        return $this->objectEntityMapper->findByRelation($search, $partialMatch);

    }//end findByRelations()


    /**
     * Get logs for an object.
     *
     * @param string $uuid    The UUID of the object
     * @param array  $filters Optional filters to apply
     *
     * @return array Array of log entries
     */
    public function getLogs(string $uuid, array $filters=[]): array
    {
        $object = $this->objectEntityMapper->find($uuid);
        $logs   = $this->getHandler->findLogs($object);

        return $logs;

    }//end getLogs()


    /**
     * Saves an object from an array.
     *
     * @param array                    $object   The object data to save.
     * @param array|null               $extend   Properties to extend the object with.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     * @param string|null              $uuid     The UUID of the object to update (if updating).
     *
     * @return ObjectEntity The saved object.
     *
     * @throws Exception If there is an error during save.
     */
    public function saveObject(
        array $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        ?string $uuid=null
    ): ObjectEntity {
        // Check if a register is provided and set the current register context
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Validate the object against the current schema
        $result = $this->validateHandler->validateObject($object, $this->currentSchema);
        if ($result->isValid() === false && $this->currentSchema->getHardValidation() === true) {
            throw new ValidationException($result->error()->message());
        }

        // Save the object using the current register and schema
        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object,
            $uuid
        );

        // Determine if register and schema should be passed to renderEntity
        $registers = isset($config['filters']['register']) ? [$this->currentRegister->getId() => $this->currentRegister] : null;
        $schemas = isset($config['filters']['schema']) ? [$this->currentSchema->getId() => $this->currentSchema] : null;

        // Render and return the saved object
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
            extend: $extend,
            registers: $registers,
            schemas: $schemas
        );

    }//end saveObject()


    /**
     * Delete an object.
     *
     * @param string $uuid The UUID of the object to delete
     *
     * @return bool Whether the deletion was successful
     */
    public function deleteObject(string $uuid): bool
    {
        return $this->deleteHandler->deleteObject(
            $this->currentRegister,
            $this->currentSchema,
            $uuid
        );

    }//end deleteObject()

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

		// Convert to arrays and extend schemas
		$registers = array_map(function($register) {
			$registerArray = is_array($register) ? $register : $register->jsonSerialize();

			// Replace schema IDs with actual schema objects if schemas property exists
			if (isset($registerArray['schemas']) && is_array($registerArray['schemas'])) {
				$registerArray['schemas'] = array_map(
					function($schemaId) {
						try {
							return $this->schemaMapper->find($schemaId)->jsonSerialize();
						} catch (Exception $e) {
							// If schema can't be found, return the ID
							return $schemaId;
						}
					},
					$registerArray['schemas']
				);
			}

			return $registerArray;
		}, $registers);

		return $registers;
	}


}//end class
