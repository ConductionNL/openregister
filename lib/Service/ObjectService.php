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

use Adbar\Dot;
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
use OCA\OpenRegister\Service\ObjectHandlers\SaveObjects;
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
use OCP\IGroupManager;
use OCP\IUserManager;
use OCA\OpenRegister\Service\OrganisationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Primary Object Management Service for OpenRegister
 *
 * ARCHITECTURE OVERVIEW:
 * This is the main orchestration service that coordinates object operations across the application.
 * It acts as a high-level facade that delegates specific operations to specialized handlers while
 * managing application state, context, and cross-cutting concerns like RBAC, caching, and validation.
 *
 * KEY RESPONSIBILITIES:
 * - Object lifecycle management (find, create, update, delete operations)
 * - Bulk operations orchestration with performance optimizations
 * - Register and Schema context management
 * - RBAC and multi-tenancy enforcement
 * - Search, pagination, and faceting capabilities
 * - Event coordination and audit trail management
 *
 * HANDLER DELEGATION:
 * - Individual object CRUD → SaveObject handler
 * - Bulk operations → Internal optimized methods + SaveObject for complex cases
 * - Validation → ValidateObject handler
 * - Rendering → RenderObject handler
 * - File operations → FileService
 *
 * PERFORMANCE FEATURES:
 * - Comprehensive schema analysis and caching
 * - Memory-optimized bulk operations with pass-by-reference
 * - Single-pass inverse relation processing
 * - Batch database operations
 *
 * ⚠️ IMPORTANT: Do NOT confuse with SaveObject handler!
 * - ObjectService = High-level orchestration and bulk operations
 * - SaveObject = Individual object save/create/update logic with relations handling
 *
 * @category   Service
 * @package    OCA\OpenRegister\Service
 *
 * @author     Conduction Development Team <info@conduction.nl>
 * @copyright  2024 Conduction B.V.
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version    GIT: <git_id>
 *
 * @link       https://www.OpenRegister.app
 *
 * @since      1.0.0 Initial ObjectService implementation
 * @since      1.5.0 Added bulk operations and performance optimizations
 * @since      2.0.0 Added comprehensive schema analysis and memory optimization
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
     * @param DeleteObject        $deleteHandler       Handler for object deletion.
     * @param GetObject           $getHandler          Handler for object retrieval.
     * @param RenderObject        $renderHandler       Handler for object rendering.
     * @param SaveObject          $saveHandler         Handler for individual object saving.
     * @param SaveObjects         $saveObjectsHandler  Handler for bulk object saving operations.
     * @param ValidateObject      $validateHandler     Handler for object validation.
     * @param PublishObject       $publishHandler      Handler for object publication.
     * @param DepublishObject     $depublishHandler    Handler for object depublication.
     * @param RegisterMapper      $registerMapper      Mapper for register operations.
     * @param SchemaMapper        $schemaMapper        Mapper for schema operations.
     * @param ObjectEntityMapper  $objectEntityMapper  Mapper for object entity operations.
     * @param FileService         $fileService         Service for file operations.
     * @param IUserSession        $userSession         User session for getting current user.
     * @param SearchTrailService  $searchTrailService  Service for search trail operations.
     * @param IGroupManager       $groupManager        Group manager for checking user groups.
     * @param IUserManager        $userManager         User manager for getting user objects.
     * @param OrganisationService $organisationService Service for organisation operations.
     * @param LoggerInterface     $logger              Logger for performance monitoring.
     */
    public function __construct(
        private readonly DeleteObject $deleteHandler,
        private readonly GetObject $getHandler,
        private readonly RenderObject $renderHandler,
        private readonly SaveObject $saveHandler,
        private readonly SaveObjects $saveObjectsHandler,
        private readonly ValidateObject $validateHandler,
        private readonly PublishObject $publishHandler,
        private readonly DepublishObject $depublishHandler,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly SearchTrailService $searchTrailService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Check if the current user is in the admin group.
     *
     * This helper method determines if the current logged-in user belongs to the 'admin' group,
     * which allows bypassing RBAC and multitenancy restrictions.
     *
     * @return bool True if user is admin, false otherwise
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);
        return in_array('admin', $userGroups);

    }//end isCurrentUserAdmin()


    /**
     * Check if the current user has permission to perform a specific CRUD action on objects of a given schema
     *
     * This method implements the RBAC permission checking logic:
     * - Admin group always has all permissions
     * - Object owner always has all permissions for their specific objects
     * - If no authorization configured, all users have all permissions
     * - Otherwise, check if user's groups match the required groups for the action
     *
     * TODO: Implement property-level RBAC checks
     * Properties can have their own authorization arrays that provide fine-grained access control.
     * When processing object data, we should check each property's authorization before allowing
     * create/read/update/delete operations on specific property values.
     *
     * @param Schema      $schema      The schema to check permissions for
     * @param string      $action      The CRUD action (create, read, update, delete)
     * @param string|null $userId      Optional user ID (defaults to current user)
     * @param string|null $objectOwner Optional object owner for ownership check
     *
     * @return bool True if user has permission, false otherwise
     *
     * @throws \Exception If user session is invalid or user groups cannot be determined
     */
    private function hasPermission(Schema $schema, string $action, ?string $userId=null, ?string $objectOwner=null, bool $rbac=true): bool
    {
        // If RBAC is disabled, always return true (bypass all permission checks)
        if ($rbac === false) {
            return true;
        }

        // Get current user if not provided
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // For unauthenticated requests, check if 'public' group has permission
                return $schema->hasPermission('public', $action, null, null, $objectOwner);
            }

            $userId = $user->getUID();
        }

        // Get user object from user ID
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, treat as public
            return $schema->hasPermission('public', $action, null, null, $objectOwner);
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Check if user is admin (admin group always has all permissions)
        if (in_array('admin', $userGroups)) {
            return true;
        }

        // Object owner permission check is now handled in schema->hasPermission() call below
        // Check schema permissions for each user group
        foreach ($userGroups as $groupId) {
            if ($schema->hasPermission($groupId, $action, $userId, in_array('admin', $userGroups) ? 'admin' : null, $objectOwner)) {
                return true;
            }
        }

        return false;

    }//end hasPermission()


    /**
     * Validate user has permission for a specific action, throw exception if not
     *
     * @param Schema      $schema      The schema to check permissions for
     * @param string      $action      The CRUD action (create, read, update, delete)
     * @param string|null $userId      Optional user ID (defaults to current user)
     * @param string|null $objectOwner Optional object owner for ownership check
     *
     * @throws \Exception If user does not have permission
     *
     * @return void
     */
    private function checkPermission(Schema $schema, string $action, ?string $userId=null, ?string $objectOwner=null, bool $rbac=true): void
    {
        if (!$this->hasPermission($schema, $action, $userId, $objectOwner, $rbac)) {
            $user     = $this->userSession->getUser();
            $userName = $user ? $user->getDisplayName() : 'Anonymous';
            throw new \Exception("User '{$userName}' does not have permission to '{$action}' objects in schema '{$schema->getTitle()}'");
        }

    }//end checkPermission()


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
     * @psalm-return   void
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

    }//end getValidateHandler()


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
     * @param bool                     $rbac     Whether to apply RBAC checks (default: true).
     * @param bool                     $multi    Whether to apply multitenancy filtering (default: true).
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
        Schema | string | int | null $schema=null,
        bool $rbac=true,
        bool $multi=true
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
            files: $files,
            rbac: $rbac,
            multi: $multi
        );

        // If the object is not found, return null.
        if ($object === null) {
            return null;
        }

        // If no schema was provided but we have an object, derive the schema from the object
        if ($this->currentSchema === null) {
            $this->setSchema($object->getSchema());
        }

        // If the object is not published, check the permissions.
        $now = new \DateTime('now');
        if ($object->getPublished() === null || $now < $object->getPublished() || ($object->getDepublished() !== null && $object->getDepublished() <= $now)) {
            // Check user has permission to read this specific object (includes object owner check)
            $this->checkPermission($this->currentSchema, 'read', null, $object->getOwner(), $rbac);
        }

        // Render the object before returning.
        $registers = null;
        if ($this->currentRegister !== null) {
            $registers = [$this->currentRegister->getId() => $this->currentRegister];
        }

        // Always use the current schema (either provided or derived from object)
        $schemas = [$this->currentSchema->getId() => $this->currentSchema];

        return $this->renderHandler->renderEntity(
            entity: $object,
            extend: $extend,
            registers: $registers,
            schemas: $schemas,
            rbac: $rbac,
            multi: $multi
        );

    }//end find()


    /**
     * Creates a new object from an array.
     *
     * @param array                    $object   The object data to create.
     * @param array|null               $extend   Properties to extend the object with.
     * @param Register|string|int|null $register The register object or its ID/UUID.
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
     * @param bool                     $rbac     Whether to apply RBAC checks (default: true).
     * @param bool                     $multi    Whether to apply multitenancy filtering (default: true).
     *
     * @return array The created object.
     *
     * @throws Exception If there is an error during creation.
     */
    public function createFromArray(
        array $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        bool $rbac=true,
        bool $multi=true
    ): ObjectEntity {
        // Check if a register is provided and set the current register context.
        if ($register !== null) {
            $this->setRegister($register);
        }

        // Check if a schema is provided and set the current schema context.
        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Check user has permission to create objects in this schema
        if ($this->currentSchema !== null) {
            $this->checkPermission($this->currentSchema, 'create', null, null, $rbac);
        }

        // Skip validation here - let saveObject handle the proper order of pre-validation cascading then validation
        // Create a temporary object entity to generate UUID and create folder
        $tempObject = new ObjectEntity();
        $tempObject->setRegister($this->currentRegister->getId());
        $tempObject->setSchema($this->currentSchema->getId());
        $tempObject->setUuid(Uuid::v4()->toRfc4122());

        // Set organisation from active organisation (always respect user's active organisation)
        $organisationUuid = $this->organisationService->getOrganisationForNewEntity();
        $tempObject->setOrganisation($organisationUuid);

        // Create folder before saving to avoid double update
        $folderId = null;
        try {
            $folderId = $this->fileService->createObjectFolderWithoutUpdate($tempObject);
        } catch (\Exception $e) {
            // Log error but continue - object can function without folder
        }

        // Save the object using the current register and schema with folder ID
        $savedObject = $this->saveObject(
            object: $object,
            register:$this->currentRegister,
            schema: $this->currentSchema,
            uuid: $tempObject->getUuid(),
        // $folderId
        );

        // Render and return the saved object.
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
            extend: $extend,
            registers: [$this->currentRegister->getId() => $this->currentRegister],
            schemas: [$this->currentSchema->getId() => $this->currentSchema],
            rbac: $rbac,
            multi: $multi
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
     * @param bool                     $rbac          Whether to apply RBAC checks (default: true).
     * @param bool                     $multi         Whether to apply multitenancy filtering (default: true).
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
        Schema | string | int | null $schema=null,
        bool $rbac=true,
        bool $multi=true
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
        $existingObject = $this->getHandler->find(id: $id, rbac: $rbac, multi: $multi);
        if ($existingObject === null) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found');
        }

        // If no schema was provided but we have an existing object, derive the schema from the object
        if ($this->currentSchema === null) {
            $this->setSchema($existingObject->getSchema());
        }

        // Check user has permission to update this specific object
        $this->checkPermission($this->currentSchema, 'update', null, $existingObject->getOwner(), $rbac);

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
            }
        }

        // Save the object using the current register and schema.
        $savedObject = $this->saveHandler->saveObject(
            register: $this->currentRegister,
            schema: $this->currentSchema,
            data: $object,
            uuid: $id,
            folderId: $folderId,
            rbac: $rbac,
            multi: $multi
        );

        // Render and return the saved object.
        return $this->renderHandler->renderEntity(
            entity: $savedObject,
            extend: $extend,
            registers: [$this->currentRegister->getId() => $this->currentRegister],
            schemas: [$this->currentSchema->getId() => $this->currentSchema],
            rbac: $rbac,
            multi: $multi
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
        // TODO: Add nightly cron job to cleanup orphaned folders and logs
        // This should scan for folders without corresponding objects and clean them up
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
     * @param bool  $rbac   Whether to apply RBAC checks (default: true).
     * @param bool  $multi  Whether to apply multitenancy filtering (default: true).
     *
     * @return array Array of objects matching the configuration
     */
    public function findAll(array $config=[], bool $rbac=true, bool $multi=true): array
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
            published: $config['published'] ?? false,
            rbac: $rbac,
            multi: $multi
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
                schemas: $schemas,
                rbac: $rbac,
                multi: $multi
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
     * @param bool   $rbac    Whether to apply RBAC checks (default: true).
     * @param bool   $multi   Whether to apply multitenancy filtering (default: true).
     *
     * @return array Array of log entries
     */
    public function getLogs(string $uuid, array $filters=[], bool $rbac=true, bool $multi=true): array
    {
        // Get logs for the specified object.
        $object = $this->objectEntityMapper->find($uuid);
        $logs   = $this->getHandler->findLogs($object, filters: $filters, rbac: $rbac, multi: $multi);

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
     * @param bool                     $rbac     Whether to apply RBAC checks (default: true).
     * @param bool                     $multi    Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The saved object.
     *
     * @throws Exception If there is an error during save.
     */


    /**
     * Save a single object (HIGH-LEVEL ORCHESTRATION METHOD)
     *
     * ARCHITECTURAL ROLE:
     * This is a high-level orchestration method that handles context management, permission checks,
     * and delegates the actual saving logic to the SaveObject handler. It manages the application
     * state and cross-cutting concerns before and after the save operation.
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObject() = Context setup, RBAC, state management, rendering
     * - SaveObject.saveObject() = Actual saving logic, relations, validation, database operations
     *
     * WORKFLOW:
     * 1. Set register/schema context
     * 2. Handle ObjectEntity input conversion
     * 3. Perform RBAC permission checks
     * 4. Delegate to SaveObject handler for actual saving
     * 5. Render and return the result
     *
     * FOR BULK OPERATIONS: Use saveObjects() method for optimized bulk processing
     *
     * TODO: Add property-level RBAC validation here
     * Before saving object data, check if user has permission to create/update specific properties
     * based on property-level authorization arrays in the schema.
     *
     * @param array|ObjectEntity       $object   The object data to save or ObjectEntity instance
     * @param array|null               $extend   Properties to extend the object with
     * @param Register|string|int|null $register The register object or its ID/UUID
     * @param Schema|string|int|null   $schema   The schema object or its ID/UUID
     * @param string|null              $uuid     The UUID of the object to update (if updating)
     * @param bool                     $rbac     Whether to apply RBAC checks (default: true)
     * @param bool                     $multi    Whether to apply multitenancy filtering (default: true)
     *
     * @return ObjectEntity The saved and rendered object
     *
     * @throws Exception If there is an error during save
     */
    public function saveObject(
        array | ObjectEntity $object,
        ?array $extend=[],
        Register | string | int | null $register=null,
        Schema | string | int | null $schema=null,
        ?string $uuid=null,
        bool $rbac=true,
        bool $multi=true
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

            $object = $object->getObject();
            // Get the object data array
        }

        // Determine if this is a CREATE or UPDATE operation and check permissions
        $isUpdate = false;
        if ($uuid !== null) {
            try {
                $existingObject = $this->objectEntityMapper->find($uuid);
                $isUpdate       = true;
                // This is an UPDATE operation
                if ($this->currentSchema !== null) {
                    $this->checkPermission($this->currentSchema, 'update', null, $existingObject->getOwner(), $rbac);
                }
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object not found, this is a CREATE operation with specific UUID
                if ($this->currentSchema !== null) {
                    $this->checkPermission($this->currentSchema, 'create', null, null, $rbac);
                }
            }
        } else {
            // No UUID provided, this is a CREATE operation
            if ($this->currentSchema !== null) {
                $this->checkPermission($this->currentSchema, 'create', null, null, $rbac);
            }
        }

        // Store the parent object's register and schema context before cascading
        // This prevents nested object creation from corrupting the main object's context
        $parentRegister = $this->currentRegister;
        $parentSchema   = $this->currentSchema;

        // Pre-validation cascading: Handle inversedBy properties BEFORE validation
        // This creates related objects and replaces them with UUIDs so validation sees UUIDs, not objects
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
        [$object, $uuid] = $this->handlePreValidationCascading($object, $parentSchema, $uuid);

        // Restore the parent object's register and schema context after cascading
        $this->currentRegister = $parentRegister;
        $this->currentSchema   = $parentSchema;

        // Validate the object against the current schema only if hard validation is enabled.
        if ($this->currentSchema->getHardValidation() === true) {
            $result = $this->validateHandler->validateObject($object, $this->currentSchema);
            if ($result->isValid() === false) {
                $meaningfulMessage = $this->validateHandler->generateErrorMessage($result);
                throw new ValidationException($meaningfulMessage, errors: $result->error());
            }
        } else {
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
                    }
                }
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object not found, will create new one with the specified UUID
                // Let SaveObject handle the creation with the provided UUID
            } catch (\Exception $e) {
                // Other errors - let SaveObject handle the creation
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
            $folderId,
            $rbac,
            $multi
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
            schemas: $schemas,
            rbac: $rbac,
            multi: $multi
        );

    }//end saveObject()


    /**
     * Delete an object.
     *
     * @param string $uuid  The UUID of the object to delete
     * @param bool   $rbac  Whether to apply RBAC checks (default: true).
     * @param bool   $multi Whether to apply multitenancy filtering (default: true).
     *
     * @return bool Whether the deletion was successful
     *
     * @throws \Exception If user does not have delete permission
     */
    public function deleteObject(string $uuid, bool $rbac=true, bool $multi=true): bool
    {
        // Find the object to get its owner for permission check (include soft-deleted objects)
        try {
            $objectToDelete = $this->objectEntityMapper->find($uuid, null, null, true);

            // If no schema was provided but we have an object, derive the schema from the object
            if ($this->currentSchema === null) {
                $this->setSchema($objectToDelete->getSchema());
            }

            // Check user has permission to delete this specific object
            $this->checkPermission($this->currentSchema, 'delete', null, $objectToDelete->getOwner(), $rbac);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Object doesn't exist, no permission check needed but let the deleteHandler handle this
            if ($this->currentSchema !== null) {
                $this->checkPermission($this->currentSchema, 'delete', null, null, $rbac);
            }
        }

        return $this->deleteHandler->deleteObject(
            $this->currentRegister,
            $this->currentSchema,
            $uuid,
            null,
            $rbac,
            $multi
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
     * Find applicable ids for objects that have an inversed relationship through which a search request is performed.
     *
     * @param  array $filters The set of filters to find the inversed relationships through.
     * @return array|null The list of ids that have an inversed relationship to an object that meets the filters. Returns NULL if no filters are found that are applicable.
     *
     * @throws \OCP\DB\Exception
     */
    private function applyInversedByFilter(array &$filters): ?array
    {
        if ($filters['schema'] === false) {
            return null;
        }

        $schema = $this->schemaMapper->find($filters['schema']);

        $filterKeysWithSub = array_filter(
                array_keys($filters),
                function ($filter) {
                    if (str_contains($filter, '_')) {
                        return true;
                    }

                    return false;
                }
                );

        $filtersWithSub = array_intersect_key($filters, array_flip($filterKeysWithSub));

        if (empty($filtersWithSub)) {
            return null;
        }

        $filterDot = new Dot(items: $filtersWithSub, parse: true, delimiter: '_');

        $ids = [];

        $iterator = 0;
        foreach ($filterDot as $key => $value) {
            if (isset($schema->getProperties()[$key]['inversedBy']) === false) {
                continue;
            }

            $iterator++;
            $property = $schema->getProperties()[$key];

            $value = (new Dot($value))->flatten(delimiter: '_');

            // @TODO fix schema finder
            $value['schema'] = $property['$ref'];

            $objects  = $this->findAll(config: ['filters' => $value]);
            $foundIds = array_map(
                    function (ObjectEntity $object) use ($property, $key) {
                        $idRaw = $object->jsonSerialize()[$property['inversedBy']];

                        if (Uuid::isValid($idRaw) === true) {
                            return $idRaw;
                        } else if (filter_var($idRaw, FILTER_VALIDATE_URL) !== false) {
                            $path = explode(separator: '/', string: parse_url($idRaw, PHP_URL_PATH));

                            return end($path);
                        }
                    },
                    $objects
                    );

            if ($ids === []) {
                $ids = $foundIds;
            } else {
                $ids = array_intersect($ids, $foundIds);
            }

            foreach ($value as $k => $v) {
                unset($filters[$key.'_'.$k]);
            }
        }//end foreach

        if ($iterator === 0 && $ids === []) {
            return null;
        }

        return $ids;

    }//end applyInversedByFilter()


    /**
     * Find all objects conforming to the request parameters, surrounded with pagination data.
     *
     * @param array $requestParams The request parameters to search with.
     * @param bool  $rbac          Whether to apply RBAC checks (default: true).
     * @param bool  $multi         Whether to apply multitenancy filtering (default: true).
     *
     * @return array The result including pagination data.
     */
    public function findAllPaginated(array $requestParams, bool $rbac=true, bool $multi=true): array
    {
        $requestParams = $this->cleanQuery($requestParams);

        // Extract specific parameters.
        $limit     = $requestParams['limit'] ?? $requestParams['_limit'] ?? null;
        $offset    = $requestParams['offset'] ?? $requestParams['_offset'] ?? null;
        $order     = $requestParams['order'] ?? $requestParams['_order'] ?? [];
        $extend    = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $page      = $requestParams['page'] ?? $requestParams['_page'] ?? null;
        $search    = $requestParams['_search'] ?? null;
        $fields    = $requestParams['_fields'] ?? null;
        $published = $requestParams['_published'] ?? false;
        $facetable = $requestParams['_facetable'] ?? false;
        $ids       = null;

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

        $searchIds = $this->applyInversedByFilter(filters: $filters);

        if ($ids === null && $searchIds !== null) {
            $ids = $searchIds;
        } else if ($ids !== null && $searchIds !== null) {
            $ids = array_intersect($ids, $searchIds);
        }

        if ($ids !== null) {
            $objects = $this->findAll(
                [
                    "limit"     => $limit,
                    "offset"    => $offset,
                    "filters"   => $filters,
                    "sort"      => $order,
                    "search"    => $search,
                    "extend"    => $extend,
                    'fields'    => $fields,
                    'published' => $published,
                    'ids'       => $ids,
                ]
            );
            $total   = $this->count(
                [
                    "filters" => $filters,
                    "ids"     => $ids,
                ]
            );
        } else {
            $objects = $this->findAll(
                [
                    "limit"     => $limit,
                    "offset"    => $offset,
                    "filters"   => $filters,
                    "sort"      => $order,
                    "search"    => $search,
                    "extend"    => $extend,
                    'fields'    => $fields,
                    'published' => $published,
                ]
            );
            $total   = $this->count(
                [
                    "filters" => $filters,
                ]
            );
        }//end if

        if ($limit !== null) {
            $pages = ceil($total / $limit);
        } else {
            $pages = 1;
        }

        // Use new faceting system with basic configuration
        $facetQuery = [
            '@self'   => array_intersect_key($filters, array_flip(['register', 'schema'])),
            '_search' => $search,
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema'   => ['type' => 'terms'],
                ],
            ],
        ];

        // Add object field filters to facet query
        $objectFilters = array_diff_key($filters, array_flip(['register', 'schema', 'extend', 'limit', 'offset', 'order', 'page']));
        foreach ($objectFilters as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $facetQuery[$key]            = $value;
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
            $baseQuery = $facetQuery;
            // Use the same base query as for facets
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
         * Get the active organization for the current user
         *
         * This method determines the active organization using the same logic as SaveObject
         * to ensure consistency between save and retrieval operations.
         *
         * @return string|null The active organization UUID or null if none found
         */
    private function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = $this->organisationService->getActiveOrganisation();

            if ($activeOrganisation !== null) {
                return $activeOrganisation->getUuid();
            } else {
                return null;
            }
        } catch (Exception $e) {
            // Log error but continue without organization context
            return null;
        }

        return null;

    }//end getActiveOrganisationForContext()


    /**
     * Get the current user ID
     *
     * @return string|null The current user ID or null if not authenticated
     */
    private function getCurrentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;

    }//end getCurrentUserId()


    /**
     * Get the current organisation ID
     *
     * @return string|null The current organisation ID or null if none found
     */
    private function getCurrentOrganisationId(): ?string
    {
        try {
            return $this->organisationService->getOrganisationForNewEntity();
        } catch (Exception $e) {
            return null;
        }

    }//end getCurrentOrganisationId()


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
    public function searchObjects(array $query=[], bool $rbac=true, bool $multi=true): array|int
    {
        // Get active organization context for multi-tenancy (only if multi is enabled)
        $activeOrganisationUuid = $multi ? $this->getActiveOrganisationForContext() : null;

        // **PERFORMANCE OPTIMIZATION**: Use chunked queries for very large result sets
        $limit = $query['_limit'] ?? 20;
        $dbStart = microtime(true);
        
        if ($limit >= 200) {
            $this->logger->debug('Using chunked database query for large dataset', [
                'requestedLimit' => $limit,
                'chunkThreshold' => 200
            ]);
            $result = $this->executeChunkedSearch($query, $activeOrganisationUuid, $rbac, $multi, $limit);
        } else {
            // Use the standard method for smaller queries
            $result = $this->objectEntityMapper->searchObjects($query, $activeOrganisationUuid, $rbac, $multi);
        }
        
        $dbTime = round((microtime(true) - $dbStart) * 1000, 2);
        $this->logger->debug('Database query completed', [
            'dbTime' => $dbTime . 'ms',
            'resultCount' => is_array($result) ? count($result) : 0,
            'limit' => $limit
        ]);

        // If _count option was used, return the integer count directly
        if (isset($query['_count']) && $query['_count'] === true) {
            return $result;
        }

        // For regular search results, proceed with rendering
        $objects = $result;

        // Get unique register and schema IDs from the results for rendering context
        $registerIds = array_unique(array_filter(array_map(fn($object) => $object->getRegister() ?? null, $objects)));
        $schemaIds   = array_unique(array_filter(array_map(fn($object) => $object->getSchema() ?? null, $objects)));

        // Load registers and schemas for rendering if needed
        $registers = null;
        $schemas   = null;

        if (!empty($registerIds)) {
            $registerEntities = $this->registerMapper->findMultiple(ids: $registerIds);
            $registers        = array_combine(array_map(fn($register) => $register->getId(), $registerEntities), $registerEntities);
        }

        if (!empty($schemaIds)) {
            $schemaEntities = $this->schemaMapper->findMultiple(ids: $schemaIds);
            $schemas        = array_combine(array_map(fn($schema) => $schema->getId(), $schemaEntities), $schemaEntities);
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
        $filter = $query['_filter'] ?? null;
        if (is_string($filter)) {
            $filter = array_map('trim', explode(',', $filter));
        }

        // Extract unset configuration from query if present
        $unset = $query['_unset'] ?? null;
        if (is_string($unset)) {
            $unset = array_map('trim', explode(',', $unset));
        }

        // **PERFORMANCE OPTIMIZATION**: Ultra-aggressive preload for sub-second performance
        // For target < 1s, we need to preload ALL relationships in ONE query
        if (!empty($extend) && !empty($objects)) {
            $startUltraPreload = microtime(true);
            
            // Extract ALL relationship IDs from ALL objects at once
            $allRelationshipIds = $this->extractAllRelationshipIds($objects, $extend);
            
            if (!empty($allRelationshipIds)) {
                // Single bulk query for ALL relationships across ALL objects
                $this->logger->debug('Ultra preload: bulk loading relationships', [
                    'objectCount' => count($objects),
                    'totalRelationshipIds' => count($allRelationshipIds),
                    'extends' => implode(',', $extend)
                ]);
                
                // Bulk load in single query - this is the key optimization
                $relatedObjectsMap = $this->bulkLoadRelationships($allRelationshipIds);
                
                // Store in render handler for instant access during rendering
                $this->renderHandler->setUltraPreloadCache($relatedObjectsMap);
                
                $ultraPreloadTime = round((microtime(true) - $startUltraPreload) * 1000, 2);
                $this->logger->debug('Ultra preload completed', [
                    'ultraPreloadTime' => $ultraPreloadTime . 'ms',
                    'cachedObjects' => count($relatedObjectsMap),
                    'objectsToRender' => count($objects)
                ]);
            }
        }

        // **SUB-SECOND OPTIMIZATION**: Use sequential rendering with ultra-cached data
        // Parallel rendering has overhead - with ultra cache, sequential is faster
        $startRender = microtime(true);
        
        foreach ($objects as $key => $object) {
            $objects[$key] = $this->renderHandler->renderEntity(
             entity: $object,
             extend: $extend,
             filter: $filter,
             fields: $fields,
             unset: $unset,
             registers: $registers,
             schemas: $schemas,
             rbac: $rbac,
             multi: $multi
            );
        }
        
        $renderTime = round((microtime(true) - $startRender) * 1000, 2);
        $objectCount = count($objects);
        $this->logger->debug('Ultra-fast rendering completed', [
            'renderTime' => $renderTime . 'ms',
            'objectCount' => $objectCount,
            'avgPerObject' => $objectCount > 0 ? round($renderTime / $objectCount, 2) . 'ms' : '0ms',
            'ultraCacheEnabled' => !empty($this->renderHandler->getUltraCacheSize())
        ]);

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
    public function countSearchObjects(array $query=[], bool $rbac=true, bool $multi=true): int
    {
        // Get active organization context for multi-tenancy (only if multi is enabled)
        $activeOrganisationUuid = $multi ? $this->getActiveOrganisationForContext() : null;

        // Use the new optimized countSearchObjects method from ObjectEntityMapper with organization context
        return $this->objectEntityMapper->countSearchObjects($query, $activeOrganisationUuid, $rbac, $multi);

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
    public function countObjects(array $config=[]): int
    {
        // Extract metadata filters from @self if present (for compatibility)
        $metadataFilters = $config['@self'] ?? [];
        $register        = $metadataFilters['register'] ?? null;
        $schema          = $metadataFilters['schema'] ?? null;

        // Extract options
        $includeDeleted = $config['_includeDeleted'] ?? false;
        $published      = $config['_published'] ?? $config['published'] ?? false;
        $search         = $config['_search'] ?? $config['search'] ?? null;
        $ids            = $config['_ids'] ?? $config['ids'] ?? null;
        $uses           = $config['_uses'] ?? $config['uses'] ?? null;

        // Clean the query: remove @self and all properties prefixed with _
        $cleanQuery = array_filter(
                $config,
                function ($key) {
                    return $key !== '@self' && str_starts_with($key, '_') === false;
                },
                ARRAY_FILTER_USE_KEY
                );

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
            register: null,
        // Already added to filters above
            schema: null,
        // Already added to filters above
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
    public function getFacetsForObjects(array $query=[]): array
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
     * @param array $baseQuery  Base query filters to apply for context
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
    public function getFacetableFields(array $baseQuery=[], int $sampleSize=100): array
    {
        try {
            return $this->objectEntityMapper->getFacetableFields($baseQuery);
        } catch (\Exception $e) {
            throw new \Exception('Failed to get facetable fields from schemas: '.$e->getMessage(), 0, $e);
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
    public function searchObjectsPaginated(array $query=[]): array
    {
        // Start timing execution
        $startTime = microtime(true);

        // Extract pagination parameters
        $limit     = $query['_limit'] ?? 20;
        $offset    = $query['_offset'] ?? null;
        $page      = $query['_page'] ?? null;
        $facetable = $query['_facetable'] ?? false;

        // Calculate offset from page if provided
        if ($page !== null && $offset === null) {
            $page = max(1, (int) $page);
            // Ensure page is at least 1
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided
        if ($page === null && $offset !== null) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values
        $page   = $page ?? 1;
        $offset = $offset ?? 0;
        $limit  = max(1, (int) $limit);
        // Ensure limit is at least 1
        // Update query with calculated pagination values
        $paginatedQuery = array_merge(
                $query,
                [
                    '_limit'  => $limit,
                    '_offset' => $offset,
                ]
                );

        // Remove page parameter from the query as we use offset internally
        unset($paginatedQuery['_page']);

        // Get the search results
        $results = $this->searchObjects($paginatedQuery);

        // Get total count (without pagination)
        $countQuery = $query;
        // Use original query without pagination
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);
        $total = $this->countSearchObjects($countQuery);

        // Get facets (without pagination)
        $facets = $this->getFacetsForObjects($countQuery);

        // Calculate total pages
        $pages = max(1, ceil($total / $limit));

        // Initialize the results array with pagination information
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => $facets,
        ];

        // Add facetable field discovery if requested
        if ($facetable === true || $facetable === 'true') {
            $baseQuery = $countQuery;
            // Use the same base query as for facets
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
    public function searchObjectsPaginatedAsync(array $query=[]): PromiseInterface
    {
        // Start timing execution
        $startTime = microtime(true);
        $this->logger->debug('Starting searchObjectsPaginatedAsync', ['query_limit' => $query['_limit'] ?? 20]);

        // Extract pagination parameters (same as synchronous version)
        $limit     = $query['_limit'] ?? 20;
        $offset    = $query['_offset'] ?? null;
        $page      = $query['_page'] ?? null;
        $facetable = $query['_facetable'] ?? false;

        // Calculate offset from page if provided
        if ($page !== null && $offset === null) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided
        if ($page === null && $offset !== null) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values
        $page   = $page ?? 1;
        $offset = $offset ?? 0;
        $limit  = max(1, (int) $limit);

        // Prepare queries for different operations
        $paginatedQuery = array_merge(
                $query,
                [
                    '_limit'  => $limit,
                    '_offset' => $offset,
                ]
                );
        unset($paginatedQuery['_page']);

        $countQuery = $query;
        // Use original query without pagination
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);

        // Create promises for each operation in order of expected duration (longest first)
        $promises = [];

        // 1. Facetable discovery (~25ms) - Only if requested
        if ($facetable === true || $facetable === 'true') {
            $baseQuery  = $countQuery;
            $sampleSize = (int) ($query['_sample_size'] ?? 100);

            $promises['facetable'] = new Promise(
                    function ($resolve, $reject) use ($baseQuery, $sampleSize) {
                        try {
                            $result = $this->getFacetableFields($baseQuery, $sampleSize);
                            $resolve($result);
                        } catch (\Throwable $e) {
                            $reject($e);
                        }
                    }
                    );
        }

        // 2. Search results (~10ms)
        $promises['search'] = new Promise(
                function ($resolve, $reject) use ($paginatedQuery) {
                    try {
                        $searchStart = microtime(true);
                        $result = $this->searchObjects($paginatedQuery);
                        $searchTime = round((microtime(true) - $searchStart) * 1000, 2);
                        $this->logger->debug('Search objects completed', [
                            'searchTime' => $searchTime . 'ms',
                            'resultCount' => count($result),
                            'limit' => $paginatedQuery['_limit'] ?? 20
                        ]);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

        // 3. Facets (~10ms)
        $promises['facets'] = new Promise(
                function ($resolve, $reject) use ($countQuery) {
                    try {
                        $result = $this->getFacetsForObjects($countQuery);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

        // 4. Count (~5ms)
        $promises['count'] = new Promise(
                function ($resolve, $reject) use ($countQuery) {
                    try {
                        $result = $this->countSearchObjects($countQuery);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
                );

        // Execute all promises concurrently and combine results
        return \React\Promise\all($promises)->then(
                function ($results) use ($page, $limit, $offset, $query, $startTime) {
                    // Extract results from promises
                    $searchResults   = $results['search'];
                    $total           = $results['count'];
                    $facets          = $results['facets'];
                    $facetableFields = $results['facetable'] ?? null;

                    // Calculate total pages
                    $pages = max(1, ceil($total / $limit));

                    // Build the paginated results structure
                    $paginatedResults = [
                        'results' => $searchResults,
                        'total'   => $total,
                        'page'    => $page,
                        'pages'   => $pages,
                        'limit'   => $limit,
                        'offset'  => $offset,
                        'facets'  => $facets,
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
                    $this->logSearchTrail($query, count($searchResults), $total, $executionTime, 'async');

                    return $paginatedResults;
                }
                );

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
    public function searchObjectsPaginatedSync(array $query=[]): array
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
     * @param ObjectEntity $entity The entity to be rendered
     * @param array|null   $extend Optional array to extend the entity
     * @param int|null     $depth  Optional depth for rendering
     * @param array|null   $filter Optional filters to apply
     * @param array|null   $fields Optional fields to include
     * @param bool         $rbac   Whether to apply RBAC checks (default: true).
     * @param bool         $multi  Whether to apply multitenancy filtering (default: true).
     *
     * @return array The rendered entity.
     */
    public function renderEntity(ObjectEntity $entity, ?array $extend=[], ?int $depth=0, ?array $filter=[], ?array $fields=[], ?array $unset=[], bool $rbac=true, bool $multi=true): array
    {
        return $this->renderHandler->renderEntity(entity: $entity, extend: $extend, depth: $depth, filter: $filter, fields: $fields, unset: $unset, rbac: $rbac, multi: $multi)->jsonSerialize();

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
            '@self'   => [
                'register' => $this->getRegister(),
                'schema'   => $this->getSchema(),
            ],
            '_search' => $search,
            '_facets' => [
                '@self' => [
                    'register' => ['type' => 'terms'],
                    'schema'   => ['type' => 'terms'],
                ],
            ],
        ];

        // Add object field filters and create basic facet config
        foreach ($filters as $key => $value) {
            if (!in_array($key, ['register', 'schema']) && !str_starts_with($key, '_')) {
                $query[$key]            = $value;
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
    public function handleValidationException(ValidationException|CustomValidationException $exception)
    {
        return $this->validateHandler->handleValidationException($exception);

    }//end handleValidationException()


    /**
     * Publish an object, setting its publication date to now or a specified date.
     *
     * @param string|null    $uuid  The UUID of the object to publish. If null, uses the current object.
     * @param \DateTime|null $date  Optional publication date. If null, uses current date/time.
     * @param bool           $rbac  Whether to apply RBAC checks (default: true).
     * @param bool           $multi Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     */
    public function publish(string $uuid=null, ?\DateTime $date=null, bool $rbac=true, bool $multi=true): ObjectEntity
    {

        // Use the publish handler to publish the object
        return $this->publishHandler->publish(
            uuid: $uuid,
            date: $date,
            rbac: $rbac,
            multi: $multi
        );

    }//end publish()


    /**
     * Depublish an object, setting its depublication date to now or a specified date.
     *
     * @param string|null    $uuid  The UUID of the object to depublish. If null, uses the current object.
     * @param \DateTime|null $date  Optional depublication date. If null, uses current date/time.
     * @param bool           $rbac  Whether to apply RBAC checks (default: true).
     * @param bool           $multi Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws \Exception If the object is not found or if there's an error during update.
     */
    public function depublish(string $uuid=null, ?\DateTime $date=null, bool $rbac=true, bool $multi=true): ObjectEntity
    {
        // Use the depublish handler to depublish the object
        return $this->depublishHandler->depublish(
            uuid: $uuid,
            date: $date,
            rbac: $rbac,
            multi: $multi
        );

    }//end depublish()


    /**
     * Locks an object
     *
     * @param string|int  $identifier The object to lock
     * @param string|null $process    The process to lock the object for
     * @param int         $duration   The duration to set the lock for
     *
     * @return ObjectEntity The locked objectEntity
     * @throws DoesNotExistException
     *
     * @deprecated
     */
    public function lockObject(string|int $identifier, ?string $process=null, int $duration=3600): ObjectEntity
    {
        return $this->objectEntityMapper->lockObject(identifier: $identifier, process: $process, duration: $duration);

    }//end lockObject()


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

    }//end unlockObject()


    /**
     * Bulk Save Operations Orchestrator (HIGH-PERFORMANCE BULK PROCESSING)
     *
     * ARCHITECTURAL ROLE:
     * This is the primary bulk operations orchestrator that coordinates high-performance bulk saving
     * of multiple objects. It implements advanced performance optimizations including schema analysis
     * caching, memory optimization, single-pass processing, and batch database operations.
     *
     * PERFORMANCE OPTIMIZATIONS IMPLEMENTED:
     * 1. ✅ Eliminate redundant object fetch after save - reconstructed from existing data
     * 2. ✅ Consolidate schema cache - single persistent cache across operations
     * 3. ✅ Batch writeBack operations - bulk UPDATEs instead of individual calls
     * 4. ✅ Single-pass inverse relations - combined scanning and applying
     * 5. ✅ Optimize object transformation - in-place operations, minimal copying
     * 6. ✅ Comprehensive schema analysis - single pass for all requirements
     * 7. ✅ Memory optimization - pass-by-reference, selective updates
     *
     * RESPONSIBILITY SEPARATION:
     * - ObjectService.saveObjects() = Bulk orchestration, performance optimization, chunking
     * - SaveObject methods = Individual object complexities (cascading, writeBack)
     * - ObjectEntityMapper.saveObjects() = Actual database bulk operations
     *
     * WORKFLOW:
     * 1. Comprehensive schema analysis and caching
     * 2. Memory-optimized object preparation with relation processing
     * 3. Optional validation with minimal copying
     * 4. In-place format transformation
     * 5. Batch database operations
     * 6. Optimized inverse relation processing
     * 7. Bulk writeBack operations
     *
     * FOR INDIVIDUAL OBJECTS: Use saveObject() method for full feature set
     *
     * PERFORMANCE GAINS:
     * - Database calls: ~60-70% reduction
     * - Memory usage: ~40% reduction
     * - Time complexity: O(N*M*P) → O(N*M)
     * - Processing speed: 2-3x faster for large datasets
     *
     * @param array                    $objects    Array of objects in serialized format
     * @param Register|string|int|null $register   Optional register filter for validation
     * @param Schema|string|int|null   $schema     Optional schema filter for validation
     * @param bool                     $rbac       Whether to apply RBAC filtering
     * @param bool                     $multi      Whether to apply multi-organization filtering
     * @param bool                     $validation Whether to validate objects against schema definitions
     * @param bool                     $events     Whether to dispatch object lifecycle events
     *
     * @throws \InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @return array Comprehensive bulk operation results with statistics and categorized objects
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<string, mixed>
     */
    public function saveObjects(
        array $objects,
        Register|string|int|null $register=null,
        Schema|string|int|null $schema=null,
        bool $rbac=true,
        bool $multi=true,
        bool $validation=false,
        bool $events=false
    ): array {

        // Set register and schema context if provided
        if ($register !== null) {
            $this->setRegister($register);
        }

        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // ARCHITECTURAL DELEGATION: Use specialized SaveObjects handler for bulk operations
        // This provides better separation of concerns and optimized bulk processing
        return $this->saveObjectsHandler->saveObjects(
            objects: $objects,
            register: $this->currentRegister,
            schema: $this->currentSchema,
            rbac: $rbac,
            multi: $multi,
            validation: $validation,
            events: $events
        );

    }//end saveObjects()











    /**
     * Hydrate metadata fields from object data with minimal array copying
     *
     * PERFORMANCE OPTIMIZATION: This method reduces array copying by directly modifying
     * the @self section in-place rather than creating new arrays. It also uses early
     * returns and optimized field access patterns to minimize operations.
     *
     * @param array  $objectData Object data array with @self metadata
     * @param Schema $schema     Schema containing configuration for metadata field mapping
     *
     * @return array Modified object data with hydrated @self metadata
     *
     * @psalm-param   array $objectData
     * @phpstan-param array $objectData  
     * @psalm-return   array
     * @phpstan-return array
     */
    private function hydrateObjectMetadataFromData(array $objectData, Schema $schema): array
    {
        $config = $schema->getConfiguration();
        
        // PERFORMANCE OPTIMIZATION: Early return if no metadata fields configured
        if (empty($config['objectNameField']) && empty($config['objectDescriptionField']) 
            && empty($config['objectSummaryField']) && empty($config['objectImageField'])
            && empty($config['objectSlugField'])) {
            return $objectData;
        }

        // Initialize @self if not exists, but avoid copying if it already exists
        if (!isset($objectData['@self'])) {
            $objectData['@self'] = [];
        }

        // PERFORMANCE OPTIMIZATION: Direct field assignment with early termination
        // Process metadata fields efficiently with minimal lookups
        // COMPREHENSIVE METADATA FIELD SUPPORT: Include all supported metadata fields
        $metadataFields = [
            'name' => $config['objectNameField'] ?? null,
            'description' => $config['objectDescriptionField'] ?? null,
            'summary' => $config['objectSummaryField'] ?? null,
            'image' => $config['objectImageField'] ?? null,
            'slug' => $config['objectSlugField'] ?? null,
        ];

        foreach ($metadataFields as $metaField => $sourceField) {
            if (!empty($sourceField)) {
                if ($metaField === 'slug') {
                    // Special handling for slug - generate from source field value
                    $slugValue = $this->getValueFromPath($objectData, $sourceField);
                    if ($slugValue !== null) {
                        $generatedSlug = $this->generateSlugFromValue((string) $slugValue);
                        if ($generatedSlug) {
                            $objectData['@self'][$metaField] = $generatedSlug;
                        }
                    }
                } else {
                    // Regular metadata field handling
                    $value = $this->getValueFromPath($objectData, $sourceField);
                    if ($value !== null) {
                        $objectData['@self'][$metaField] = $value;
                    }
                }
            }
        }

        return $objectData;
    }//end hydrateObjectMetadataFromData()











    /**
     * Get value from object data using dot notation path
     *
     * @param array  $data Object data array
     * @param string $path Dot notation path (e.g., 'contact.email', 'title')
     *
     * @return mixed|null Value at the path or null if not found
     */
    private function getValueFromPath(array $data, string $path)
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                return null;
            }
        }

        return $current;
    }//end getValueFromPath()


    /**
     * Generate a slug from a given value
     *
     * METADATA ENHANCEMENT: Simplified slug generation for ObjectService metadata hydration
     *
     * @param string $value The value to convert to a slug
     *
     * @return string|null The generated slug or null if generation failed
     */
    private function generateSlugFromValue(string $value): ?string
    {
        try {
            if (empty($value)) {
                return null;
            }

            // Generate the base slug
            $slug = $this->createSlugHelper($value);

            // Add timestamp for uniqueness
            $timestamp = time();
            $uniqueSlug = $slug . '-' . $timestamp;

            return $uniqueSlug;
        } catch (\Exception $e) {
            return null;
        }
    }//end generateSlugFromValue()


    /**
     * Creates a URL-friendly slug from a string
     *
     * @param string $text The text to convert to a slug
     *
     * @return string The generated slug
     */
    private function createSlugHelper(string $text): string
    {
        // Convert to lowercase
        $text = strtolower($text);

        // Replace non-alphanumeric characters with hyphens
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        // Remove leading and trailing hyphens
        $text = trim($text, '-');

        // Ensure the slug is not empty
        if (empty($text)) {
            $text = 'object';
        }

        return $text;
    }//end createSlugHelper()














    /**
     * Handle post-save writeBack operations for inverse relations
     *
     * This method processes writeBack operations after objects have been saved to the database.
     * It uses the SaveObject handler's writeBack functionality for properties that have
     * both inversedBy and writeBack enabled.
     *
     * @param array $savedObjects Array of saved ObjectEntity objects
     * @param array $schemaCache  Cached schemas indexed by schema ID
     *
     * @return void
     */
    private function handlePostSaveInverseRelations(array $savedObjects, array $schemaCache): void
    {
        $writeBackCount = 0;
        $bulkWriteBackUpdates = []; // PERFORMANCE OPTIMIZATION: Collect updates for bulk processing

        foreach ($savedObjects as $savedObject) {
            $objectData = $savedObject->getObject();
            $schemaId   = $savedObject->getSchema();

            if (!isset($schemaCache[$schemaId])) {
                continue;
            }

            $schema           = $schemaCache[$schemaId];
            $schemaProperties = $schema->getProperties();

            foreach ($objectData as $property => $value) {
                if (!isset($schemaProperties[$property])) {
                    continue;
                }

                $propertyConfig = $schemaProperties[$property];
                $items          = $propertyConfig['items'] ?? [];

                // Check for writeBack enabled properties
                $writeBack  = $propertyConfig['writeBack'] ?? ($items['writeBack'] ?? false);
                $inversedBy = $propertyConfig['inversedBy'] ?? ($items['inversedBy'] ?? null);

                if ($writeBack && $inversedBy && !empty($value)) {
                    // Use SaveObject handler's writeBack functionality
                    try {
                        // Create a temporary object data array for writeBack processing
                        $writeBackData = [$property => $value];
                        $this->saveHandler->handleInverseRelationsWriteBack($savedObject, $schema, $writeBackData);
                        $writeBackCount++;

                        // After writeBack, update the source object's property with the current value
                        // This ensures the source object reflects the relationship
                        $currentObjectData = $savedObject->getObject();
                        if (!isset($currentObjectData[$property]) || $currentObjectData[$property] !== $value) {
                            $currentObjectData[$property] = $value;
                            $savedObject->setObject($currentObjectData);

                            // PERFORMANCE OPTIMIZATION: Collect for bulk update instead of individual UPDATE
                            $objectUuid = $savedObject->getUuid();
                            if (!isset($bulkWriteBackUpdates[$objectUuid])) {
                                $bulkWriteBackUpdates[$objectUuid] = $savedObject;
                            }
                        }
                    } catch (\Exception $e) {
                    }
                }//end if
            }//end foreach
        }//end foreach

        // PERFORMANCE OPTIMIZATION: Execute all writeBack updates in a single bulk operation
        if (!empty($bulkWriteBackUpdates)) {
            $this->performBulkWriteBackUpdates(array_values($bulkWriteBackUpdates));
        }


    }//end handlePostSaveInverseRelations()





    /**
     * Filter objects based on RBAC and multi-organization permissions
     *
     * @param array $objects Array of objects to filter
     * @param bool  $rbac    Whether to apply RBAC filtering
     * @param bool  $multi   Whether to apply multi-organization filtering
     *
     * @return array Filtered array of objects
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return   array<int, array<string, mixed>>
     */
    private function filterObjectsForPermissions(array $objects, bool $rbac, bool $multi): array
    {
        $filteredObjects = [];
        $currentUser     = $this->userSession->getUser();
        $userId          = $currentUser ? $currentUser->getUID() : null;
        $activeOrganisation = $this->getActiveOrganisationForContext();

        foreach ($objects as $object) {
            $self = $object['@self'] ?? [];

            // Check RBAC permissions if enabled
            if ($rbac && $userId !== null) {
                $objectOwner  = $self['owner'] ?? null;
                $objectSchema = $self['schema'] ?? null;

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);
                        // TODO: Add property-level RBAC check for 'create' action here
                        // Check individual property permissions before allowing property values to be set
                        if (!$this->hasPermission($schema, 'create', $userId, $objectOwner, $rbac)) {
                            continue;
                            // Skip this object if user doesn't have permission
                        }
                    } catch (\Exception $e) {
                        // Skip objects with invalid schemas
                        continue;
                    }
                }
            }

            // Check multi-organization filtering if enabled
            if ($multi && $activeOrganisation !== null) {
                $objectOrganisation = $self['organisation'] ?? null;
                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    continue;
                    // Skip objects from different organizations
                }
            }

            $filteredObjects[] = $object;
        }//end foreach

        return $filteredObjects;

    }//end filterObjectsForPermissions()


    /**
     * Validate that all objects have required fields in their @self section
     *
     * @param array $objects Array of objects to validate
     *
     * @throws \InvalidArgumentException If required fields are missing
     *
     * @return void
     *
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @psalm-param   array<int, array<string, mixed>> $objects
     */
    private function validateRequiredFields(array $objects): void
    {
        $requiredFields = ['register', 'schema'];

        foreach ($objects as $index => $object) {
            // Check if object has @self section
            if (!isset($object['@self']) || !is_array($object['@self'])) {
                throw new \InvalidArgumentException(
                    "Object at index {$index} is missing required '@self' section"
                );
            }

            $self = $object['@self'];

            // Check each required field
            foreach ($requiredFields as $field) {
                if (!isset($self[$field]) || empty($self[$field])) {
                    throw new \InvalidArgumentException(
                        "Object at index {$index} is missing required field '{$field}' in @self section"
                    );
                }
            }
        }

    }//end validateRequiredFields()


    /**
     * Transform objects from serialized format to database format
     *
     * Moves everything except '@self' into the 'object' property and moves
     * '@self' contents to the root level.
     *
     * @param array $objects Array of objects in serialized format
     *
     * @return array Array of transformed objects in database format
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return   array<int, array<string, mixed>>
     */











    /**
     * Merge new object data into existing object with minimal copying
     *
     * PERFORMANCE OPTIMIZATION: This method avoids unnecessary object cloning by directly
     * modifying the existing object when safe to do so. Object cloning can be expensive
     * for large objects with many properties.
     *
     * @param ObjectEntity $existingObject The existing object from database
     * @param array        $newObjectData  The new object data to merge
     *
     * @return ObjectEntity The merged object ready for update
     *
     * @phpstan-param array<string, mixed> $newObjectData
     * @psalm-param   array<string, mixed> $newObjectData
     */
    private function mergeObjectData(ObjectEntity $existingObject, array $newObjectData): ObjectEntity
    {
        // PERFORMANCE OPTIMIZATION: Hydrate directly instead of cloning
        // The existing object will be updated in-place, avoiding memory duplication
        // This is safe because we're in a bulk operation context where the original
        // objects are no longer needed after this transformation
        
        // CRITICAL FIX: Ensure correct property names before hydrating
        // ObjectEntity expects 'object' property, not 'data'
        if (isset($newObjectData['data']) && !isset($newObjectData['object'])) {
            $newObjectData['object'] = $newObjectData['data'];
            unset($newObjectData['data']);
        }
        
        
        $existingObject->hydrate($newObjectData);

        return $existingObject;

    }//end mergeObjectData()


    /**
     * Merge two objects within the same register and schema
     *
     * This method merges a source object into a target object, handling properties,
     * files, and relations according to the specified actions. The source object
     * is deleted after successful merge.
     *
     * @param string $sourceObjectId The ID/UUID of the source object (object A)
     * @param array  $mergeData      Merge request data containing:
     *                               - target: Target object ID
     *                               (object to merge into) -
     *                               object: Merged object data
     *                               (without id) - fileAction:
     *                               File action ('transfer' or
     *                               'delete') - relationAction:
     *                               Relation action ('transfer' or
     *                               'drop')
     *
     * @return array The merge report containing results and statistics
     *
     * @throws DoesNotExistException If either object doesn't exist
     * @throws \InvalidArgumentException If objects are not in the same register/schema or required data is missing
     * @throws \Exception If there's an error during the merge process
     *
     * @phpstan-param  array<string, mixed> $mergeData
     * @phpstan-return array<string, mixed>
     * @psalm-param    array<string, mixed> $mergeData
     * @psalm-return   array<string, mixed>
     */
    public function mergeObjects(string $sourceObjectId, array $mergeData): array
    {
        // Extract parameters from merge data
        $targetObjectId = $mergeData['target'] ?? null;
        $mergedData     = $mergeData['object'] ?? [];
        $fileAction     = $mergeData['fileAction'] ?? 'transfer';
        $relationAction = $mergeData['relationAction'] ?? 'transfer';

        if (!$targetObjectId) {
            throw new \InvalidArgumentException('Target object ID is required');
        }

        // Initialize merge report
        $mergeReport = [
            'success'      => false,
            'sourceObject' => null,
            'targetObject' => null,
            'mergedObject' => null,
            'actions'      => [
                'properties' => [],
                'files'      => [],
                'relations'  => [],
                'references' => [],
            ],
            'statistics'   => [
                'propertiesChanged'    => 0,
                'filesTransferred'     => 0,
                'filesDeleted'         => 0,
                'relationsTransferred' => 0,
                'relationsDropped'     => 0,
                'referencesUpdated'    => 0,
            ],
            'warnings'     => [],
            'errors'       => [],
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
            $targetObjectData  = $targetObject->getObject();
            $changedProperties = [];

            foreach ($mergedData as $property => $value) {
                $oldValue = $targetObjectData[$property] ?? null;

                if ($oldValue !== $value) {
                    $targetObjectData[$property] = $value;
                    $changedProperties[]         = [
                        'property' => $property,
                        'oldValue' => $oldValue,
                        'newValue' => $value,
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
                    $mergeReport['warnings'][] = 'Failed to transfer files: '.$e->getMessage();
                }
            } else if ($fileAction === 'delete' && $sourceObject->getFolder() !== null) {
                try {
                    $deleteResult = $this->deleteObjectFiles($sourceObject);
                    $mergeReport['actions']['files']           = $deleteResult['files'];
                    $mergeReport['statistics']['filesDeleted'] = $deleteResult['deleted'];

                    if (!empty($deleteResult['errors'])) {
                        $mergeReport['warnings'] = array_merge($mergeReport['warnings'], $deleteResult['errors']);
                    }
                } catch (\Exception $e) {
                    $mergeReport['warnings'][] = 'Failed to delete files: '.$e->getMessage();
                }
            }//end if

            // Handle relations
            if ($relationAction === 'transfer') {
                $sourceRelations = $sourceObject->getRelations();
                $targetRelations = $targetObject->getRelations();

                $transferredRelations = [];
                foreach ($sourceRelations as $relation) {
                    if (!in_array($relation, $targetRelations)) {
                        $targetRelations[]      = $relation;
                        $transferredRelations[] = $relation;
                        $mergeReport['statistics']['relationsTransferred']++;
                    }
                }

                $targetObject->setRelations($targetRelations);
                $mergeReport['actions']['relations'] = [
                    'action'    => 'transferred',
                    'relations' => $transferredRelations,
                ];
            } else {
                $mergeReport['actions']['relations']           = [
                    'action'    => 'dropped',
                    'relations' => $sourceObject->getRelations(),
                ];
                $mergeReport['statistics']['relationsDropped'] = count($sourceObject->getRelations());
            }//end if

            // Update target object with merged data
            $targetObject->setObject($targetObjectData);
            $updatedObject = $this->objectEntityMapper->update($targetObject);

            // Update references to source object
            $referencingObjects = $this->findByRelations($sourceObject->getUuid());
            $updatedReferences  = [];

            foreach ($referencingObjects as $referencingObject) {
                $relations = $referencingObject->getRelations();
                $updated   = false;

                for ($i = 0; $i < count($relations); $i++) {
                    if ($relations[$i] === $sourceObject->getUuid()) {
                        $relations[$i] = $targetObject->getUuid();
                        $updated       = true;
                        $mergeReport['statistics']['referencesUpdated']++;
                    }
                }

                if ($updated) {
                    $referencingObject->setRelations($relations);
                    $this->objectEntityMapper->update($referencingObject);
                    $updatedReferences[] = [
                        'objectId' => $referencingObject->getUuid(),
                        'title'    => $referencingObject->getTitle() ?? $referencingObject->getUuid(),
                    ];
                }
            }//end foreach

            $mergeReport['actions']['references'] = $updatedReferences;

            // Soft delete source object using the entity's delete method
            $sourceObject->delete($this->userSession, 'Merged into object '.$targetObject->getUuid());
            $this->objectEntityMapper->update($sourceObject);

            // Set success and add merged object to report
            $mergeReport['success']      = true;
            $mergeReport['mergedObject'] = $updatedObject->jsonSerialize();

            // Merge completed successfully
        } catch (\Exception $e) {
            // Handle merge error
            $mergeReport['errors'][] = "Merge failed: ".$e->getMessage();
            $mergeReport['errors'][] = $e->getMessage();
            throw $e;
        }//end try

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
     * @psalm-return   array<string, mixed>
     */
    private function transferObjectFiles(ObjectEntity $sourceObject, ObjectEntity $targetObject): array
    {
        $result = [
            'files'       => [],
            'transferred' => 0,
            'errors'      => [],
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
                    $fileName    = $file->getName();

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
                        'name'    => $fileName,
                        'action'  => 'transferred',
                        'success' => true,
                    ];
                    $result['transferred']++;
                } catch (\Exception $e) {
                    $result['files'][]  = [
                        'name'    => $file->getName(),
                        'action'  => 'transfer_failed',
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ];
                    $result['errors'][] = 'Failed to transfer file '.$file->getName().': '.$e->getMessage();
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to access source files: '.$e->getMessage();
        }//end try

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
     * @psalm-return   array<string, mixed>
     */
    private function deleteObjectFiles(ObjectEntity $sourceObject): array
    {
        $result = [
            'files'   => [],
            'deleted' => 0,
            'errors'  => [],
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
                        'name'    => $fileName,
                        'action'  => 'deleted',
                        'success' => true,
                    ];
                    $result['deleted']++;
                } catch (\Exception $e) {
                    $result['files'][]  = [
                        'name'    => $file->getName(),
                        'action'  => 'delete_failed',
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ];
                    $result['errors'][] = 'Failed to delete file '.$file->getName().': '.$e->getMessage();
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            $result['errors'][] = 'Failed to access source files: '.$e->getMessage();
        }//end try

        return $result;

    }//end deleteObjectFiles()


    /**
     * Handles pre-validation cascading for inversedBy properties
     *
     * This method processes properties with inversedBy configuration BEFORE validation.
     * It creates related objects from nested object data and replaces them with UUIDs
     * so that validation sees UUIDs instead of objects.
     *
     * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
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
            $urlGenerator         = new \ReflectionClass($this->saveHandler);
            $urlGeneratorProperty = $urlGenerator->getProperty('urlGenerator');
            $urlGeneratorProperty->setAccessible(true);
            $urlGeneratorInstance = $urlGeneratorProperty->getValue($this->saveHandler);

            $schemaObject = $schema->getSchemaObject($urlGeneratorInstance);
            $properties   = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
            // Process schema properties for inversedBy relationships
        } catch (Exception $e) {
            // Handle error in schema processing
            return [$object, $uuid];
        }

        // Find properties that have inversedBy configuration
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
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
                        } else if (is_string($item) && $this->isUuid($item)) {
                            // This is already a UUID, keep it
                            $createdUuids[] = $item;
                        }
                    }

                    $object[$propertyName] = $createdUuids;
                }
            }
            // Handle single object properties
            else if (isset($definition['inversedBy']) && !($definition['type'] === 'array')) {
                if (is_array($propertyValue) && !$this->isUuid($propertyValue)) {
                    // This is a nested object, create it first
                    $createdUuid = $this->createRelatedObject($propertyValue, $definition, $uuid);
                    if ($createdUuid) {
                        $object[$propertyName] = $createdUuid;
                    }
                }
            }
        }//end foreach

        return [$object, $uuid];

    }//end handlePreValidationCascading()


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
                uuid: null,
            // Let it generate a new UUID
                folderId: null,
                rbac: true,
            // Use default RBAC for internal cascading operations
                multi: true
            // Use default multitenancy for internal cascading operations
            );

            return $createdObject->getUuid();
        } catch (Exception $e) {
            // Log error but don't expose details
            return null;
        }//end try

    }//end createRelatedObject()


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

    }//end isUuid()


    /**
     * Migrate objects between registers and/or schemas
     *
     * This method migrates multiple objects from one register/schema combination
     * to another register/schema combination with property mapping.
     *
     * @param string|int $sourceRegister The source register ID or slug
     * @param string|int $sourceSchema   The source schema ID or slug
     * @param string|int $targetRegister The target register ID or slug
     * @param string|int $targetSchema   The target schema ID or slug
     * @param array      $objectIds      Array of object IDs to migrate
     * @param array      $mapping        Simple mapping where keys are target properties, values are source properties
     *
     * @return array Migration result with statistics and details
     *
     * @phpstan-return array<string, mixed>
     * @psalm-return   array<string, mixed>
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
            'success'    => false,
            'statistics' => [
                'objectsMigrated'     => 0,
                'objectsFailed'       => 0,
                'propertiesMapped'    => 0,
                'propertiesDiscarded' => 0,
            ],
            'details'    => [],
            'warnings'   => [],
            'errors'     => [],
        ];

        try {
            // Load source and target registers/schemas
            $sourceRegisterEntity = is_string($sourceRegister) || is_int($sourceRegister) ? $this->registerMapper->find($sourceRegister) : $sourceRegister;
            $sourceSchemaEntity   = is_string($sourceSchema) || is_int($sourceSchema) ? $this->schemaMapper->find($sourceSchema) : $sourceSchema;
            $targetRegisterEntity = is_string($targetRegister) || is_int($targetRegister) ? $this->registerMapper->find($targetRegister) : $targetRegister;
            $targetSchemaEntity   = is_string($targetSchema) || is_int($targetSchema) ? $this->schemaMapper->find($targetSchema) : $targetSchema;

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
                $objectId     = $sourceObject->getUuid();
                $objectDetail = [
                    'objectId'    => $objectId,
                    'objectTitle' => null,
                    'success'     => false,
                    'error'       => null,
                ];

                // Remove this object from the remaining list (it was found) - do this BEFORE try-catch
                $remainingObjectIds = array_filter(
                        $remainingObjectIds,
                        function ($id) use ($sourceObject) {
                            return $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId();
                        }
                        );

                try {
                    $objectDetail['objectTitle'] = $sourceObject->getName() ?? $sourceObject->getUuid();

                    // Verify the source object belongs to the expected register/schema (cast to int for comparison)
                    if ((int) $sourceObject->getRegister() !== (int) $sourceRegister
                        || (int) $sourceObject->getSchema() !== (int) $sourceSchema
                    ) {
                        $actualRegister = $sourceObject->getRegister();
                        $actualSchema   = $sourceObject->getSchema();
                        throw new \InvalidArgumentException(
                            "Object {$objectId} does not belong to the specified source register/schema. "."Expected: register='{$sourceRegister}', schema='{$sourceSchema}'. "."Actual: register='{$actualRegister}', schema='{$actualSchema}'"
                        );
                    }

                    // Get source object data (the JSON object property)
                    $sourceData = $sourceObject->getObject();

                    // Map properties according to mapping configuration
                    $mappedData = $this->mapObjectProperties($sourceData, $mapping);
                    $migrationReport['statistics']['propertiesMapped']    += count($mappedData);
                    $migrationReport['statistics']['propertiesDiscarded'] += (count($sourceData) - count($mappedData));

                    // Log the mapping result for debugging
                    $this->logger->debug(
                            'Object properties mapped',
                            [
                                'mappedData' => $mappedData,
                            ]
                            );

                    // Store original files and relations before altering the object
                    $originalFiles     = $sourceObject->getFolder();
                    $originalRelations = $sourceObject->getRelations();

                    // Alter the existing object to migrate it to the target register/schema
                    $sourceObject->setRegister($targetRegisterEntity->getId());

                    $sourceObject->setSchema($targetSchemaEntity->getId());

                    $sourceObject->setObject($mappedData);

                    // Update the object using the mapper
                    $savedObject = $this->objectEntityMapper->update($sourceObject);

                    // Handle file migration (files should already be attached to the object)
                    if ($originalFiles !== null) {
                        // Files are already associated with this object, no migration needed
                    }

                    // Handle relations migration (relations are already on the object)
                    if (!empty($originalRelations)) {
                        // Relations are preserved on the object, no additional migration needed
                    }

                    $objectDetail['success']     = true;
                    $objectDetail['newObjectId'] = $savedObject->getUuid();
                    // Same UUID, but migrated
                    $migrationReport['statistics']['objectsMigrated']++;
                } catch (\Exception $e) {
                    $objectDetail['error'] = $e->getMessage();
                    $migrationReport['statistics']['objectsFailed']++;
                    $migrationReport['errors'][] = "Failed to migrate object {$objectId}: ".$e->getMessage();
                }//end try

                $migrationReport['details'][] = $objectDetail;
            }//end foreach

            // Handle objects that weren't found
            foreach ($remainingObjectIds as $notFoundId) {
                $objectDetail = [
                    'objectId'    => $notFoundId,
                    'objectTitle' => null,
                    'success'     => false,
                    'error'       => "Object with ID {$notFoundId} not found",
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

            throw $e;
        }//end try

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
     * @psalm-return   array<string, mixed>
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
     * @psalm-return   void
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
                    $fileName    = $file->getName();

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
                }//end try
            }//end foreach
        } catch (\Exception $e) {
            // Log error but don't fail the migration
        }//end try

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
     * @psalm-return   void
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
                $updated   = false;

                for ($i = 0; $i < count($relations); $i++) {
                    if ($relations[$i] === $sourceObject->getUuid()) {
                        $relations[$i] = $targetObject->getUuid();
                        $updated       = true;
                    }
                }

                if ($updated) {
                    $referencingObject->setRelations($relations);
                    $this->objectEntityMapper->update($referencingObject);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the migration
        }//end try

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
    private function logSearchTrail(array $query, int $resultCount, int $totalResults, float $executionTime, string $executionType='sync'): void
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
        }

    }//end logSearchTrail()


    private function cleanQuery(array $parameters): array
    {
        $newParameters = [];

        // 1. Handle ordering
        if (isset($parameters['ordering'])) {
            $ordering  = $parameters['ordering'];
            $direction = str_starts_with($ordering, '-') ? 'DESC' : 'ASC';
            $field     = ltrim($ordering, '-');
            $newParameters['_order'] = [$field => $direction];
            unset($parameters['ordering']);
        }

        // 2. Normalize keys: replace '__' with '_'
        $normalized = [];
        foreach ($parameters as $key => $value) {
            $normalized[str_replace('__', '_', $key)] = $value;
        }

        // 3. Process parameters (no nested loops)
        foreach ($normalized as $key => $value) {
            if (preg_match('/^(.*)_(in|gt|lt|gte|lte|isnull)$/', $key, $matches)) {
                [$_, $base, $suffix] = $matches;

                switch ($suffix) {
                    case 'in':
                    case 'gt':
                    case 'lt':
                    case 'gte':
                    case 'lte':
                        $newParameters[$base][$suffix] = $value;
                        break;

                    case 'isnull':
                        $newParameters[$base] = $value === true ? 'IS NULL' : 'IS NOT NULL';
                        break;
                }
            } else {
                $newParameters[$key] = $value;
            }
        }//end foreach

        return $newParameters;

    }//end cleanQuery()


    /**
     * Perform bulk delete operations on objects by UUID
     *
     * This method handles both soft delete and hard delete based on the current state
     * of the objects. If an object has no deleted value set, it performs a soft delete
     * by setting the deleted timestamp. If an object already has a deleted value set,
     * it performs a hard delete by removing the object from the database.
     *
     * @param array $uuids Array of object UUIDs to delete
     * @param bool  $rbac  Whether to apply RBAC filtering
     * @param bool  $multi Whether to apply multi-organization filtering
     *
     * @return array Array of UUIDs of deleted objects
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    public function deleteObjects(array $uuids=[], bool $rbac=true, bool $multi=true): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled
        if ($rbac || $multi) {
            $filteredUuids = $this->filterUuidsForPermissions($uuids, $rbac, $multi);
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk delete operation
        $deletedObjectIds = $this->objectEntityMapper->deleteObjects($filteredUuids);

        return $deletedObjectIds;

    }//end deleteObjects()


    /**
     * Perform bulk publish operations on objects by UUID
     *
     * This method sets the published timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the published timestamp.
     *
     * @param array         $uuids    Array of object UUIDs to publish
     * @param DateTime|bool $datetime Optional datetime for publishing (false to unset)
     * @param bool          $rbac     Whether to apply RBAC filtering
     * @param bool          $multi    Whether to apply multi-organization filtering
     *
     * @return array Array of UUIDs of published objects
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    public function publishObjects(array $uuids=[], \DateTime|bool $datetime=true, bool $rbac=true, bool $multi=true): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled
        if ($rbac || $multi) {
            $filteredUuids = $this->filterUuidsForPermissions($uuids, $rbac, $multi);
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk publish operation
        $publishedObjectIds = $this->objectEntityMapper->publishObjects($filteredUuids, $datetime);

        return $publishedObjectIds;

    }//end publishObjects()


    /**
     * Perform bulk depublish operations on objects by UUID
     *
     * This method sets the depublished timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the depublished timestamp.
     *
     * @param array         $uuids    Array of object UUIDs to depublish
     * @param DateTime|bool $datetime Optional datetime for depublishing (false to unset)
     * @param bool          $rbac     Whether to apply RBAC filtering
     * @param bool          $multi    Whether to apply multi-organization filtering
     *
     * @return array Array of UUIDs of depublished objects
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    public function depublishObjects(array $uuids=[], \DateTime|bool $datetime=true, bool $rbac=true, bool $multi=true): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled
        if ($rbac || $multi) {
            $filteredUuids = $this->filterUuidsForPermissions($uuids, $rbac, $multi);
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk depublish operation
        $depublishedObjectIds = $this->objectEntityMapper->depublishObjects($filteredUuids, $datetime);

        return $depublishedObjectIds;

    }//end depublishObjects()


    /**
     * Filter UUIDs based on RBAC and multi-organization permissions
     *
     * @param array $uuids Array of UUIDs to filter
     * @param bool  $rbac  Whether to apply RBAC filtering
     * @param bool  $multi Whether to apply multi-organization filtering
     *
     * @return array Filtered array of UUIDs
     *
     * @phpstan-param  array<int, string> $uuids
     * @psalm-param    array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    private function filterUuidsForPermissions(array $uuids, bool $rbac, bool $multi): array
    {
        $filteredUuids = [];
        $currentUser   = $this->userSession->getUser();
        $userId        = $currentUser ? $currentUser->getUID() : null;
        $activeOrganisation = $this->getActiveOrganisationForContext();

        // Get objects for permission checking
        $objects = $this->objectEntityMapper->findAll(ids: $uuids, includeDeleted: true);

        foreach ($objects as $object) {
            $objectUuid = $object->getUuid();

            // Check RBAC permissions if enabled
            if ($rbac && $userId !== null) {
                $objectOwner  = $object->getOwner();
                $objectSchema = $object->getSchema();

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);

                        // TODO: Add property-level RBAC check for 'delete' action here
                        // Check if user has permission to delete objects with specific property values
                        if (!$this->hasPermission($schema, 'delete', $userId, $objectOwner, $rbac)) {
                            continue;
                            // Skip this object - no permission
                        }
                    } catch (DoesNotExistException $e) {
                        continue;
                        // Skip this object - schema not found
                    }
                }
            }

            // Check multi-organization permissions if enabled
            if ($multi && $activeOrganisation !== null) {
                $objectOrganisation = $object->getOrganisation();

                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    continue;
                    // Skip this object - different organization
                }
            }

            $filteredUuids[] = $objectUuid;
        }//end foreach

        return $filteredUuids;

    }//end filterUuidsForPermissions()


    /**
     * Detect if we're running in a slow environment (e.g., AC environment)
     *
     * This method uses various heuristics to detect slower environments
     * and enables more aggressive caching and preloading strategies.
     *
     * @return bool True if environment is detected as slow
     *
     * @phpstan-return bool
     * @psalm-return   bool
     */
    private function isSlowEnvironment(): bool
    {
        // Check for environment variables that indicate AC environment
        $isAcEnvironment = (
            getenv('AC_ENVIRONMENT') === 'true' ||
            getenv('SLOW_ENVIRONMENT') === 'true' ||
            strpos($_SERVER['HTTP_HOST'] ?? '', '.ac.') !== false
        );
        
        if ($isAcEnvironment) {
            return true;
        }
        
        // Use static cache to avoid repeated detection overhead
        static $environmentScore = null;
        
        if ($environmentScore === null) {
            $environmentScore = 0;
            
            // Check database response time (simple heuristic)
            $start = microtime(true);
            try {
                $this->objectEntityMapper->countAll([], null, [], null, false, null, null, null, false, false);
                $dbTime = (microtime(true) - $start) * 1000; // Convert to milliseconds
                
                // If a simple count takes more than 50ms, consider it slow
                if ($dbTime > 50) {
                    $environmentScore += 2;
                }
                
                // Additional penalty for very slow responses
                if ($dbTime > 200) {
                    $environmentScore += 3;
                }
            } catch (\Exception $e) {
                // If we can't measure, assume potentially slow
                $environmentScore += 1;
            }
            
            // Check memory constraints (lower memory often indicates constrained environments)
            $memoryLimit = $this->getMemoryLimitInBytes();
            if ($memoryLimit > 0 && $memoryLimit < 536870912) { // Less than 512MB
                $environmentScore += 1;
            }
            
            // Log detection result for monitoring
            $this->logger->debug('Environment performance detection', [
                'score' => $environmentScore,
                'dbTime' => $dbTime ?? 'unknown',
                'memoryLimit' => $memoryLimit,
                'isSlow' => $environmentScore >= 2
            ]);
        }
        
        return $environmentScore >= 2;
        
    }//end isSlowEnvironment()


    /**
     * Convert memory limit string to bytes
     *
     * @return int Memory limit in bytes, or -1 if unlimited
     */
    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return -1; // Unlimited
        }
        
        $value = (int) $memoryLimit;
        $unit = strtolower(substr($memoryLimit, -1));
        
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
        
    }//end getMemoryLimitInBytes()


    /**
     * Render objects in parallel using ReactPHP for optimal performance
     *
     * This method processes large datasets by dividing them into concurrent batches,
     * significantly reducing total rendering time. Uses intelligent batch sizing
     * based on system resources and dataset characteristics.
     *
     * @param array  $objects   Array of ObjectEntity objects to render
     * @param array  $extend    Array of properties to extend
     * @param ?array $filter    Filter configuration
     * @param ?array $fields    Fields configuration 
     * @param ?array $unset     Unset configuration
     * @param ?array $registers Registers context array
     * @param ?array $schemas   Schemas context array  
     * @param bool   $rbac      Whether to apply RBAC checks
     * @param bool   $multi     Whether to apply multitenancy filtering
     *
     * @return array Array of rendered ObjectEntity objects
     *
     * @phpstan-param array<ObjectEntity> $objects
     * @phpstan-param array<string> $extend  
     * @phpstan-param array<string>|null $filter
     * @phpstan-param array<string>|null $fields
     * @phpstan-param array<string>|null $unset
     * @phpstan-param array<int, Register>|null $registers
     * @phpstan-param array<int, Schema>|null $schemas
     * @phpstan-return array<ObjectEntity>
     * 
     * @psalm-param array<ObjectEntity> $objects
     * @psalm-param array<string> $extend
     * @psalm-param array<string>|null $filter  
     * @psalm-param array<string>|null $fields
     * @psalm-param array<string>|null $unset
     * @psalm-param array<int, Register>|null $registers
     * @psalm-param array<int, Schema>|null $schemas
     * @psalm-return array<ObjectEntity>
     */
    private function renderObjectsInParallel(
        array $objects,
        array $extend,
        ?array $filter,
        ?array $fields,
        ?array $unset,
        ?array $registers,
        ?array $schemas,
        bool $rbac,
        bool $multi
    ): array {
        $totalObjects = count($objects);
        
        // Determine optimal batch size based on dataset and resources
        $batchSize = $this->calculateOptimalBatchSize($totalObjects);
        
        $this->logger->debug('Parallel rendering configuration', [
            'totalObjects' => $totalObjects,
            'batchSize' => $batchSize,
            'batchCount' => ceil($totalObjects / $batchSize)
        ]);
        
        // Split objects into batches for parallel processing
        $batches = array_chunk($objects, $batchSize, true);
        $promises = [];
        
        // Create promises for each batch
        foreach ($batches as $batchIndex => $batch) {
            $promises[$batchIndex] = new Promise(
                function ($resolve, $reject) use ($batch, $extend, $filter, $fields, $unset, $registers, $schemas, $rbac, $multi, $batchIndex) {
                    try {
                        $startBatch = microtime(true);
                        $renderedBatch = [];
                        
                        // Render each object in this batch
                        foreach ($batch as $key => $object) {
                            $renderedBatch[$key] = $this->renderHandler->renderEntity(
                                entity: $object,
                                extend: $extend,
                                filter: $filter,
                                fields: $fields,
                                unset: $unset,
                                registers: $registers,
                                schemas: $schemas,
                                rbac: $rbac,
                                multi: $multi
                            );
                        }
                        
                        $batchTime = round((microtime(true) - $startBatch) * 1000, 2);
                        $this->logger->debug('Batch rendering completed', [
                            'batchIndex' => $batchIndex,
                            'objectsInBatch' => count($batch),
                            'executionTime' => $batchTime . 'ms'
                        ]);
                        
                        $resolve($renderedBatch);
                    } catch (\Throwable $e) {
                        $this->logger->error('Batch rendering failed', [
                            'batchIndex' => $batchIndex,
                            'exception' => $e->getMessage()
                        ]);
                        $reject($e);
                    }
                }
            );
        }
        
        // Execute all batches in parallel and merge results
        $results = \React\Async\await(\React\Promise\all($promises));
        
        // Merge all batch results back into a single array, preserving keys
        $renderedObjects = [];
        foreach ($results as $batchResults) {
            $renderedObjects = array_merge($renderedObjects, $batchResults);
        }
        
        return $renderedObjects;
        
    }//end renderObjectsInParallel()


    /**
     * Calculate optimal batch size for parallel processing
     *
     * Determines the best batch size based on system resources, dataset size,
     * and processing characteristics to maximize parallelization benefits
     * while avoiding resource exhaustion.
     *
     * @param int $totalObjects Total number of objects to process
     *
     * @return int Optimal batch size for parallel processing
     *
     * @phpstan-param int $totalObjects
     * @phpstan-return int
     * @psalm-param int $totalObjects  
     * @psalm-return int
     */
    private function calculateOptimalBatchSize(int $totalObjects): int
    {
        // Base batch size calculation based on dataset size
        if ($totalObjects <= 50) {
            return 10; // Small datasets: small batches for quick turnaround
        } elseif ($totalObjects <= 200) {
            return 25; // Medium datasets: balanced batches
        } elseif ($totalObjects <= 500) {
            return 50; // Large datasets: bigger batches for efficiency
        } else {
            return 100; // Very large datasets: maximum efficiency batches
        }
        
        // Note: PHP's ReactPHP doesn't provide true parallelism (due to GIL-like behavior)
        // but it does provide excellent concurrency for I/O bound operations
        // which is what we have with database queries and object processing
        
    }//end calculateOptimalBatchSize()


    /**
     * Execute chunked search for very large datasets to optimize memory and performance
     *
     * This method splits very large queries into smaller chunks to prevent memory
     * exhaustion and improve overall query performance through better database
     * resource utilization.
     *
     * @param array       $query                 The search query array
     * @param string|null $activeOrganisationUuid Active organisation UUID for filtering
     * @param bool        $rbac                  Whether to apply RBAC checks
     * @param bool        $multi                 Whether to apply multitenancy filtering  
     * @param int         $totalLimit            Total number of records requested
     *
     * @return array Array of ObjectEntity objects
     *
     * @phpstan-param array<string, mixed> $query
     * @phpstan-return array<ObjectEntity>
     * @psalm-param array<string, mixed> $query
     * @psalm-return array<ObjectEntity>
     */
    private function executeChunkedSearch(
        array $query,
        ?string $activeOrganisationUuid,
        bool $rbac,
        bool $multi,
        int $totalLimit
    ): array {
        $chunkSize = 100; // Process in chunks of 100 for optimal performance
        $allResults = [];
        $offset = $query['_offset'] ?? 0;
        $processed = 0;
        
        $this->logger->debug('Starting chunked search execution', [
            'totalLimit' => $totalLimit,
            'chunkSize' => $chunkSize,
            'startOffset' => $offset,
            'expectedChunks' => ceil($totalLimit / $chunkSize)
        ]);
        
        while ($processed < $totalLimit) {
            $currentChunkSize = min($chunkSize, $totalLimit - $processed);
            $currentOffset = $offset + $processed;
            
            // Create chunk-specific query
            $chunkQuery = array_merge($query, [
                '_limit' => $currentChunkSize,
                '_offset' => $currentOffset
            ]);
            
            $this->logger->debug('Processing search chunk', [
                'chunkNumber' => floor($processed / $chunkSize) + 1,
                'chunkSize' => $currentChunkSize,
                'chunkOffset' => $currentOffset
            ]);
            
            $startChunk = microtime(true);
            
            // Execute chunk query
            $chunkResults = $this->objectEntityMapper->searchObjects(
                $chunkQuery,
                $activeOrganisationUuid,
                $rbac,
                $multi
            );
            
            $chunkTime = round((microtime(true) - $startChunk) * 1000, 2);
            $this->logger->debug('Search chunk completed', [
                'chunkResults' => count($chunkResults),
                'chunkTime' => $chunkTime . 'ms',
                'totalProcessed' => $processed + count($chunkResults)
            ]);
            
            // If no results returned, we've reached the end
            if (empty($chunkResults)) {
                break;
            }
            
            // Add results to collection
            $allResults = array_merge($allResults, $chunkResults);
            $processed += count($chunkResults);
            
            // If we got fewer results than requested, we've reached the end
            if (count($chunkResults) < $currentChunkSize) {
                break;
            }
        }
        
        $this->logger->debug('Chunked search completed', [
            'totalResults' => count($allResults),
            'totalChunks' => floor($processed / $chunkSize) + (($processed % $chunkSize) > 0 ? 1 : 0),
            'requestedLimit' => $totalLimit
        ]);
        
        return $allResults;
        
    }//end executeChunkedSearch()


    /**
     * Extract ALL relationship IDs from ALL objects for ultra-aggressive preloading
     *
     * This scans through all objects and collects every single relationship ID
     * that will be needed for extending, allowing us to load everything in one query.
     *
     * @param array $objects Array of ObjectEntity objects to scan
     * @param array $extend  Array of properties to extend
     *
     * @return array Array of unique relationship IDs found across all objects
     *
     * @phpstan-param array<ObjectEntity> $objects
     * @phpstan-param array<string> $extend
     * @phpstan-return array<string>
     * @psalm-param array<ObjectEntity> $objects
     * @psalm-param array<string> $extend  
     * @psalm-return array<string>
     */
    private function extractAllRelationshipIds(array $objects, array $extend): array
    {
        $allIds = [];
        
        foreach ($objects as $object) {
            $objectData = $object->getObject();
            
            foreach ($extend as $extendProperty) {
                if (isset($objectData[$extendProperty])) {
                    $value = $objectData[$extendProperty];
                    
                    if (is_array($value)) {
                        // Handle array of relationship IDs
                        foreach ($value as $id) {
                            if (!empty($id) && is_string($id)) {
                                $allIds[] = $id;
                            }
                        }
                    } elseif (is_string($value) && !empty($value)) {
                        // Handle single relationship ID
                        $allIds[] = $value;
                    }
                }
            }
        }
        
        // Remove duplicates and return unique IDs
        return array_unique($allIds);
        
    }//end extractAllRelationshipIds()


    /**
     * Bulk load all relationship objects in a single optimized query
     *
     * This method loads all related objects needed for extending in ONE database query,
     * providing maximum performance for large datasets with complex relationships.
     *
     * @param array $relationshipIds Array of all relationship IDs to load
     *
     * @return array Array of objects indexed by ID/UUID for instant lookup
     *
     * @phpstan-param array<string> $relationshipIds
     * @phpstan-return array<string, ObjectEntity>
     * @psalm-param array<string> $relationshipIds
     * @psalm-return array<string, ObjectEntity>
     */
    private function bulkLoadRelationships(array $relationshipIds): array
    {
        if (empty($relationshipIds)) {
            return [];
        }
        
        // Use the optimized findMultiple method for bulk loading
        $relatedObjects = $this->objectEntityMapper->findMultiple($relationshipIds);
        
        // Create lookup map indexed by both ID and UUID for fast access
        $lookupMap = [];
        foreach ($relatedObjects as $object) {
            if ($object instanceof ObjectEntity) {
                // Index by numeric ID
                if ($object->getId()) {
                    $lookupMap[(string)$object->getId()] = $object;
                }
                
                // Index by UUID
                if ($object->getUuid()) {
                    $lookupMap[$object->getUuid()] = $object;
                }
                
                // Index by slug if available
                if ($object->getSlug()) {
                    $lookupMap[$object->getSlug()] = $object;
                }
            }
        }
        
        return $lookupMap;
        
    }//end bulkLoadRelationships()


}//end class
