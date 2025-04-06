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
use OCA\OpenRegister\Service\Response\SingleObjectResponse;
use OCA\OpenRegister\Service\Response\MultipleObjectResponse;
use OCA\OpenRegister\Service\Response\ObjectResponse;

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
    }

    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return self
     */
    public function setRegister(Register|string|int $register): self
    {
        if (is_string($register) || is_int($register)) {
            // Look up the register by ID or UUID
            $register = $this->registerMapper->find($register);
        }

        $this->currentRegister = $register;
        return $this;
    }

    /**
     * Set the current schema context.
     *
     * @param Schema|string|int $schema The schema object or its ID/UUID
     *
     * @return self
     */
    public function setSchema(Schema|string|int $schema): self
    {
        if (is_string($schema) || is_int($schema)) {
            // Look up the schema by ID or UUID
            $schema = $this->schemaMapper->find($schema);
        }

        $this->currentSchema = $schema;
        return $this;
    }

    /**
     * Set the current object context.
     *
     * @param ObjectEntity|string|int $object The object entity or its ID/UUID
     *
     * @return self
     */
    public function setObject(ObjectEntity|string|int $object): self
    {
        if (is_string($object) || is_int($object)) {
            // Look up the object by ID or UUID
            $object = $this->getHandler->getObjectByIdOrUuid($object);
        }

        $this->currentObject = $object;
        return $this;
    }

    /**
     * Finds an object by ID or UUID and renders it.
     *
     * @param int|string      $id       The object ID or UUID.
     * @param array|null      $extend   Properties to extend the object with.
     * @param bool            $files    Whether to include file information.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return ObjectEntity|null The rendered object or null.
     *
     * @throws Exception If the object is not found.
     */
    public function find(int | string $id, ?array $extend=[], bool $files=false, Register|string|int|null $register=null, Schema|string|int|null $schema=null): ?ObjectEntity
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
        return $this->renderHandler->renderEntity($object, $extend);
    }

    /**
     * Creates a new object from an array.
     *
     * @param array                  $object   The object data to create.
     * @param array|null             $extend   Properties to extend the object with.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     *
     * @return array The created object.
     *
     * @throws Exception If there is an error during creation.
     */
    public function createFromArray(
        array $object,
        ?array $extend = [],
        Register|string|int|null $register = null,
        Schema|string|int|null $schema = null
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
        return $this->renderHandler->renderEntity($savedObject, $extend);
    }

    /**
     * Updates an object from an array.
     *
     * @param string                  $id            The ID of the object to update.
     * @param array                   $object        The updated object data.
     * @param bool                    $updateVersion Whether to update the version.
     * @param bool                    $patch         Whether this is a patch update.
     * @param array|null              $extend        Properties to extend the object with.
     * @param Register|string|int|null $register     The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema       The schema object or its ID/UUID.
     *
     * @return array The updated object.
     *
     * @throws Exception If there is an error during update.
     */
    public function updateFromArray(
        string $id,
        array $object,
        bool $updateVersion,
        bool $patch = false,
        ?array $extend = [],
        Register|string|int|null $register = null,
        Schema|string|int|null $schema = null
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
        return $this->renderHandler->renderEntity($savedObject, $extend);
    }

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
    }

    /**
     * Finds all objects matching the given criteria.
     *
     * @param array $config Configuration array containing search parameters:
     *                      - limit: (int|null) Maximum number of objects to return
     *                      - offset: (int|null) Number of objects to skip
     *                      - filters: (array) Filter criteria
     *                      - sort: (array) Sort criteria
     *                      - search: (string|null) Search term
     *                      - extend: (array|null) Properties to extend the objects with
     *                      - files: (bool) Whether to include file information
     *                      - uses: (string|null) Filter by object usage
     *                      - register: (Register|string|int|null) Register object or UUID/ID
     *                      - schema: (Schema|string|int|null) Schema object or UUID/ID
     *                      - fields: (array|null) Specific fields to include
     *                      - unset: (array|null) Specific fields to unset
     *                      - ids: (array|null) Specific IDs to filter objects
     *
     * @return array The found objects
     */
    public function findAll(array $config): array
    {
        // Set the current register context if a register is provided
        if (isset($config['register'])) {
            $this->setRegister($config['register']);
        }

        // Set the current schema context if a schema is provided
        if (isset($config['schema'])) {
            $this->setSchema($config['schema']);
        }

        // Delegate the findAll operation to the handler
        $objects = $this->getHandler->findAll(
            $config['limit'] ?? null,
            $config['offset'] ?? null,
            $config['filters'] ?? [],
            $config['sort'] ?? [],
            $config['search'] ?? null,
            $config['extend'] ?? [],
            $config['files'] ?? false,
            $config['uses'] ?? null
        );

        // Render each object through the object service
        foreach ($objects as $key => $object) {
            $objects[$key] = $this->renderHandler->renderEntity(
                entity: $object->jsonSerialize(),
                extend: $config['extend'] ?? [],
                depth: 0,
                filter: $config['unset'] ?? null,
                fields: $config['fields'] ?? null
            );
        }

        return $objects;
    }

    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array       $filters  Filter criteria.
     * @param string|null $search   Search term.
     *
     * @return int The number of matching objects.
     */
    public function count(
        array $filters = [],
        ?string $search = null,
    ): int {
        // Add this point in time we should always have a register and schema.
        $filters['register_id'] = $this->currentRegister->getId();
        $filters['schema_id'] = $this->currentSchema->getId();

        return $this->getHandler->count($filters, $search);
    }

    /**
     * Finds objects by their relations.
     *
     * @param string $search       The URI or UUID to search for in relations.
     * @param bool   $partialMatch Whether to search for partial matches (default: true).
     *
     * @return array An array of ObjectEntities that have the specified URI/UUID in their relations.
     */
    public function findByRelations(string $search, bool $partialMatch = true): array
    {
        // Use the findByRelation method from the ObjectEntityMapper to find objects by their relations.
        return $this->objectEntityMapper->findByRelation($search, $partialMatch); 
    }

    /**
     * Get logs for an object.
     *
     * @param string $uuid The UUID of the object
     *
     * @return ObjectResponse
     */
    public function getLogs(string $uuid): ObjectResponse
    {
        $object = $this->objectEntityMapper->find($uuid);
        $logs = $this->getHandler->findLogs($object);
        return new ObjectResponse($logs);
    }

    /**
     * Save an object.
     *
     * @param array $data The object data to save
     *
     * @return SingleObjectResponse
     */
    public function saveObject(array $data): SingleObjectResponse
    {
        $object = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $data
        );
        return new SingleObjectResponse($object, $this->getHandler);
    }

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
    }
}
