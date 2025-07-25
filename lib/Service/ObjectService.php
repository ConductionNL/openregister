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
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FacetableAnalyzer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\RenderObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Async;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * Service class for managing objects in the OpenRegister application.
 *
 * This service acts as a facade for the various object handlers,
 * coordinating operations between them and maintaining state.
 */
class ObjectService
{

    /**
     * The current register context.
     *
     * @var Register|null
     */
    private ?Register $currentRegister = null;

    /**
     * The current schema context.
     *
     * @var Schema|null
     */
    private ?Schema $currentSchema = null;

    /**
     * The current object context.
     *
     * @var ObjectEntity|null
     */
    private ?ObjectEntity $currentObject = null;


    /**
     * Constructor for ObjectService.
     *
     * @param DeleteObject       $deleteHandler      Handler for object deletion.
     * @param GetObject          $getHandler         Handler for object retrieval.
     * @param RenderObject       $renderHandler      Handler for object rendering.
     * @param SaveObject         $saveHandler        Handler for object saving.
     * @param ValidateObject     $validateHandler    Handler for object validation.
     * @param PublishObject      $publishHandler     Handler for object publication.
     * @param DepublishObject    $depublishHandler   Handler for object depublication.
     * @param RegisterMapper     $registerMapper     Mapper for register operations.
     * @param SchemaMapper       $schemaMapper       Mapper for schema operations.
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entity operations.
     * @param FileService        $fileService        Service for file operations.
     * @param IUserSession       $userSession        User session for getting current user.
     * @param SearchTrailService $searchTrailService Service for search trail operations.
     */
    public function __construct(
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObject $saveHandler,
        private readonly ValidateObject $validateHandler,
        private readonly PublishObject $publishHandler,
        private readonly DepublishObject $depublishHandler,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly SearchTrailService $searchTrailService
    ) {

    }//end __construct()

    /**
     * Ensure folder exists for an ObjectEntity.
     *
     * This method checks if the object has a valid folder ID and creates one if needed.
     * It handles legacy cases where the folder property might be null, empty, or a string path.
     *
     * @param ObjectEntity $entity The object entity to ensure folder for
     *
     * @return void
     *
     * @psalm-return void
     * @phpstan-return void
     */
    public function ensureObjectFolderExists(ObjectEntity $entity): void
    {
        $folderProperty = $entity->getFolder();
        
        // Check if folder needs to be created (null, empty string, or legacy string path)
        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty)) {
            try {
                // Create folder and get the folder node
                $folderNode = $this->fileService->createEntityFolder($entity);
                
                if ($folderNode !== null) {
                    // Update the entity with the folder ID
                    $entity->setFolder($folderNode->getId());
                    
                    // Save the entity with the new folder ID
                    $this->objectEntityMapper->update($entity);
                }
            } catch (\Exception $e) {
                // Log the error but don't fail the object creation/update
                // The object can still function without a folder
                error_log("Failed to create folder for object {$entity->getId()}: " . $e->getMessage());
            }
        }
    }//end ensureObjectFolderExists()



    /**
     * Get ValidateHandler
     *
     * @return ValidateObject
     */
    public function getValidateHandler(): ValidateObject
    {
        return $this->validateHandler;
    }

    /**
     * Set the current register context.
     *
     * @param Register|string|int $register The register object or its ID/UUID
     *
     * @return self
     */
    public function setRegister(Register | string | int $register): self
    {
        if (is_string($register) === true || is_int($register) === true) {
            // Look up the register by ID or UUID.
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
        if (is_string($schema) === true || is_int($schema) === true) {
            // Look up the schema by ID or UUID.
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
        if (is_string($object) === true || is_int($object) === true) {
            // Look up the object by ID or UUID.
            $object = $this->objectEntityMapper->find($object);
        }

        $this->currentObject = $object;
        return $this;

    }//end setObject()


    /**
     * Get the current object context.
     *
     * @return ObjectEntity|null The current object entity or null if not set.
     */
    public function getObject(): ?ObjectEntity
    {
        // Return the current object context.
        return $this->currentObject;

    }//end getObject()


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
    public function find(
        int | string $id,
        ?array $extend=[],
        bool $files=false,
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null
    ): ?ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Retrieve the object using the current register, schema, ID, extend properties, and file information.
        $object = $this->getHandler->find(
            id: $id,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            extend: $extend,
            files: $files
        );

        // If the object is not found, return null.
        if ($object === null) {
            return null;
        }

        // Render the object before returning.
        $registers = null;
        if ($this->currentRegister !== null) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        }

        $schemas = null;
        if ($this->currentSchema !== null) {
            $schemas = [$this->currentSchema->getId() => $this->currentSchema];
        }

        return $this->renderHandler->renderEntity(
            entity: $object,
            extend: $extend,
            registers: $registers,
            schemas: $schemas
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
    ): ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Skip validation here - let saveObject handle the proper order of pre-validation cascading then validation

        // Create a temporary object entity to generate UUID and create folder
        $tempObject = new ObjectEntity();
        $tempObject->setRegister($this->currentRegister->getId());
        $tempObject->setSchema($this->currentSchema->getId());
        $tempObject->setUuid(Uuid::v4()->toRfc4122());
        
        // Create folder before saving to avoid double update
        $folderId = null;
        try {
            $folderId = $this->fileService->createObjectFolderWithoutUpdate($tempObject);
        } catch (\Exception $e) {
            // Log error but continue - object can function without folder
            error_log("Failed to create folder for new object: " . $e->getMessage());
        }

        // Save the object using the current register and schema with folder ID
        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object,
            $tempObject->getUuid(),
            $folderId
        );

        // Render and return the saved object.
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
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
    ): ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Retrieve the existing object by its UUID.
        $existingObject = $this->getHandler->find(id: $id);
        if ($existingObject === null) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found');
        }

        // If patch is true, merge the existing object with the new data.
        if ($patch === true) {
            $object = array_merge($existingObject->getObject(), $object);
        }

        // Skip validation here - let saveObject handle the proper order of pre-validation cascading then validation

        // Create folder before saving if object doesn't have one
        $folderId = null;
        if ($existingObject->getFolder() === null || $existingObject->getFolder() === '' || is_string($existingObject->getFolder())) {
            try {
                $folderId = $this->fileService->createObjectFolderWithoutUpdate($existingObject);
            } catch (\Exception $e) {
                // Log error but continue - object can function without folder
                error_log("Failed to create folder for updated object: " . $e->getMessage());
            }
        }

        // Save the object using the current register and schema.
        $savedObject = $this->saveHandler->saveObject(
            register: $this->currentRegister,
            schema: $this->currentSchema,
            data: $object,
            uuid: $id,
            folderId: $folderId
        );

        // Render and return the saved object.
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
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

        // Convert extend to an array if it's a string.
        if (isset($config['extend']) === true && is_string($config['extend']) === true) {
            $config['extend'] = explode(',', $config['extend']);
        }

        // Set the current register context if a register is provided and it's not an array.
        if (isset($config['filters']['register']) === true  && is_array($config['filters']['register']) === false) {
            $this->setRegister($config['filters']['register']);
        }

        // Set the current schema context if a schema is provided and it's not an array.
        if (isset($config['filters']['schema']) === true  && is_array($config['filters']['schema']) === false) {
            $this->setSchema($config['filters']['schema']);
        }

        // Delegate the findAll operation to the handler.
        $objects = $this->getHandler->findAll(
            limit: $config['limit'] ?? null,
            offset: $config['offset'] ?? null,
            filters: $config['filters'] ?? [],
            sort: $config['sort'] ?? [],
            search: $config['search'] ?? null,
            files: $config['files'] ?? false,
            uses: $config['uses'] ?? null,
            ids: $config['ids'] ?? null,
            published: $config['published'] ?? false
        );

        // Determine if register and schema should be passed to renderEntity only if currentSchema and currentRegister aren't null.
        $registers = null;
        if ($this->currentRegister !== null && isset($config['filters']['register']) === true) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        }

        $schemas = null;
        if ($this->currentSchema !== null && isset($config['filters']['schema']) === true) {
            $schemas = [$this->currentSchema->getId() => $this->currentSchema];
        }

        // Check if '@self.schema' or '@self.register' is in extend but not in filters.
        if (isset($config['extend']) === true && in_array('@self.schema', (array) $config['extend'], true) === true && $schemas === null) {
            $schemaIds = array_unique(array_filter(array_map(fn($object) => $object->getSchema() ?? null, $objects)));
            $schemas   = $this->schemaMapper->findMultiple(ids: $schemaIds);
            $schemas   = array_combine(array_map(fn($schema) => $schema->getId(), $schemas), $schemas);
        }

        if (isset($config['extend']) === true && in_array('@self.register', (array) $config['extend'], true) === true && $registers === null) {
            $registerIds = array_unique(array_filter(array_map(fn($object) => $object->getRegister() ?? null, $objects)));
            $registers   = $this->registerMapper->findMultiple(ids:  $registerIds);
            $registers   = array_combine(array_map(fn($register) => $register->getId(), $registers), $registers);
        }

        // Render each object through the object service.
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
     * @return int The number of matching objects.
     * @throws \Exception If register or schema is not set
     */
    public function count(
        array $config=[]
    ): int {
        // Add register and schema IDs to filters// Ensure we have both register and schema set.
        if ($this->currentRegister !== null && empty($config['filers']['register']) === true) {
            $filters['register'] = $this->currentRegister->getId();
        }

        if ($this->currentSchema !== null && empty($config['filers']['schema']) === true) {
            $config['filers']['schema'] = $this->currentSchema->getId();
        }

        // Remove limit from config as it's not needed for count.
        unset($config['limit']);

        return $this->objectEntityMapper->countAll(
            filters: $config['filters'] ?? [],
            search: $config['search'] ?? null,
            ids: $config['ids'] ?? null,
            uses: $config['uses'] ?? null,
            published: $config['published'] ?? false
        );

    }//end count()


    /**
     * Find objects by their relations.
     *
     * @param string $search       The URI or UUID to search for in relations
     * @param bool   $partialMatch Whether to search for partial matches (default: true)
     *
     * @return array An array of ObjectEntities that have the specified URI/UUID in their relations
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
        // Get logs for the specified object.
        $object = $this->objectEntityMapper->find($uuid);
        $logs   = $this->getHandler->findLogs($object, filters: $filters);

        return $logs;

    }//end getLogs()


    /**
     * Saves an object from an array or ObjectEntity.
     *
     * @param array|ObjectEntity       $object   The object data to save or ObjectEntity instance.
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
        array | ObjectEntity $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        ?string $uuid=null
    ): ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Debug logging can be added here if needed
        // echo "=== SAVEOBJECT START ===\n";
        
        // Handle ObjectEntity input - extract UUID and convert to array
        if ($object instanceof ObjectEntity) {
            // If no UUID was passed, use the UUID from the existing object
            if ($uuid === null) {
                $uuid = $object->getUuid();
            }
            $object = $object->getObject(); // Get the object data array
        }

        // Store the parent object's register and schema context before cascading
        // This prevents nested object creation from corrupting the main object's context
        $parentRegister = $this->currentRegister;
        $parentSchema = $this->currentSchema;
        
        // Pre-validation cascading: Handle inversedBy properties BEFORE validation
        // This creates related objects and replaces them with UUIDs so validation sees UUIDs, not objects
        [$object, $uuid] = $this->handlePreValidationCascading($object, $parentSchema, $uuid);
        
        // Restore the parent object's register and schema context after cascading
        $this->currentRegister = $parentRegister;
        $this->currentSchema = $parentSchema;

        // Validate the object against the current schema only if hard validation is enabled.
        if ($this->currentSchema->getHardValidation() === true) {
            $result = $this->validateHandler->validateObject($object, $this->currentSchema);
            if ($result->isValid() === false) {
                $meaningfulMessage = $this->validateHandler->generateErrorMessage($result);
                throw new ValidationException($meaningfulMessage, errors: $result->error());
            }
            // error_log('[ObjectService] Object validation passed'); // Removed info log
        } else {
            // error_log('[ObjectService] Hard validation disabled, skipping validation'); // Removed info log
        }

        // Handle folder creation for existing objects or new objects with UUIDs
        $folderId = null;
        if ($uuid !== null) {
            // For existing objects or objects with specific UUIDs, check if folder needs to be created
            try {
                $existingObject = $this->objectEntityMapper->find($uuid);
                if ($existingObject->getFolder() === null || $existingObject->getFolder() === '' || is_string($existingObject->getFolder())) {
                    try {
                        $folderId = $this->fileService->createObjectFolderWithoutUpdate($existingObject);
                    } catch (\Exception $e) {
                        // Log error but continue - object can function without folder
                        error_log("Failed to create folder for existing object: " . $e->getMessage());
                    }
                }
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object not found, will create new one with the specified UUID
                // Let SaveObject handle the creation with the provided UUID
            } catch (\Exception $e) {
                // Other errors - let SaveObject handle the creation
                error_log("Error checking for existing object: " . $e->getMessage());
            }
        }
        // For new objects without UUID, let SaveObject generate the UUID and handle folder creation

        // Save the object using the current register and schema.
        // Let SaveObject handle the UUID logic completely
        $savedObject = $this->saveHandler->saveObject(
            $this->currentRegister,
            $this->currentSchema,
            $object,
            $uuid,
            $folderId
        );

        // Determine if register and schema should be passed to renderEntity.
        if (isset($config['filters']['register']) === true) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        } else {
            $registers = null;
        }

        if (isset($config['filters']['schema']) === true) {
            $schemas = [$this->currentSchema->getId() => $this->currentSchema];
        } else {
            $schemas = null;
        }

        // Render and return the saved object.
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
        // Get all registers.
        $registers = $this->registerMapper->findAll();

        // Convert to arrays and extend schemas.
        $registers = array_map(
          function ($register) {
            if (is_array($register) === true) {
                $registerArray = $register;
            } else {
                $registerArray = $register->jsonSerialize();
            }

            // Replace schema IDs with actual schema objects if schemas property exists.
            if (isset($registerArray['schemas']) === true && is_array($registerArray['schemas']) === true) {
                $registerArray['schemas'] = array_map(
                    function ($schemaId) {
                        // Only expand if it's an int or string (ID/UUID/slug)
                        if (is_int($schemaId) || is_string($schemaId)) {
                            try {
                                return $this->schemaMapper->find($schemaId)->jsonSerialize();
                            } catch (Exception $e) {
                                return $schemaId;
                            }
                        }
                        // If it's already an array/object, return as-is
                        return $schemaId;
                    },
                    $registerArray['schemas']
                );
            }

            return $registerArray;
          },
          $registers
          );

        return $registers;

    }//end getRegisters()


    /**
     * Find all objects conforming to the request parameters, surrounded with pagination data.
     *
     * @param array $requestParams The request parameters to search with.
     *
     * @return array The result including pagination data.
     */
    public function findAllPaginated(array $requestParams): array
    {
        // Extract specific parameters.
        $limit  = $requestParams['limit'] ?? $requestParams['_limit'] ?? null;
        $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
        $order  = $requestParams['order'] ?? $requestParams['_order'] ?? [];
        $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $page   = $requestParams['page'] ?? $requestParams['_page'] ?? null;
        $search = $requestParams['_search'] ?? null;
        $fields = $requestParams['_fields'] ?? null;
        $published = $requestParams['_published'] ?? false;
        $facetable = $requestParams['_facetable'] ?? false;

        if ($page !== null && isset($limit) === true) {
            $page   = (int) $page;
            $offset = $limit * ($page - 1);
        }

        // Ensure order and extend are arrays.
        if (is_string($order) === true) {
            $order = array_map('trim', explode(',', $order));
        }

        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Remove unnecessary parameters from filters.
        $filters = $requestParams;
        unset($filters['_route']);
        // TODO: Investigate why this is here and if it's needed.
        unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search'], $filters['_facetable']);
        unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

        if (isset($filters['register']) === false) {
            $filters['register'] = $this->getRegister();
        }

        if (isset($filters['schema']) === false) {
            $filters['schema'] = $this->getSchema();
        }

        $objects = $this->findAll(
                [
                    "limit"   => $limit,
                    "offset"  => $offset,
                    "filters" => $filters,
                    "sort"    => $order,
                    "search"  => $search,
                    "extend"  => $extend,
                    'fields'  => $fields,
                    'published' => $published,
                ]
                );

        $total = $this->count(
                [
                    "filters" => $filters,
                ]
                );

        if ($limit !== null) {
            $pages = ceil($total / $limit);
        } else {
            $pages = 1;
        }

        // Use new faceting system with basic configuration
        $facetQuery = [
            '@self' => array_intersect_key($filters, array_flip(['register', 'schema'])),
            '_search' => $search,
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ]
            ]
        ];
        
        // Add object field filters to facet query
        $objectFilters = array_diff_key($filters, array_flip(['register', 'schema', 'extend', 'limit', 'offset', 'order', 'page']));
        foreach ($objectFilters as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $facetQuery[$key] = $value;
                $facetQuery['_facets'][$key] = ['type' => 'terms'];
            }
        }
        
        $facets = $this->getFacetsForObjects($facetQuery);

        // Build the result array with pagination and faceting data
        $result = [
            'results' => $objects,
            'facets'  => $facets,
            'total'   => $total,
            'page'    => $page ?? 1,
            'pages'   => $pages,
        ];

        // Add facetable field discovery if requested
        if ($facetable === true || $facetable === 'true') {
            $baseQuery = $facetQuery; // Use the same base query as for facets
            $sampleSize = (int) ($requestParams['_sample_size'] ?? 100);
            
            $result['facetable'] = $this->getFacetableFields($baseQuery, $sampleSize);
        }

        return $result;

    }//end findAllPaginated()


    /**
     * Fetch the ObjectService as mapper, or the specific ObjectEntityMapper
     *
     * @param string|null $type     The type of object (only for backwards compatibility)
     * @param int|null    $register The register to get the ObjectService for
     * @param int|null    $schema   The schema to get the ObjectService for
     *
     * @return ObjectEntityMapper|ObjectService
     */
    public function getMapper(?string $type=null, ?int $register=null, ?int $schema=null): ObjectEntityMapper | ObjectService
    {
        if ($register !== null && $schema !== null) {
            $this->setRegister($register);
            $this->setSchema($schema);
            return $this;
        }

        return $this->objectEntityMapper;

    }//end getMapper()


    /**
     * Search objects using clean query structure
     *
     * This method provides a cleaner search interface that uses the new searchObjects
     * method from ObjectEntityMapper with proper query structure. It automatically
     * handles metadata filters, object field searches, and search options.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _limit: Maximum results to return
     *                     - _offset: Results to skip (pagination)
     *                     - _order: Sorting criteria
     *                     - _search: Full-text search term
     *                     - _includeDeleted: Include soft-deleted objects
     *                     - _published: Only published objects
     *                     - _ids: Array of IDs/UUIDs to filter by
     *                     - _count: Return count instead of objects (boolean)
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array<int, ObjectEntity>|int An array of ObjectEntity objects matching the criteria, or integer count if _count is true
     */
    public function searchObjects(array $query = []): array|int
    {
        // Use the new searchObjects method from ObjectEntityMapper
        $result = $this->objectEntityMapper->searchObjects($query);

        // If _count option was used, return the integer count directly
        if (isset($query['_count']) && $query['_count'] === true) {
            return $result;
        }

        // For regular search results, proceed with rendering
        $objects = $result;

        // Get unique register and schema IDs from the results for rendering context
        $registerIds = array_unique(array_filter(array_map(fn($object) => $object->getRegister() ?? null, $objects)));
        $schemaIds = array_unique(array_filter(array_map(fn($object) => $object->getSchema() ?? null, $objects)));

        // Load registers and schemas for rendering if needed
        $registers = null;
        $schemas = null;

        if (!empty($registerIds)) {
            $registerEntities = $this->registerMapper->findMultiple(ids: $registerIds);
            $registers = array_combine(array_map(fn($register) => $register->getId(), $registerEntities), $registerEntities);
        }

        if (!empty($schemaIds)) {
            $schemaEntities = $this->schemaMapper->findMultiple(ids: $schemaIds);
            $schemas = array_combine(array_map(fn($schema) => $schema->getId(), $schemaEntities), $schemaEntities);
        }

        // Extract extend configuration from query if present
        $extend = $query['_extend'] ?? [];
        if (is_string($extend)) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Extract fields configuration from query if present
        $fields = $query['_fields'] ?? null;
        if (is_string($fields)) {
            $fields = array_map('trim', explode(',', $fields));
        }

        // Extract filter configuration from query if present
        $filter = $query['_filter'] ?? $query['_unset'] ?? null;
        if (is_string($filter)) {
            $filter = array_map('trim', explode(',', $filter));
        }

        // Render each object through the render handler
        foreach ($objects as $key => $object) {
            $objects[$key] = $this->renderHandler->renderEntity(
                entity: $object,
                extend: $extend,
                filter: $filter,
                fields: $fields,
                registers: $registers,
                schemas: $schemas
            );
        }

        return $objects;

    }//end searchObjects()


    /**
     * Count objects using clean query structure
     *
     * This method provides an optimized count interface that mirrors the searchObjects 
     * functionality but returns only the count of matching objects. It uses the new
     * countSearchObjects method which is optimized for counting operations.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _includeDeleted: Include soft-deleted objects
     *                     - _published: Only published objects
     *                     - _search: Full-text search term
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return int The number of objects matching the criteria
     */
    public function countSearchObjects(array $query = []): int
    {
        // Use the new optimized countSearchObjects method from ObjectEntityMapper
        return $this->objectEntityMapper->countSearchObjects($query);

    }//end countSearchObjects()


    /**
     * Count objects using legacy configuration structure
     *
     * This method maintains backward compatibility with the existing count functionality.
     * For new code, prefer using countSearchObjects() with the clean query structure.
     *
     * @param array $config Configuration array containing:
     *                      - filters: Filter criteria
     *                      - search: Search term
     *                      - ids: Array of IDs or UUIDs to filter by
     *                      - uses: Filter by object usage
     *                      - published: Only published objects
     *
     * @phpstan-param array<string, mixed> $config
     *
     * @psalm-param array<string, mixed> $config
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return int The number of objects matching the criteria
     */
    public function countObjects(array $config = []): int
    {
        // Extract metadata filters from @self if present (for compatibility)
        $metadataFilters = $config['@self'] ?? [];
        $register = $metadataFilters['register'] ?? null;
        $schema = $metadataFilters['schema'] ?? null;

        // Extract options
        $includeDeleted = $config['_includeDeleted'] ?? false;
        $published = $config['_published'] ?? $config['published'] ?? false;
        $search = $config['_search'] ?? $config['search'] ?? null;
        $ids = $config['_ids'] ?? $config['ids'] ?? null;
        $uses = $config['_uses'] ?? $config['uses'] ?? null;

        // Clean the query: remove @self and all properties prefixed with _
        $cleanQuery = array_filter($config, function($key) {
            return $key !== '@self' && str_starts_with($key, '_') === false;
        }, ARRAY_FILTER_USE_KEY);

        // Remove system parameters
        unset($cleanQuery['published'], $cleanQuery['search'], $cleanQuery['ids'], $cleanQuery['uses']);

        // Add register and schema to filters if provided
        if ($register !== null) {
            $cleanQuery['register'] = $register;
        }
        if ($schema !== null) {
            $cleanQuery['schema'] = $schema;
        }

        // Use the existing countAll method for legacy compatibility
        return $this->objectEntityMapper->countAll(
            filters: $cleanQuery,
            search: $search,
            ids: $ids,
            uses: $uses,
            includeDeleted: $includeDeleted,
            register: null, // Already added to filters above
            schema: null,   // Already added to filters above
            published: $published
        );

    }//end countObjects()


    /**
     * Get facets for objects matching the given criteria
     *
     * This method provides comprehensive faceting capabilities for object data,
     * supporting both metadata facets (like register, schema, dates) and object
     * field facets (like status, category, priority). It uses the new facet
     * handlers for optimal performance and consistency.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _search: Full-text search term
     *                     - _facets: Facet configuration (required)
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array The facets for objects matching the criteria
     */
    public function getFacetsForObjects(array $query = []): array
    {
        // Always use the new comprehensive faceting system via ObjectEntityMapper
        $facets = $this->objectEntityMapper->getSimpleFacets($query);
        
        // Load register and schema context for enhanced metadata
        $this->loadRegistersAndSchemas($query);
        
        return ['facets' => $facets];

    }//end getFacetsForObjects()


    /**
     * Get facetable fields for discovery
     *
     * This method provides a comprehensive list of fields that can be used for faceting
     * by analyzing schema definitions instead of object data. This approach is more
     * efficient and provides consistent faceting based on schema property definitions.
     *
     * Fields are marked as facetable in schema properties by setting 'facetable': true.
     * This method will return configuration for both metadata fields (@self) and
     * object fields based on their schema definitions.
     *
     * @param array $baseQuery Base query filters to apply for context
     * @param int   $sampleSize Unused parameter, kept for backward compatibility
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @throws \Exception If facetable field discovery fails
     *
     * @return array Comprehensive facetable field information from schemas
     */
    public function getFacetableFields(array $baseQuery = [], int $sampleSize = 100): array
    {
        try {
            return $this->objectEntityMapper->getFacetableFields($baseQuery);
        } catch (\Exception $e) {
            throw new \Exception('Failed to get facetable fields from schemas: ' . $e->getMessage(), 0, $e);
        }

    }//end getFacetableFields()


    /**
     * Load registers and schemas for enhanced metadata context
     *
     * This method loads register and schema objects based on the query filters
     * to provide enhanced context for faceting and rendering.
     *
     * @param array $query The search query array
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @return void
     */
    private function loadRegistersAndSchemas(array $query): void
    {
        // Load register context if specified
        if (isset($query['@self']['register'])) {
            $registerValue = $query['@self']['register'];
            if (!is_array($registerValue) && $this->currentRegister === null) {
                try {
                    $this->setRegister($registerValue);
                } catch (\Exception $e) {
                    // Ignore errors in context loading
                }
            }
        }

        // Load schema context if specified
        if (isset($query['@self']['schema'])) {
            $schemaValue = $query['@self']['schema'];
            if (!is_array($schemaValue) && $this->currentSchema === null) {
                try {
                    $this->setSchema($schemaValue);
                } catch (\Exception $e) {
                    // Ignore errors in context loading
                }
            }
        }

    }//end loadRegistersAndSchemas()


    /**
     * Search objects with pagination and comprehensive faceting support
     *
     * This method provides a complete search interface with pagination, faceting,
     * and optional facetable field discovery. It supports all the features of the
     * searchObjects method while adding pagination and URL generation for navigation.
     *
     * **Performance Note**: For better performance with multiple operations (facets + facetable),
     * consider using `searchObjectsPaginatedAsync()` which runs operations concurrently.
     *
     * ### Supported Query Parameters
     *
     * **Pagination:**
     * - `_limit`: Maximum results per page (default: 20)
     * - `_offset`: Number of results to skip
     * - `_page`: Page number (alternative to offset)
     *
     * **Search and Filtering:**
     * - `@self`: Metadata filters (register, schema, uuid, etc.)
     * - Direct keys: Object field filters for JSON data
     * - `_search`: Full-text search term
     * - `_includeDeleted`: Include soft-deleted objects
     * - `_published`: Only published objects
     * - `_ids`: Array of IDs/UUIDs to filter by
     *
     * **Faceting:**
     * - `_facets`: Facet configuration for aggregations (~10ms performance impact)
     * - `_facetable`: Include facetable field discovery (~15ms performance impact)
     *
     * **Rendering:**
     * - `_extend`: Properties to extend
     * - `_fields`: Fields to include
     * - `_filter/_unset`: Fields to exclude
     *
     * ### Facet Types
     * 
     * - **terms**: Categorical data with enumerated values and counts
     * - **date_histogram**: Time-based data with configurable intervals (day, week, month, year)
     * - **range**: Numeric data with custom range buckets
     *
     * ### Disjunctive Faceting
     * 
     * Facets use disjunctive logic, meaning each facet shows counts as if its own
     * filter were not applied. This prevents facet options from disappearing when
     * selected, providing a better user experience.
     *
     * ### Performance Impact
     * 
     * - Regular queries: Baseline response time
     * - With `_facets`: Adds ~10ms to response time
     * - With `_facetable=true`: Adds ~15ms to response time
     * - Combined: Adds ~25ms total
     * 
     * Use faceting and discovery strategically for optimal performance.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _limit: Maximum results to return
     *                     - _offset: Results to skip (pagination)
     *                     - _page: Page number (alternative to offset)
     *                     - _order: Sorting criteria
     *                     - _search: Full-text search term
     *                     - _includeDeleted: Include soft-deleted objects
     *                     - _published: Only published objects
     *                     - _ids: Array of IDs/UUIDs to filter by
     *                     - _facets: Facet configuration for aggregations
     *                     - _facetable: Include facetable field discovery (true/false)
     *                     - _extend: Properties to extend
     *                     - _fields: Fields to include
     *                     - _filter/_unset: Fields to exclude
     *                     - _queries: Specific fields for legacy facets
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array<string, mixed> Array containing:
     *                              - results: Array of rendered ObjectEntity objects
     *                              - total: Total number of matching objects
     *                              - page: Current page number
     *                              - pages: Total number of pages
     *                              - limit: Items per page
     *                              - offset: Current offset
     *                              - facets: Comprehensive facet data with counts and metadata
     *                              - facetable: Facetable field discovery (if _facetable=true)
     *                              - next: URL for next page (if available)
     *                              - prev: URL for previous page (if available)
     */
    public function searchObjectsPaginated(array $query = []): array
    {
        // Start timing execution
        $startTime = microtime(true);
        
        // Extract pagination parameters
        $limit = $query['_limit'] ?? 20;
        $offset = $query['_offset'] ?? null;
        $page = $query['_page'] ?? null;
        $facetable = $query['_facetable'] ?? false;

        // Calculate offset from page if provided
        if ($page !== null && $offset === null) {
            $page = max(1, (int) $page); // Ensure page is at least 1
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided
        if ($page === null && $offset !== null) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values
        $page = $page ?? 1;
        $offset = $offset ?? 0;
        $limit = max(1, (int) $limit); // Ensure limit is at least 1

        // Update query with calculated pagination values
        $paginatedQuery = array_merge($query, [
            '_limit' => $limit,
            '_offset' => $offset,
        ]);

        // Remove page parameter from the query as we use offset internally
        unset($paginatedQuery['_page']);

        // Get the search results
        $results = $this->searchObjects($paginatedQuery);

        // Get total count (without pagination)
        $countQuery = $query; // Use original query without pagination
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);
        $total = $this->countSearchObjects($countQuery);

        // Get facets (without pagination)
        $facets = $this->getFacetsForObjects($countQuery);

        // Calculate total pages
        $pages = max(1, ceil($total / $limit));
        
        // Initialize the results array with pagination information
        $paginatedResults = [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
            'offset' => $offset,
            'facets' => $facets,
        ];

        // Add facetable field discovery if requested
        if ($facetable === true || $facetable === 'true') {
            $baseQuery = $countQuery; // Use the same base query as for facets
            $sampleSize = (int) ($query['_sample_size'] ?? 100);
            
            $paginatedResults['facetable'] = $this->getFacetableFields($baseQuery, $sampleSize);
        }

        // Add next/prev page URLs if applicable
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages
        if ($page < $pages) {
            $nextPage = ($page + 1);
            $nextUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&').'page='.$nextPage;
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page
        if ($page > 1) {
            $prevPage = ($page - 1);
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&').'page='.$prevPage;
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        // Calculate execution time in milliseconds
        $executionTime = (microtime(true) - $startTime) * 1000;

        // Log the search trail with actual execution time
        $this->logSearchTrail($query, count($results), $total, $executionTime, 'sync');

        return $paginatedResults;

    }//end searchObjectsPaginated()


    /**
     * Search objects with pagination and comprehensive faceting support (Asynchronous)
     *
     * This method provides the same functionality as searchObjectsPaginated but runs
     * the database operations asynchronously using ReactPHP promises. This significantly
     * improves performance by executing search, count, facets, and facetable discovery
     * operations concurrently instead of sequentially.
     *
     * ### Performance Benefits
     * 
     * Instead of sequential execution (~50ms total):
     * 1. Facetable discovery: ~15ms
     * 2. Search results: ~10ms  
     * 3. Facets: ~10ms
     * 4. Count: ~5ms
     * 
     * Operations run concurrently, reducing total time to ~15ms (longest operation).
     *
     * ### Operation Order
     * 
     * Operations are queued in order of expected duration (longest first):
     * 1. **Facetable discovery** (~15ms) - Field analysis and discovery
     * 2. **Search results** (~10ms) - Main object search with pagination
     * 3. **Facets** (~10ms) - Aggregation calculations
     * 4. **Count** (~5ms) - Total count for pagination
     *
     * @param array $query The search query array (same structure as searchObjectsPaginated)
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return PromiseInterface<array<string, mixed>> Promise that resolves to the same structure as searchObjectsPaginated
     */
    public function searchObjectsPaginatedAsync(array $query = []): PromiseInterface
    {
        // Start timing execution
        $startTime = microtime(true);
        
        // Extract pagination parameters (same as synchronous version)
        $limit = $query['_limit'] ?? 20;
        $offset = $query['_offset'] ?? null;
        $page = $query['_page'] ?? null;
        $facetable = $query['_facetable'] ?? false;

        // Calculate offset from page if provided
        if ($page !== null && $offset === null) {
            $page = max(1, (int) $page);
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided
        if ($page === null && $offset !== null) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values
        $page = $page ?? 1;
        $offset = $offset ?? 0;
        $limit = max(1, (int) $limit);

        // Prepare queries for different operations
        $paginatedQuery = array_merge($query, [
            '_limit' => $limit,
            '_offset' => $offset,
        ]);
        unset($paginatedQuery['_page']);

        $countQuery = $query; // Use original query without pagination
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);

        // Create promises for each operation in order of expected duration (longest first)
        $promises = [];

        // 1. Facetable discovery (~25ms) - Only if requested
        if ($facetable === true || $facetable === 'true') {
            $baseQuery = $countQuery;
            $sampleSize = (int) ($query['_sample_size'] ?? 100);
            
            $promises['facetable'] = new Promise(function ($resolve, $reject) use ($baseQuery, $sampleSize) {
                try {
                    $result = $this->getFacetableFields($baseQuery, $sampleSize);
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        }

        // 2. Search results (~10ms)
        $promises['search'] = new Promise(function ($resolve, $reject) use ($paginatedQuery) {
            try {
                $result = $this->searchObjects($paginatedQuery);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });

        // 3. Facets (~10ms)
        $promises['facets'] = new Promise(function ($resolve, $reject) use ($countQuery) {
            try {
                $result = $this->getFacetsForObjects($countQuery);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });

        // 4. Count (~5ms)
        $promises['count'] = new Promise(function ($resolve, $reject) use ($countQuery) {
            try {
                $result = $this->countSearchObjects($countQuery);
                $resolve($result);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });

        // Execute all promises concurrently and combine results
        return \React\Promise\all($promises)->then(function ($results) use ($page, $limit, $offset, $query, $startTime) {
            // Extract results from promises
            $searchResults = $results['search'];
            $total = $results['count'];
            $facets = $results['facets'];
            $facetableFields = $results['facetable'] ?? null;

            // Calculate total pages
            $pages = max(1, ceil($total / $limit));

            // Build the paginated results structure
            $paginatedResults = [
                'results' => $searchResults,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'limit' => $limit,
                'offset' => $offset,
                'facets' => $facets,
            ];

            // Add facetable field discovery if it was requested
            if ($facetableFields !== null) {
                $paginatedResults['facetable'] = $facetableFields;
            }

            // Add next/prev page URLs if applicable
            $currentUrl = $_SERVER['REQUEST_URI'];

            // Add next page link if there are more pages
            if ($page < $pages) {
                $nextPage = ($page + 1);
                $nextUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
                if (strpos($nextUrl, 'page=') === false) {
                    $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&') . 'page=' . $nextPage;
                }
                $paginatedResults['next'] = $nextUrl;
            }

            // Add previous page link if not on first page
            if ($page > 1) {
                $prevPage = ($page - 1);
                $prevUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
                if (strpos($prevUrl, 'page=') === false) {
                    $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&') . 'page=' . $prevPage;
                }
                $paginatedResults['prev'] = $prevUrl;
            }

            // Calculate execution time in milliseconds
            $executionTime = (microtime(true) - $startTime) * 1000;

            // Log the search trail with actual execution time
            $this->logSearchTrail($query, count($searchResults), $total, $executionTime, 'async');

            return $paginatedResults;
        });

    }//end searchObjectsPaginatedAsync()


    /**
     * Helper method to execute async search and return results synchronously
     *
     * This method provides a convenient way to use the async search functionality
     * while maintaining a synchronous interface. It's useful when you want the
     * performance benefits of concurrent operations but need to work within
     * synchronous code.
     *
     * @param array $query The search query array (same structure as searchObjectsPaginated)
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array<string, mixed> The same structure as searchObjectsPaginated
     */
    public function searchObjectsPaginatedSync(array $query = []): array
    {
        // Execute the async version and wait for the result
        $promise = $this->searchObjectsPaginatedAsync($query);
        
        // Use React's await functionality to get the result synchronously
        // Note: The async version already logs the search trail, so we don't need to log again
        return \React\Async\await($promise);

    }//end searchObjectsPaginatedSync()


    // From this point on only deprecated functions for backwards compatibility with OpenConnector. To remove after OpenConnector refactor.


    /**
     * Returns the current schema
     *
     * @deprecated
     *
     * @return int The current schema
     */
    public function getSchema(): int
    {
        return $this->currentSchema->getId();

    }//end getSchema()


    /**
     * Returns the current register
     *
     * @deprecated
     *
     * @return int
     */
    public function getRegister(): int
    {
        return $this->currentRegister->getId();

    }//end getRegister()


    /**
     * Find multiple objects by their ids
     *
     * @param array $ids The ids to fetch objects for
     *
     * @return array The found objects
     *
     * @deprecated This can now be done using the ids field in the findAll-function
     */
    public function findMultiple(array $ids): array
    {
        return $this->findAll(['ids' => $ids]);

    }//end findMultiple()


    /**
     * Renders the rendered object.
     *
     * @param ObjectEntity      $entity The entity to be rendered
     * @param array|null $extend Optional array to extend the entity
     * @param int|null   $depth  Optional depth for rendering
     * @param array|null $filter Optional filters to apply
     * @param array|null $fields Optional fields to include
     *
     * @return array The rendered entity.
     */
    public function renderEntity(ObjectEntity $entity, ?array $extend=[], ?int $depth=0, ?array $filter=[], ?array $fields=[]): array
    {
        return $this->renderHandler->renderEntity(entity: $entity, extend: $extend, depth: $depth, filter: $filter, fields: $fields)->jsonSerialize();

    }//end renderEntity()


    /**
     * Returns the object on a certain uuid
     *
     * @param string $uuid The uuid to find an object for.
     *
     * @return ObjectEntity|null
     *
     * @throws Exception
     *
     * @deprecated The find function now also handles only fetching by uuid.
     */
    public function findByUuid(string $uuid): ?ObjectEntity
    {
        return $this->find($uuid);

    }//end findByUuid()


    /**
     * Get facets for the current register and schema
     *
     * @param array       $filters The filters to apply
     * @param string|null $search  The search query
     *
     * @return array The facets
     *
     * @deprecated Use getFacetsForObjects() with _facets configuration instead
     */
    public function getFacets(array $filters=[], ?string $search=null): array
    {
        // Convert to new faceting system
        $query = [
            '@self' => [
                'register' => $this->getRegister(),
                'schema' => $this->getSchema()
            ],
            '_search' => $search,
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema' => ['type' => 'terms']
                ]
            ]
        ];
        
        // Add object field filters and create basic facet config
        foreach ($filters as $key => $value) {
            if (!in_array($key, ['register', 'schema']) && !str_starts_with($key, '_')) {
                $query[$key] = $value;
                $query['_facets'][$key] = ['type' => 'terms'];
            }
        }

        return $this->getFacetsForObjects($query);

    }//end getFacets()

	/**
	 * Handle validation exceptions
	 *
	 * @param ValidationException|CustomValidationException $exception The exception to handle
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The resulting response
	 *
	 * @deprecated
	 */
    public function handleValidationException(ValidationException|CustomValidationException $exception) {
        return $this->validateHandler->handleValidationException($exception);
    }


    /**
     * Publish an object, setting its publication date to now or a specified date.
     *
     * @param string|null $uuid The UUID of the object to publish. If null, uses the current object.
     * @param \DateTime|null $date Optional publication date. If null, uses current date/time.
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     */
    public function publish(string $uuid = null, ?\DateTime $date = null): ObjectEntity
    {

        // Use the publish handler to publish the object
        return $this->publishHandler->publish(
            uuid: $uuid,
            date: $date
        );
    }

    /**
     * Depublish an object, setting its depublication date to now or a specified date.
     *
     * @param string|null $uuid The UUID of the object to depublish. If null, uses the current object.
     * @param \DateTime|null $date Optional depublication date. If null, uses current date/time.
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     */
    public function depublish(string $uuid = null, ?\DateTime $date = null): ObjectEntity
    {
        // Use the depublish handler to depublish the object
        return $this->depublishHandler->depublish(
            uuid: $uuid,
            date: $date
        );
    }

	/**
	 * Locks an object
	 *
	 * @param string|int $identifier The object to lock
	 * @param string|null $process The process to lock the object for
	 * @param int $duration The duration to set the lock for
	 *
	 * @return ObjectEntity The locked objectEntity
	 * @throws DoesNotExistException
	 *
	 * @deprecated
	 */
	public function lockObject(string|int $identifier, ?string $process = null, int $duration = 3600): ObjectEntity
	{
		return $this->objectEntityMapper->lockObject(identifier: $identifier, process: $process, duration: $duration);
	}

	/**
	 * Unlocks an object
	 *
	 * @param string|int $identifier The object to unlock
	 *
	 * @return ObjectEntity The unlocked objectEntity
	 * @throws DoesNotExistException
	 *
	 * @deprecated
	 */
	public function unlockObject(string|int $identifier): ObjectEntity
	{
		return $this->objectEntityMapper->unlockObject(identifier: $identifier);
	}


    /**
     * Merge two objects within the same register and schema
     *
     * This method merges a source object into a target object, handling properties,
     * files, and relations according to the specified actions. The source object
     * is deleted after successful merge.
     *
     * @param string $sourceObjectId The ID/UUID of the source object (object A)
     * @param array $mergeData Merge request data containing:
     *                        - target: Target object ID (object to merge into)
     *                        - object: Merged object data (without id)
     *                        - fileAction: File action ('transfer' or 'delete')
     *                        - relationAction: Relation action ('transfer' or 'drop')
     *
     * @return array The merge report containing results and statistics
     *
     * @throws DoesNotExistException If either object doesn't exist
     * @throws \InvalidArgumentException If objects are not in the same register/schema or required data is missing
     * @throws \Exception If there's an error during the merge process
     *
     * @phpstan-param array<string, mixed> $mergeData
     * @phpstan-return array<string, mixed>
     * @psalm-param array<string, mixed> $mergeData
     * @psalm-return array<string, mixed>
     */
    public function mergeObjects(string $sourceObjectId, array $mergeData): array {
        // Extract parameters from merge data
        $targetObjectId = $mergeData['target'] ?? null;
        $mergedData = $mergeData['object'] ?? [];
        $fileAction = $mergeData['fileAction'] ?? 'transfer';
        $relationAction = $mergeData['relationAction'] ?? 'transfer';

        if (!$targetObjectId) {
            throw new \InvalidArgumentException('Target object ID is required');
        }
        
        // Initialize merge report
        $mergeReport = [
            'success' => false,
            'sourceObject' => null,
            'targetObject' => null,
            'mergedObject' => null,
            'actions' => [
                'properties' => [],
                'files' => [],
                'relations' => [],
                'references' => []
            ],
            'statistics' => [
                'propertiesChanged' => 0,
                'filesTransferred' => 0,
                'filesDeleted' => 0,
                'relationsTransferred' => 0,
                'relationsDropped' => 0,
                'referencesUpdated' => 0
            ],
            'warnings' => [],
            'errors' => []
        ];

        try {
            // Fetch both objects directly from mapper for updating (not rendered)
            
            try {
                $sourceObject = $this->objectEntityMapper->find($sourceObjectId);
            } catch (\Exception $e) {
                $sourceObject = null;
            }
            
            try {
                $targetObject = $this->objectEntityMapper->find($targetObjectId);
            } catch (\Exception $e) {
                $targetObject = null;
            }

            if ($sourceObject === null) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('Source object not found');
            }

            if ($targetObject === null) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('Target object not found');
            }

            // Store original objects in report
            $mergeReport['sourceObject'] = $sourceObject->jsonSerialize();
            $mergeReport['targetObject'] = $targetObject->jsonSerialize();

            // Validate objects are in same register and schema
            if ($sourceObject->getRegister() !== $targetObject->getRegister()) {
                throw new \InvalidArgumentException('Objects must be in the same register');
            }

            if ($sourceObject->getSchema() !== $targetObject->getSchema()) {
                throw new \InvalidArgumentException('Objects must conform to the same schema');
            }

            // Merge properties
            $targetObjectData = $targetObject->getObject();
            $changedProperties = [];

            foreach ($mergedData as $property => $value) {
                $oldValue = $targetObjectData[$property] ?? null;
                
                if ($oldValue !== $value) {
                    $targetObjectData[$property] = $value;
                    $changedProperties[] = [
                        'property' => $property,
                        'oldValue' => $oldValue,
                        'newValue' => $value
                    ];
                    $mergeReport['statistics']['propertiesChanged']++;
                }
            }

            $mergeReport['actions']['properties'] = $changedProperties;

            // Handle files
            if ($fileAction === 'transfer' && $sourceObject->getFolder() !== null) {
                try {
                    $fileResult = $this->transferObjectFiles($sourceObject, $targetObject);
                    $mergeReport['actions']['files'] = $fileResult['files'];
                    $mergeReport['statistics']['filesTransferred'] = $fileResult['transferred'];
                    
                    if (!empty($fileResult['errors'])) {
                        $mergeReport['warnings'] = array_merge($mergeReport['warnings'], $fileResult['errors']);
                    }
                } catch (\Exception $e) {
                    $mergeReport['warnings'][] = 'Failed to transfer files: ' . $e->getMessage();
                }
            } elseif ($fileAction === 'delete' && $sourceObject->getFolder() !== null) {
                try {
                    $deleteResult = $this->deleteObjectFiles($sourceObject);
                    $mergeReport['actions']['files'] = $deleteResult['files'];
                    $mergeReport['statistics']['filesDeleted'] = $deleteResult['deleted'];
                    
                    if (!empty($deleteResult['errors'])) {
                        $mergeReport['warnings'] = array_merge($mergeReport['warnings'], $deleteResult['errors']);
                    }
                } catch (\Exception $e) {
                    $mergeReport['warnings'][] = 'Failed to delete files: ' . $e->getMessage();
                }
            }

            // Handle relations
            if ($relationAction === 'transfer') {
                $sourceRelations = $sourceObject->getRelations();
                $targetRelations = $targetObject->getRelations();
                
                $transferredRelations = [];
                foreach ($sourceRelations as $relation) {
                    if (!in_array($relation, $targetRelations)) {
                        $targetRelations[] = $relation;
                        $transferredRelations[] = $relation;
                        $mergeReport['statistics']['relationsTransferred']++;
                    }
                }
                
                $targetObject->setRelations($targetRelations);
                $mergeReport['actions']['relations'] = [
                    'action' => 'transferred',
                    'relations' => $transferredRelations
                ];
            } else {
                $mergeReport['actions']['relations'] = [
                    'action' => 'dropped',
                    'relations' => $sourceObject->getRelations()
                ];
                $mergeReport['statistics']['relationsDropped'] = count($sourceObject->getRelations());
            }

            // Update target object with merged data
            $targetObject->setObject($targetObjectData);
            $updatedObject = $this->objectEntityMapper->update($targetObject);

            // Update references to source object
            $referencingObjects = $this->findByRelations($sourceObject->getUuid());
            $updatedReferences = [];

            foreach ($referencingObjects as $referencingObject) {
                $relations = $referencingObject->getRelations();
                $updated = false;

                for ($i = 0; $i < count($relations); $i++) {
                    if ($relations[$i] === $sourceObject->getUuid()) {
                        $relations[$i] = $targetObject->getUuid();
                        $updated = true;
                        $mergeReport['statistics']['referencesUpdated']++;
                    }
                }

                if ($updated) {
                    $referencingObject->setRelations($relations);
                    $this->objectEntityMapper->update($referencingObject);
                    $updatedReferences[] = [
                        'objectId' => $referencingObject->getUuid(),
                        'title' => $referencingObject->getTitle() ?? $referencingObject->getUuid()
                    ];
                }
            }

            $mergeReport['actions']['references'] = $updatedReferences;

            // Soft delete source object using the entity's delete method
            $sourceObject->delete($this->userSession, 'Merged into object ' . $targetObject->getUuid());
            $this->objectEntityMapper->update($sourceObject);

            // Set success and add merged object to report
            $mergeReport['success'] = true;
            $mergeReport['mergedObject'] = $updatedObject->jsonSerialize();
            
            // Merge completed successfully

        } catch (\Exception $e) {
            // Handle merge error
            $mergeReport['errors'][] = "Merge failed: " . $e->getMessage();
            $mergeReport['errors'][] = $e->getMessage();
            throw $e;
        }

        return $mergeReport;

    }//end mergeObjects()


    /**
     * Transfer files from source object to target object
     *
     * @param ObjectEntity $sourceObject The source object
     * @param ObjectEntity $targetObject The target object
     *
     * @return array Result of file transfer operation
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    private function transferObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): array
    {
        $result = [
            'files' => [],
            'transferred' => 0,
            'errors' => []
        ];

        try {
            // Ensure target object has a folder
            $this->ensureObjectFolderExists($targetObject);

            // Get files from source folder
            $sourceFiles = $this->fileService->getFiles($sourceObject);
            
            foreach ($sourceFiles as $file) {
                try {
                    // Skip if not a file
                    if (!($file instanceof \OCP\Files\File)) {
                        continue;
                    }
                    
                    // Get file content and create new file in target object
                    $fileContent = $file->getContent();
                    $fileName = $file->getName();
                    
                    // Create new file in target object folder
                    $this->fileService->addFile(
                        objectEntity: $targetObject,
                        fileName: $fileName,
                        content: $fileContent,
                        share: false,
                        tags: []
                    );
                    
                    // Delete original file from source
                    $this->fileService->deleteFile($file, $sourceObject);
                    
                    $result['files'][] = [
                        'name' => $fileName,
                        'action' => 'transferred',
                        'success' => true
                    ];
                    $result['transferred']++;
                } catch (\Exception $e) {
                    $result['files'][] = [
                        'name' => $file->getName(),
                        'action' => 'transfer_failed',
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $result['errors'][] = 'Failed to transfer file ' . $file->getName() . ': ' . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to access source files: ' . $e->getMessage();
        }

        return $result;

    }//end transferObjectFiles()


    /**
     * Delete files from source object
     *
     * @param ObjectEntity $sourceObject The source object
     *
     * @return array Result of file deletion operation
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    private function deleteObjectFiles(ObjectEntity $sourceObject): array
    {
        $result = [
            'files' => [],
            'deleted' => 0,
            'errors' => []
        ];

        try {
            // Get files from source folder
            $sourceFiles = $this->fileService->getFiles($sourceObject);
            
            foreach ($sourceFiles as $file) {
                try {
                    // Skip if not a file
                    if (!($file instanceof \OCP\Files\File)) {
                        continue;
                    }
                    
                    $fileName = $file->getName();
                    
                    // Delete the file using FileService
                    $this->fileService->deleteFile($file, $sourceObject);
                    
                    $result['files'][] = [
                        'name' => $fileName,
                        'action' => 'deleted',
                        'success' => true
                    ];
                    $result['deleted']++;
                } catch (\Exception $e) {
                    $result['files'][] = [
                        'name' => $file->getName(),
                        'action' => 'delete_failed',
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $result['errors'][] = 'Failed to delete file ' . $file->getName() . ': ' . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to access source files: ' . $e->getMessage();
        }

        return $result;

    }//end deleteObjectFiles()




    /**
     * Handles pre-validation cascading for inversedBy properties
     *
     * This method processes properties with inversedBy configuration BEFORE validation.
     * It creates related objects from nested object data and replaces them with UUIDs
     * so that validation sees UUIDs instead of objects.
     *
     * @param array       $object The object data to process
     * @param Schema      $schema The schema containing property definitions
     * @param string|null $uuid   The UUID of the parent object (will be generated if null)
     *
     * @return array Array containing [processedObject, parentUuid]
     *
     * @throws Exception If there's an error during object creation
     */
    private function handlePreValidationCascading(array $object, Schema $schema, ?string $uuid): array
    {
        // Pre-validation cascading to handle nested objects
        
        try {
            // Get the URL generator from the SaveObject handler
            $urlGenerator = new \ReflectionClass($this->saveHandler);
            $urlGeneratorProperty = $urlGenerator->getProperty('urlGenerator');
            $urlGeneratorProperty->setAccessible(true);
            $urlGeneratorInstance = $urlGeneratorProperty->getValue($this->saveHandler);
            
            $schemaObject = $schema->getSchemaObject($urlGeneratorInstance);
            $properties = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
            // Process schema properties for inversedBy relationships
        } catch (Exception $e) {
            // Handle error in schema processing
            return [$object, $uuid];
        }

        // Find properties that have inversedBy configuration
        $inversedByProperties = array_filter(
            $properties,
            function (array $property) {
                // Check for inversedBy in array items
                if ($property['type'] === 'array' && isset($property['items']['inversedBy'])) {
                    return true;
                }
                // Check for inversedBy in direct object properties
                if (isset($property['inversedBy'])) {
                    return true;
                }
                return false;
            }
        );

        // Check if we have any inversedBy properties to process
        if (empty($inversedByProperties)) {
            return [$object, $uuid];
        }

        // Generate UUID for parent object if not provided
        if ($uuid === null) {
            $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        }

        foreach ($inversedByProperties as $propertyName => $definition) {
            // Skip if property not present in data or is empty
            if (!isset($object[$propertyName]) || empty($object[$propertyName])) {
                continue;
            }

            $propertyValue = $object[$propertyName];

            // Handle array properties
            if ($definition['type'] === 'array' && isset($definition['items']['inversedBy'])) {
                if (is_array($propertyValue) && !empty($propertyValue)) {
                    $createdUuids = [];
                    foreach ($propertyValue as $item) {
                        if (is_array($item) && !$this->isUuid($item)) {
                            // This is a nested object, create it first
                            $createdUuid = $this->createRelatedObject($item, $definition['items'], $uuid);
                            if ($createdUuid) {
                                $createdUuids[] = $createdUuid;
                            }
                        } elseif (is_string($item) && $this->isUuid($item)) {
                            // This is already a UUID, keep it
                            $createdUuids[] = $item;
                        }
                    }
                    $object[$propertyName] = $createdUuids;
                }
            }
            // Handle single object properties
            elseif (isset($definition['inversedBy']) && !($definition['type'] === 'array')) {
                if (is_array($propertyValue) && !$this->isUuid($propertyValue)) {
                    // This is a nested object, create it first
                    $createdUuid = $this->createRelatedObject($propertyValue, $definition, $uuid);
                    if ($createdUuid) {
                        $object[$propertyName] = $createdUuid;
                    }
                }
            }
        }

        return [$object, $uuid];
    }

    /**
     * Creates a related object and returns its UUID
     *
     * @param array  $objectData The object data to create
     * @param array  $definition The property definition containing schema reference
     * @param string $parentUuid The UUID of the parent object
     *
     * @return string|null The UUID of the created object or null if creation failed
     */
    private function createRelatedObject(array $objectData, array $definition, string $parentUuid): ?string
    {
        try {
            // Resolve schema reference to actual schema ID
            $schemaRef = $definition['$ref'] ?? null;
            if (!$schemaRef) {
                return null;
            }

            // Extract schema slug from reference
            $schemaSlug = null;
            if (str_contains($schemaRef, '#/components/schemas/')) {
                $schemaSlug = substr($schemaRef, strrpos($schemaRef, '/') + 1);
            }

            if (!$schemaSlug) {
                return null;
            }

            // Find the schema - use the same logic as SaveObject.resolveSchemaReference
            $targetSchema = null;
            
            // First try to find by slug using findAll and filtering
            $allSchemas = $this->schemaMapper->findAll();
            foreach ($allSchemas as $schema) {
                if (strcasecmp($schema->getSlug(), $schemaSlug) === 0) {
                    $targetSchema = $schema;
                    break;
                }
            }
            
            if (!$targetSchema) {
                return null;
            }

            // Get the register (use the same register as the parent object)
            $targetRegister = $this->currentRegister;

            // Add the inverse relationship to the parent object
            $inversedBy = $definition['inversedBy'] ?? null;
            if ($inversedBy) {
                $objectData[$inversedBy] = $parentUuid;
            }

            // Create the object
            $createdObject = $this->saveHandler->saveObject(
                register: $targetRegister,
                schema: $targetSchema,
                data: $objectData,
                uuid: null // Let it generate a new UUID
            );

            return $createdObject->getUuid();
        } catch (Exception $e) {
            // Log error but don't expose details
            return null;
        }
    }

    /**
     * Checks if a value is a UUID string
     *
     * @param mixed $value The value to check
     *
     * @return bool True if the value is a UUID string
     */
    private function isUuid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Migrate objects between registers and/or schemas
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param string|int $sourceRegister    The source register ID or slug
     * @param string|int $sourceSchema      The source schema ID or slug  
     * @param string|int $targetRegister    The target register ID or slug
     * @param string|int $targetSchema      The target schema ID or slug
     * @param array      $objectIds         Array of object IDs to migrate
     * @param array      $mapping           Simple mapping where keys are target properties, values are source properties
     *
     * @return array Migration result with statistics and details
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     *
     * @throws DoesNotExistException If register or schema not found
     * @throws \InvalidArgumentException If invalid parameters provided
     */
    public function migrateObjects(
        string|int $sourceRegister,
        string|int $sourceSchema,
        string|int $targetRegister,
        string|int $targetSchema,
        array $objectIds,
        array $mapping
    ): array {
        // Initialize migration report
        $migrationReport = [
            'success' => false,
            'statistics' => [
                'objectsMigrated' => 0,
                'objectsFailed' => 0,
                'propertiesMapped' => 0,
                'propertiesDiscarded' => 0,
            ],
            'details' => [],
            'warnings' => [],
            'errors' => []
        ];

        try {
            // Load source and target registers/schemas
            $sourceRegisterEntity = is_string($sourceRegister) || is_int($sourceRegister) 
                ? $this->registerMapper->find($sourceRegister) 
                : $sourceRegister;
            $sourceSchemaEntity = is_string($sourceSchema) || is_int($sourceSchema) 
                ? $this->schemaMapper->find($sourceSchema) 
                : $sourceSchema;
            $targetRegisterEntity = is_string($targetRegister) || is_int($targetRegister) 
                ? $this->registerMapper->find($targetRegister) 
                : $targetRegister;
            $targetSchemaEntity = is_string($targetSchema) || is_int($targetSchema) 
                ? $this->schemaMapper->find($targetSchema) 
                : $targetSchema;

            // Validate entities exist
            if (!$sourceRegisterEntity || !$sourceSchemaEntity || !$targetRegisterEntity || !$targetSchemaEntity) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('One or more registers/schemas not found');
            }

            // Get all source objects at once using ObjectEntityMapper
            $sourceObjects = $this->objectEntityMapper->findMultiple($objectIds);

            
            // Keep track of remaining object IDs to find which ones weren't found
            $remainingObjectIds = $objectIds;

            // Set target context for saving
            $this->setRegister($targetRegisterEntity);
            $this->setSchema($targetSchemaEntity);

            // Process each found source object
            foreach ($sourceObjects as $sourceObject) {
                $objectId = $sourceObject->getUuid();
                $objectDetail = [
                    'objectId' => $objectId,
                    'objectTitle' => null,
                    'success' => false,
                    'error' => null
                ];

                // Remove this object from the remaining list (it was found) - do this BEFORE try-catch
                $remainingObjectIds = array_filter($remainingObjectIds, function($id) use ($sourceObject) {
                    return $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId();
                });

                try {

                    $objectDetail['objectTitle'] =  $sourceObject->getName() ?? $sourceObject->getUuid();

                    // Verify the source object belongs to the expected register/schema (cast to int for comparison)
                    if ((int)$sourceObject->getRegister() !== (int)$sourceRegister || 
                    (int)$sourceObject->getSchema() !== (int)$sourceSchema) {
                        $actualRegister = $sourceObject->getRegister();
                        $actualSchema = $sourceObject->getSchema();
                        throw new \InvalidArgumentException(
                            "Object {$objectId} does not belong to the specified source register/schema. " .
                            "Expected: register='{$sourceRegister}', schema='{$sourceSchema}'. " .
                            "Actual: register='{$actualRegister}', schema='{$actualSchema}'"
                        );
                    }

                    // Get source object data (the JSON object property)
                    $sourceData = $sourceObject->getObject();

                    // Map properties according to mapping configuration  
                    $mappedData = $this->mapObjectProperties($sourceData, $mapping);
                    $migrationReport['statistics']['propertiesMapped'] += count($mappedData);
                    $migrationReport['statistics']['propertiesDiscarded'] += (count($sourceData) - count($mappedData));

                    // Log the mapping result for debugging
                    error_log("Migration mapping for object {$objectId}: " . json_encode([
                        'sourceData' => $sourceData,
                        'mapping' => $mapping,
                        'mappedData' => $mappedData
                    ]));

                    // Store original files and relations before altering the object
                    $originalFiles = $sourceObject->getFolder();
                    $originalRelations = $sourceObject->getRelations();
                    
                    // Alter the existing object to migrate it to the target register/schema
                    $sourceObject->setRegister($targetRegisterEntity->getId());
                    
                    $sourceObject->setSchema($targetSchemaEntity->getId());
                    
                    $sourceObject->setObject($mappedData);
                    
                    // Update the object using the mapper
                    $savedObject = $this->objectEntityMapper->update($sourceObject);

                    // Log the save response for debugging
                    error_log("Migration save response for object {$objectId}: " . json_encode($savedObject->jsonSerialize()));

                    // Handle file migration (files should already be attached to the object)
                    if ($originalFiles !== null) {
                        // Files are already associated with this object, no migration needed
                        error_log("Files preserved for migrated object {$objectId}");
                    }

                    // Handle relations migration (relations are already on the object)
                    if (!empty($originalRelations)) {
                        // Relations are preserved on the object, no additional migration needed
                        error_log("Relations preserved for migrated object {$objectId}");
                    }

                    $objectDetail['success'] = true;
                    $objectDetail['newObjectId'] = $savedObject->getUuid(); // Same UUID, but migrated
                    $migrationReport['statistics']['objectsMigrated']++;

                } catch (\Exception $e) {
                    $objectDetail['error'] = $e->getMessage();
                    $migrationReport['statistics']['objectsFailed']++;
                    $migrationReport['errors'][] = "Failed to migrate object {$objectId}: " . $e->getMessage();
                    
                    // Log the full exception for debugging
                    error_log("Migration error for object {$objectId}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                }

                $migrationReport['details'][] = $objectDetail;
            }

            // Handle objects that weren't found
            foreach ($remainingObjectIds as $notFoundId) {
                $objectDetail = [
                    'objectId' => $notFoundId,
                    'objectTitle' => null,
                    'success' => false,
                    'error' => "Object with ID {$notFoundId} not found"
                ];
                
                $migrationReport['details'][] = $objectDetail;
                $migrationReport['statistics']['objectsFailed']++;
                $migrationReport['errors'][] = "Failed to migrate object {$notFoundId}: Object not found";
            }

            // Set overall success if at least one object was migrated
            $migrationReport['success'] = $migrationReport['statistics']['objectsMigrated'] > 0;

            // Add warnings if some objects failed
            if ($migrationReport['statistics']['objectsFailed'] > 0) {
                $migrationReport['warnings'][] = "Some objects failed to migrate. Check details for specific errors.";
            }

        } catch (\Exception $e) {
            $migrationReport['errors'][] = $e->getMessage();
            error_log("Migration process error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }

        return $migrationReport;

    }//end migrateObjects()


    /**
     * Map object properties using simple mapping configuration
     *
     * Maps properties from source object data to target object data using a simple mapping array.
     * The mapping array has target properties as keys and source properties as values.
     * Only properties that exist in the source data and are mapped will be included.
     *
     * @param array $sourceData The source object data
     * @param array $mapping    Simple mapping array where:
     *                          - Keys are target property names
     *                          - Values are source property names
     *                          Example: ['targetProp' => 'sourceProp', 'Test' => 'titel']
     *
     * @return array The mapped object data containing only the mapped properties
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return array<string, mixed>
     */
    private function mapObjectProperties(array $sourceData, array $mapping): array
    {
        $mappedData = [];

        // Simple mapping: keys are target properties, values are source properties
        foreach ($mapping as $targetProperty => $sourceProperty) {
            // Only map if the source property exists in the source data
            if (array_key_exists($sourceProperty, $sourceData)) {
                $mappedData[$targetProperty] = $sourceData[$sourceProperty];
            }
        }

        return $mappedData;

    }//end mapObjectProperties()


    /**
     * Migrate files from source object to target object
     *
     * @param ObjectEntity $sourceObject The source object
     * @param ObjectEntity $targetObject The target object
     *
     * @return void
     *
     * @phpstan-return void
     * @psalm-return void
     */
    private function migrateObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): void
    {
        try {
            // Ensure target object has a folder
            $this->ensureObjectFolderExists($targetObject);

            // Get files from source folder
            $sourceFiles = $this->fileService->getFiles($sourceObject);
            
            foreach ($sourceFiles as $file) {
                try {
                    // Skip if not a file
                    if (!($file instanceof \OCP\Files\File)) {
                        continue;
                    }
                    
                    // Copy file content to target object (don't delete from source yet)
                    $fileContent = $file->getContent();
                    $fileName = $file->getName();
                    
                    // Create copy of file in target object folder
                    $this->fileService->addFile(
                        objectEntity: $targetObject,
                        fileName: $fileName,
                        content: $fileContent,
                        share: false,
                        tags: []
                    );
                } catch (\Exception $e) {
                    // Log error but continue with other files
                    error_log("Failed to migrate file {$file->getName()}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the migration
            error_log("Failed to migrate files for object {$sourceObject->getUuid()}: " . $e->getMessage());
        }

    }//end migrateObjectFiles()


    /**
     * Migrate relations from source object to target object
     *
     * @param ObjectEntity $sourceObject The source object
     * @param ObjectEntity $targetObject The target object
     *
     * @return void
     *
     * @phpstan-return void
     * @psalm-return void
     */
    private function migrateObjectRelations(ObjectEntity $sourceObject, ObjectEntity $targetObject): void
    {
        try {
            // Copy relations from source to target
            $sourceRelations = $sourceObject->getRelations();
            if (!empty($sourceRelations)) {
                $targetObject->setRelations($sourceRelations);
                $this->objectEntityMapper->update($targetObject);
            }

            // Update references to source object to point to target object
            $referencingObjects = $this->findByRelations($sourceObject->getUuid());

            foreach ($referencingObjects as $referencingObject) {
                $relations = $referencingObject->getRelations();
                $updated = false;

                for ($i = 0; $i < count($relations); $i++) {
                    if ($relations[$i] === $sourceObject->getUuid()) {
                        $relations[$i] = $targetObject->getUuid();
                        $updated = true;
                    }
                }

                if ($updated) {
                    $referencingObject->setRelations($relations);
                    $this->objectEntityMapper->update($referencingObject);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the migration
            error_log("Failed to migrate relations for object {$sourceObject->getUuid()}: " . $e->getMessage());
        }

    }//end migrateObjectRelations()


    /**
     * Log a search trail for analytics
     *
     * This method creates a search trail entry to track search operations,
     * including search terms, parameters, results, and performance metrics.
     * System parameters (starting with _) are excluded from tracking.
     *
     * @param array  $query         The search query parameters
     * @param int    $resultCount   The number of results returned
     * @param int    $totalResults  The total number of matching results
     * @param float  $executionTime The actual execution time in milliseconds
     * @param string $executionType The execution type ('sync' or 'async')
     *
     * @return void
     */
    private function logSearchTrail(array $query, int $resultCount, int $totalResults, float $executionTime, string $executionType = 'sync'): void
    {
        try {
            // Create the search trail entry using the service with actual execution time
            $this->searchTrailService->createSearchTrail(
                $query,
                $resultCount,
                $totalResults,
                $executionTime,
                $executionType
            );
        } catch (\Exception $e) {
            // Log the error but don't fail the request
            error_log("Failed to log search trail: " . $e->getMessage());
        }

    }//end logSearchTrail()

}//end class
