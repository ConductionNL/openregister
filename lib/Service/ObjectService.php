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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
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
        private readonly ValidateObject $validateHandler
    ) {
    }

    /**
     * Set the current register context.
     *
     * @param Register $register The register to use
     *
     * @return self
     */
    public function setRegister(Register $register): self
    {
        $this->currentRegister = $register;
        return $this;
    }

    /**
     * Set the current schema context.
     *
     * @param Schema $schema The schema to use
     *
     * @return self
     */
    public function setSchema(Schema $schema): self
    {
        $this->currentSchema = $schema;
        return $this;
    }

    /**
     * Finds an object by ID or UUID.
     *
     * @param int|string $id     The object ID or UUID.
     * @param array|null $extend Properties to extend the object with.
     * @param bool       $files  Whether to include file information.
     *
     * @return ObjectEntity|null The found object or null.
     *
     * @throws Exception If the object is not found.
     */
    public function find(int | string $id, ?array $extend=[], bool $files=false): ?ObjectEntity
    {
        return $this->getHandler->getObject(
            $this->currentRegister,
            $this->currentSchema,
            $id,
            $extend,
            $files
        );
    }

    /**
     * Finds an object by UUID.
     *
     * @param string $uuid The UUID of the object to find.
     *
     * @return ObjectEntity|null The found object or null.
     */
    public function findByUuid(string $uuid): ?ObjectEntity
    {
        return $this->getHandler->findByUuid($uuid);
    }

    /**
     * Creates a new object from an array.
     *
     * @param array      $object The object data to create.
     * @param array|null $extend Properties to extend the object with.
     *
     * @return array The created object.
     *
     * @throws Exception If there is an error during creation.
     */
    public function createFromArray(array $object, ?array $extend=[]): array
    {
        $result = $this->validateHandler->validateObject($object, $this->currentSchema);
        if ($result->isValid() === false) {
            throw new ValidationException($result->error()->message());
        }

        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object
        );

        return $this->renderHandler->renderEntity($savedObject, $extend);
    }

    /**
     * Updates an object from an array.
     *
     * @param string     $id            The ID of the object to update.
     * @param array      $object        The updated object data.
     * @param bool       $updateVersion Whether to update the version.
     * @param bool       $patch         Whether this is a patch update.
     * @param array|null $extend        Properties to extend the object with.
     *
     * @return array The updated object.
     *
     * @throws Exception If there is an error during update.
     */
    public function updateFromArray(string $id, array $object, bool $updateVersion, bool $patch=false, ?array $extend=[]): array
    {
        $existingObject = $this->getHandler->findByUuid($id);
        if ($existingObject === null) {
            throw new DoesNotExistException('Object not found');
        }

        if ($patch === true) {
            $object = array_merge($existingObject->getObject(), $object);
        }

        $result = $this->validateHandler->validateObject($object, $this->currentSchema);
        if ($result->isValid() === false) {
            throw new ValidationException($result->error()->message());
        }

        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object
        );

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
     * @param int|null    $limit   Maximum number of objects to return.
     * @param int|null    $offset  Number of objects to skip.
     * @param array       $filters Filter criteria.
     * @param array       $sort    Sort criteria.
     * @param string|null $search  Search term.
     * @param array|null  $extend  Properties to extend the objects with.
     * @param bool        $files   Whether to include file information.
     * @param string|null $uses    Filter by object usage.
     *
     * @return array The found objects.
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        array $filters=[],
        array $sort=[],
        ?string $search=null,
        ?array $extend=[],
        bool $files=false,
        ?string $uses=null,
    ): array {
        return $this->getHandler->findAll(
            $limit,
            $offset,
            $filters,
            $sort,
            $search,
            $extend,
            $files,
            $uses
        );
    }

    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array       $filters Filter criteria.
     * @param string|null $search  Search term.
     *
     * @return int The number of matching objects.
     */
    public function count(array $filters=[], ?string $search=null): int
    {
        return $this->getHandler->count($filters, $search);
    }

    /**
     * Finds multiple objects by their UUIDs.
     *
     * @param array $uuids List of UUIDs to find.
     * @param array|null $extend Properties to extend.
     * @param bool $includeFiles Whether to include file information.
     * @return array List of found objects.
     */
    public function findMultiple(array $uuids, ?array $extend = null, bool $includeFiles = false): array
    {
        return $this->getHandler->findMultiple($uuids, $extend, $includeFiles);
    }

    /**
     * Get a single object by UUID.
     *
     * @param string $uuid The UUID of the object
     *
     * @return SingleObjectResponse
     */
    public function getObject(string $uuid): SingleObjectResponse
    {
        $object = $this->getHandler->getObject(
            $this->currentRegister,
            $this->currentSchema,
            $uuid
        );
        return new SingleObjectResponse($object, $this->getHandler);
    }

    /**
     * Get multiple objects.
     *
     * @param array $criteria The search criteria
     *
     * @return MultipleObjectResponse
     */
    public function getObjects(array $criteria = []): MultipleObjectResponse
    {
        $objects = $this->getHandler->findAll(
            filters: $criteria
        );
        return new MultipleObjectResponse($objects, $this->getHandler);
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
        $object = $this->getHandler->findByUuid($uuid);
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
