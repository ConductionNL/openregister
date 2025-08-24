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
     * @param DeleteObject        $deleteHandler       Handler for object deletion.
     * @param GetObject           $getHandler          Handler for object retrieval.
     * @param RenderObject        $renderHandler       Handler for object rendering.
     * @param SaveObject          $saveHandler         Handler for object saving.
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
        private readonly SearchTrailService $searchTrailService,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly OrganisationService $organisationService
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
     * TODO: Add property-level RBAC validation here
     * Before saving object data, check if user has permission to create/update specific properties
     * based on property-level authorization arrays in the schema.
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

        // Use the new searchObjects method from ObjectEntityMapper with organization context
        $result = $this->objectEntityMapper->searchObjects($query, $activeOrganisationUuid, $rbac, $multi);

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

        // Render each object through the render handler
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
                        $result = $this->searchObjects($paginatedQuery);
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
     * Save multiple objects to the database using bulk operations
     *
     * This method provides a high-performance bulk save operation that processes
     * multiple objects in a single database transaction using optimized SQL statements.
     * Objects are expected to be in serialized format and will be enriched with
     * missing metadata (owner, organisation, created, updated) if not present.
     *
     * Optional validation can be enabled to validate objects against their schema definitions
     * before saving. Invalid objects will be excluded from the save operation and returned
     * in the 'invalid' array with their validation errors in the 'errors' array.
     *
     * Optional event dispatching can be enabled to trigger object lifecycle events during
     * the bulk save operation. This may impact performance but provides full event support.
     * NOTE: Event dispatching is reserved for future implementation and currently has no effect.
     *
     * @param array                    $objects    Array of objects in serialized format (each object is an array representation of ObjectEntity)
     * @param Register|string|int|null $register   Optional register filter for validation
     * @param Schema|string|int|null   $schema     Optional schema filter for validation
     * @param bool                     $rbac       Whether to apply RBAC filtering
     * @param bool                     $multi      Whether to apply multi-organization filtering
     * @param bool                     $validation Whether to validate objects against schema definitions before saving (default: false)
     * @param bool                     $events     Whether to dispatch object lifecycle events during bulk operations (default: false)
     *
     * @throws \InvalidArgumentException If required fields are missing from any object
     * @throws \OCP\DB\Exception If a database error occurs during bulk operations
     *
     * @return array Array containing bulk operation results:
     *               - 'saved': Array of newly created ObjectEntity instances
     *               - 'updated': Array of updated ObjectEntity instances
     *               - 'skipped': Array of objects that were skipped (reserved for future implementation)
     *               - 'invalid': Array of objects that failed validation (when validation=true)
     *               - 'errors': Array of validation error messages indexed by object index
     *               - 'statistics': Array with counts (totalProcessed, saved, updated, skipped, invalid)
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<string, mixed>
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
        $startTime    = microtime(true);
        $totalObjects = count($objects);

        error_log('[ObjectService] Starting saveObjects: '.$totalObjects.' objects');

        // Initialize result arrays for different outcomes
        $result = [
            'saved'      => [],
            'updated'    => [],
            'skipped'    => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'totalProcessed' => $totalObjects,
                'saved'          => 0,
                'updated'        => 0,
                'skipped'        => 0,
                'invalid'        => 0,
            ],
        ];

        if (empty($objects)) {
            return $result;
        }

        // Set register and schema context if provided
        if ($register !== null) {
            $this->setRegister($register);
        }

        if ($schema !== null) {
            $this->setSchema($schema);
        }

        // Process objects through SaveObject handler for proper relation handling
        // This ensures that inversedBy relationships and writeBack operations are handled correctly
        $processedObjects = [];
        try {
            $processedObjects = $this->prepareObjectsForBulkSave($objects);
        } catch (\Exception $e) {
            error_log('[ObjectService] Error preparing objects for bulk save: '.$e->getMessage());
            $result['errors'][] = [
                'error' => 'Failed to prepare objects for bulk save: '.$e->getMessage(),
                'type'  => 'BulkPreparationException',
            ];
            return $result;
        }

        // Check if we have any processed objects
        if (empty($processedObjects)) {
            error_log('[ObjectService] No objects were successfully prepared for bulk save');
            $result['errors'][] = [
                'error' => 'No objects were successfully prepared for bulk save',
                'type'  => 'NoObjectsPreparedException',
            ];
            return $result;
        }

        // Log how many objects were successfully prepared
        error_log('[ObjectService] Successfully prepared '.count($processedObjects).' out of '.count($objects).' objects for bulk save');

        // Update statistics to reflect actual processed objects
        $result['statistics']['totalProcessed'] = count($processedObjects);

        // Process objects in chunks for optimal performance
        $chunkSize = $this->calculateOptimalChunkSize(count($processedObjects));
        error_log('[ObjectService] Using chunk size: '.$chunkSize.' for '.count($processedObjects).' processed objects');

        // For very large datasets, try concurrent processing if ReactPHP is available
        if (count($processedObjects) > 1000 && class_exists('\React\Promise\Promise')) {
            error_log('[ObjectService] Attempting concurrent processing for large dataset');
            try {
                $concurrentResult = $this->processObjectsConcurrently($processedObjects, $chunkSize, $rbac, $multi, $validation, $events);
                if ($concurrentResult !== null) {
                    $totalTime    = microtime(true) - $startTime;
                    $overallSpeed = count($processedObjects) / max($totalTime, 0.001);
                    error_log('[ObjectService] CONCURRENT processing completed: '.count($processedObjects).' objects in '.round($totalTime, 3).'s ('.round($overallSpeed, 1).' obj/sec)');

                    // Add preparation statistics to concurrent result
                    $concurrentResult['statistics']['totalProcessed'] = count($processedObjects);
                    $concurrentResult['statistics']['prepared']       = count($processedObjects);

                    return $concurrentResult;
                }
            } catch (\Exception $e) {
                error_log('[ObjectService] Concurrent processing failed, falling back to sequential: '.$e->getMessage());
            }
        }

        // Sequential processing with chunks
        $chunks     = array_chunk($processedObjects, $chunkSize);
        $chunkCount = count($chunks);

        foreach ($chunks as $chunkIndex => $objectsChunk) {
            $chunkStart = microtime(true);
            error_log('[ObjectService] Processing chunk '.($chunkIndex + 1).'/'.$chunkCount.' ('.count($objectsChunk).' objects)');

            $chunkResult = $this->processObjectsChunk($objectsChunk, $rbac, $multi, $validation, $events);

            // Merge chunk results
            $result['saved']   = array_merge($result['saved'], $chunkResult['saved']);
            $result['updated'] = array_merge($result['updated'], $chunkResult['updated']);
            $result['invalid'] = array_merge($result['invalid'], $chunkResult['invalid']);
            $result['errors']  = array_merge($result['errors'], $chunkResult['errors']);

            $result['statistics']['saved']   += $chunkResult['statistics']['saved'];
            $result['statistics']['updated'] += $chunkResult['statistics']['updated'];
            $result['statistics']['invalid'] += $chunkResult['statistics']['invalid'];

            $chunkTime  = microtime(true) - $chunkStart;
            $chunkSpeed = count($objectsChunk) / max($chunkTime, 0.001);
            error_log('[ObjectService] Chunk '.($chunkIndex + 1).' completed: '.count($objectsChunk).' objects in '.round($chunkTime, 3).'s ('.round($chunkSpeed, 1).' obj/sec)');
        }

        $totalTime    = microtime(true) - $startTime;
        $overallSpeed = count($processedObjects) / max($totalTime, 0.001);

        error_log('[ObjectService] saveObjects completed: '.count($processedObjects).' objects in '.round($totalTime, 3).'s ('.round($overallSpeed, 1).' obj/sec)');

        return $result;

    }//end saveObjects()


    /**
     * Calculate optimal chunk size based on total objects for internal processing
     *
     * @param int $totalObjects Total number of objects to process
     *
     * @return int Optimal chunk size
     */
    private function calculateOptimalChunkSize(int $totalObjects): int
    {
        // Balanced chunk sizes for optimal performance
        if ($totalObjects <= 100) {
            return $totalObjects;
            // Process all at once for small sets
        } else if ($totalObjects <= 500) {
            return 250;
            // Medium chunks for medium sets
        } else if ($totalObjects <= 2000) {
            return 500;
            // Large chunks for large sets
        } else if ($totalObjects <= 5000) {
            return 1000;
            // Very large chunks for very large sets
        } else {
            return 2000;
            // Large chunks for huge datasets
        }

    }//end calculateOptimalChunkSize()


    /**
     * Process a chunk of objects with performance optimizations
     *
     * @param array $objects    Array of objects to process
     * @param bool  $rbac       Apply RBAC filtering
     * @param bool  $multi      Apply multi-tenancy filtering
     * @param bool  $validation Apply schema validation
     * @param bool  $events     Dispatch events
     *
     * @return array Processing result for this chunk
     */
    private function processObjectsChunk(array $objects, bool $rbac, bool $multi, bool $validation, bool $events): array
    {
        $now = new \DateTime();

        $result = [
            'saved'      => [],
            'updated'    => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved'   => 0,
                'updated' => 0,
                'invalid' => 0,
            ],
        ];

        // Apply RBAC and multi-organization filtering if enabled
        if ($rbac || $multi) {
            // @todo: Uncomment this when we have a way to check permissions
            // $objects = $this->filterObjectsForPermissions($objects, $rbac, $multi);
        }

        // Validate that all objects have required fields in their @self section
        try {
            $this->validateRequiredFields($objects);
        } catch (\InvalidArgumentException $e) {
            $result['errors'][] = $e->getMessage();
            return $result;
        }

        // Objects are already prepared by prepareObjectsForBulkSave
        $validObjects = $objects;

        // Validate objects against schema if validation is enabled
        if ($validation === true) {
            $validObjects = $this->validateObjectsAgainstSchema($objects, $result);
        }

        if (empty($validObjects)) {
            return $result;
        }

        // Objects are already prepared, use them directly
        $preparedObjects = $validObjects;

        // Transform prepared objects from serialized format to database format
        $transformedObjects = $this->transformObjectsToDatabaseFormat($preparedObjects);

        // Extract IDs and find existing objects
        $objectIds       = $this->extractObjectIds($transformedObjects);
        $existingObjects = $this->findExistingObjects($objectIds);

        // Separate into insert and update arrays
        $insertObjects = [];
        $updateObjects = [];

        foreach ($transformedObjects as $transformedObject) {
            $objectId = $transformedObject['uuid'] ?? $transformedObject['id'] ?? null;

            if ($objectId !== null && isset($existingObjects[$objectId])) {
                $mergedObject = $this->mergeObjectData($existingObjects[$objectId], $transformedObject);
                $mergedObject->setUpdated($now->format('Y-m-d H:i:s'));
                $updateObjects[] = $mergedObject;
            } else {
                $transformedObject['created'] = $now->format('Y-m-d H:i:s');
                $transformedObject['updated'] = $now->format('Y-m-d H:i:s');
                $insertObjects[] = $transformedObject;
            }
        }

        // Use the mapper's bulk save operation
        $savedObjectIds = $this->objectEntityMapper->saveObjects($insertObjects, $updateObjects);

        error_log('[ObjectService] Bulk save completed. Insert objects: '.count($insertObjects).', Update objects: '.count($updateObjects).', Saved IDs: '.count($savedObjectIds));

        // Fetch saved objects to return in result
        if (!empty($savedObjectIds)) {
            $savedObjects = $this->objectEntityMapper->findAll(ids: $savedObjectIds, includeDeleted: true);

            // Categorize saved objects
            foreach ($savedObjects as $savedObject) {
                $objectId = $savedObject->getUuid();

                if (isset($existingObjects[$objectId])) {
                    $result['updated'][] = $savedObject;
                    $result['statistics']['updated']++;
                } else {
                    $result['saved'][] = $savedObject;
                    $result['statistics']['saved']++;
                }
            }

            // Handle post-save writeBack operations for inverse relations
            if (!empty($savedObjects)) {
                // Build schema cache from saved objects
                $schemaCache = [];
                foreach ($savedObjects as $savedObject) {
                    $schemaId = $savedObject->getSchema();
                    if (!isset($schemaCache[$schemaId])) {
                        try {
                            $schemaCache[$schemaId] = $this->schemaMapper->find($schemaId);
                        } catch (\Exception $e) {
                            // Continue without schema if not found
                        }
                    }
                }

                error_log('[ObjectService] Calling handlePostSaveInverseRelations with '.count($savedObjects).' objects');
                $this->handlePostSaveInverseRelations($savedObjects, $schemaCache);

                // Hydrate metadata fields for all saved objects
                error_log('[ObjectService] Hydrating metadata fields for '.count($savedObjects).' objects');
                $this->hydrateBulkObjectMetadata($savedObjects, $schemaCache);

                // Save hydrated objects back to database to persist metadata changes
                $this->persistBulkMetadataChanges($savedObjects);
            }
        } else {
            error_log('[ObjectService] No saved object IDs returned from bulk save operation');
        }//end if

        return $result;

    }//end processObjectsChunk()


    /**
     * Hydrate metadata fields for multiple objects in bulk operation
     *
     * This method efficiently processes metadata hydration for all saved objects using
     * the pre-loaded schema cache to avoid additional database queries. It applies the
     * same metadata mapping logic as individual saves (objectNameField, objectDescriptionField, 
     * objectSummaryField, objectImageField) but optimized for batch processing.
     *
     * @param array $savedObjects Array of ObjectEntity objects that were saved
     * @param array $schemaCache  Pre-loaded schema cache indexed by schema ID
     *
     * @return void
     *
     * @psalm-param   array<int, ObjectEntity> $savedObjects
     * @phpstan-param array<int, ObjectEntity> $savedObjects  
     * @psalm-param   array<int, Schema> $schemaCache
     * @phpstan-param array<int, Schema> $schemaCache
     * @psalm-return   void
     * @phpstan-return void
     */
    private function hydrateBulkObjectMetadata(array $savedObjects, array $schemaCache): void
    {
        foreach ($savedObjects as $savedObject) {
            $schemaId = $savedObject->getSchema();
            
            // Skip if schema not in cache
            if (!isset($schemaCache[$schemaId])) {
                continue;
            }
            
            $schema = $schemaCache[$schemaId];
            
            // Use the existing SaveObject handler's hydration method
            try {
                $this->saveHandler->hydrateObjectMetadata($savedObject, $schema);
            } catch (\Exception $e) {
                // Continue without hydration if it fails - don't break bulk save
                error_log('[ObjectService] Failed to hydrate metadata for object '.$savedObject->getUuid().': '.$e->getMessage());
            }
        }
    }//end hydrateBulkObjectMetadata()


    /**
     * Persist metadata changes to database after bulk hydration
     *
     * This method updates the hydrated objects in the database to ensure metadata
     * changes are persisted. It only updates the metadata fields (name, description, 
     * summary, image) without affecting the object data to minimize performance impact.
     *
     * @param array $savedObjects Array of ObjectEntity objects with hydrated metadata
     *
     * @return void
     *
     * @psalm-param   array<int, ObjectEntity> $savedObjects
     * @phpstan-param array<int, ObjectEntity> $savedObjects
     * @psalm-return   void
     * @phpstan-return void
     */
    private function persistBulkMetadataChanges(array $savedObjects): void
    {
        if (empty($savedObjects)) {
            return;
        }

        error_log('[ObjectService] Persisting metadata changes for '.count($savedObjects).' objects');

        try {
            // Group objects into batches for efficient database operations
            $batchSize = 50; // Process in smaller batches to avoid memory/query limits
            $batches = array_chunk($savedObjects, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                error_log('[ObjectService] Processing metadata persistence batch '.($batchIndex + 1).'/'.count($batches).' ('.count($batch).' objects)');
                
                // Use the mapper to update only metadata fields efficiently
                foreach ($batch as $savedObject) {
                    $this->objectEntityMapper->update($savedObject, false);
                }
            }

            error_log('[ObjectService] Successfully persisted metadata changes for '.count($savedObjects).' objects');
        } catch (\Exception $e) {
            error_log('[ObjectService] Error persisting metadata changes: '.$e->getMessage());
            // Don't throw - metadata persistence failure shouldn't break bulk save
        }
    }//end persistBulkMetadataChanges()


    /**
     * Concurrent processing using ReactPHP for large datasets
     *
     * @param array $objects    All objects to process
     * @param int   $chunkSize  Chunk size for parallel processing
     * @param bool  $rbac       Apply RBAC filtering
     * @param bool  $multi      Apply multi-tenancy filtering
     * @param bool  $validation Apply schema validation
     * @param bool  $events     Dispatch events
     *
     * @return array|null Processing result or null if concurrent processing fails
     */
    private function processObjectsConcurrently(array $objects, int $chunkSize, bool $rbac, bool $multi, bool $validation, bool $events): ?array
    {
        // Only use concurrent processing for React-enabled environments
        if (!class_exists('\React\Promise\Promise')) {
            return null;
        }

        try {
            $chunks   = array_chunk($objects, $chunkSize);
            $promises = [];

            error_log('[ObjectService] CONCURRENT: Processing '.count($chunks).' chunks concurrently');

            foreach ($chunks as $chunkIndex => $chunk) {
                $promises[] = \React\Async\async(
            function () use ($chunk, $rbac, $multi, $validation, $events, $chunkIndex) {
                    error_log('[ObjectService] CONCURRENT: Starting chunk '.($chunkIndex + 1));
                    $result = $this->processObjectsChunk($chunk, $rbac, $multi, $validation, $events);
                    error_log('[ObjectService] CONCURRENT: Completed chunk '.($chunkIndex + 1).' ('.count($chunk).' objects)');
                    return $result;
            }
                )();
            }

            // Wait for all chunks to complete
            $chunkResults = \React\Async\await(\React\Promise\all($promises));

            // Merge all results
            $finalResult = [
                'saved'      => [],
                'updated'    => [],
                'invalid'    => [],
                'errors'     => [],
                'statistics' => ['saved' => 0, 'updated' => 0, 'invalid' => 0],
            ];

            foreach ($chunkResults as $chunkResult) {
                $finalResult['saved']   = array_merge($finalResult['saved'], $chunkResult['saved']);
                $finalResult['updated'] = array_merge($finalResult['updated'], $chunkResult['updated']);
                $finalResult['invalid'] = array_merge($finalResult['invalid'], $chunkResult['invalid']);
                $finalResult['errors']  = array_merge($finalResult['errors'], $chunkResult['errors']);

                $finalResult['statistics']['saved']   += $chunkResult['statistics']['saved'];
                $finalResult['statistics']['updated'] += $chunkResult['statistics']['updated'];
                $finalResult['statistics']['invalid'] += $chunkResult['statistics']['invalid'];
            }

            $finalResult['statistics']['totalProcessed'] = count($objects);

            // Add preparation statistics to the final result
            $finalResult['statistics']['prepared'] = count($objects);
            return $finalResult;
        } catch (\Exception $e) {
            error_log('[ObjectService] CONCURRENT processing error: '.$e->getMessage());
            return null;
        }//end try

    }//end processObjectsConcurrently()


    /**
     * Prepares objects for bulk save with proper relation handling.
     *
     * This method ensures that objects are properly prepared for bulk saving,
     * including proper handling of inversedBy relationships through the SaveObject handler.
     *
     * @param array $objects Array of objects in serialized format
     *
     * @return array Array of prepared objects
     */
    private function prepareObjectsForBulkSave(array $objects): array
    {
        $startTime   = microtime(true);
        $objectCount = count($objects);

        error_log('[ObjectService] Starting bulk preparation for '.$objectCount.' objects');

        // Early return for empty arrays
        if (empty($objects)) {
            return [];
        }

        $preparedObjects = [];
        $schemaCache     = [];

        // Pre-process objects for inversedBy relationships
        foreach ($objects as $index => $object) {
            try {
                $selfData = $object['@self'] ?? [];
                $schemaId = $selfData['schema'] ?? null;

                if (!$schemaId) {
                    $preparedObjects[$index] = $object;
                    continue;
                }

                // Cache schemas to avoid repeated database calls
                if (!isset($schemaCache[$schemaId])) {
                    try {
                        $schemaCache[$schemaId] = $this->schemaMapper->find($schemaId);
                    } catch (\Exception $e) {
                        $preparedObjects[$index] = $object;
                        continue;
                    }
                }

                $schema = $schemaCache[$schemaId];

                // Generate UUID if not present
                if (!isset($selfData['id']) || empty($selfData['id'])) {
                    $selfData['id']  = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
                    $object['@self'] = $selfData;
                }

                // Handle pre-validation cascading for inversedBy properties
                [$processedObject, $uuid] = $this->handlePreValidationCascading($object, $schema, $selfData['id']);

                $preparedObjects[$index] = $processedObject;
            } catch (\Exception $e) {
                error_log('[ObjectService] Error preparing object at index '.$index.': '.$e->getMessage());
                $preparedObjects[$index] = $object;
                // Continue with original object
            }//end try
        }//end foreach

        // Handle bulk inverse relations within the batch
        $this->handleBulkInverseRelations($preparedObjects, $schemaCache);

        // Performance logging
        $endTime      = microtime(true);
        $duration     = round(($endTime - $startTime) * 1000, 2);
        $successCount = count($preparedObjects);
        $failureCount = $objectCount - $successCount;

        error_log('[ObjectService] Bulk preparation completed: '.$successCount.' success, '.$failureCount.' failed in '.$duration.'ms');

        return array_values($preparedObjects);

    }//end prepareObjectsForBulkSave()


    /**
     * Handle inverse relations for all objects in batch for optimal performance
     *
     * @param array &$preparedObjects Prepared objects to process (indexed by original position)
     * @param array $schemaCache      Cached schemas
     *
     * @return void
     */
    private function handleBulkInverseRelations(array &$preparedObjects, array $schemaCache): void
    {
        $inverseRelationMap = [];
        $processedCount     = 0;

        // Build inverse relation map by scanning all objects
        foreach ($preparedObjects as $index => $object) {
            $selfData   = $object['@self'] ?? [];
            $schemaId   = $selfData['schema'] ?? null;
            $objectUuid = $selfData['id'] ?? null;

            if (!$schemaId || !$objectUuid || !isset($schemaCache[$schemaId])) {
                continue;
            }

            $schema           = $schemaCache[$schemaId];
            $schemaProperties = $schema->getProperties();

            // Scan each property for inverse relations
            foreach ($object as $property => $value) {
                if ($property === '@self' || !isset($schemaProperties[$property])) {
                    continue;
                }

                $propertyConfig = $schemaProperties[$property];
                $items          = $propertyConfig['items'] ?? [];

                // Check for inversedBy at property level (single object relations)
                $inversedBy = $propertyConfig['inversedBy'] ?? null;
                $writeBack  = $propertyConfig['writeBack'] ?? false;

                // Check for inversedBy in array items (array of object relations)
                if (!$inversedBy && isset($items['inversedBy'])) {
                    $inversedBy = $items['inversedBy'];
                    $writeBack  = $items['writeBack'] ?? false;
                }

                // Process if this property has inverse relations (writeBack not required for bulk)
                if ($inversedBy) {
                    // Handle single object relations
                    if (!is_array($value) && is_string($value) && \Symfony\Component\Uid\Uuid::isValid($value)) {
                        // Single UUID relation
                        if (!isset($inverseRelationMap[$value])) {
                            $inverseRelationMap[$value] = [];
                        }

                        if (!isset($inverseRelationMap[$value][$inversedBy])) {
                            $inverseRelationMap[$value][$inversedBy] = [];
                        }

                        $inverseRelationMap[$value][$inversedBy][] = $objectUuid;
                        $processedCount++;
                    }
                    // Handle array of object relations
                    else if (is_array($value)) {
                        foreach ($value as $relatedUuid) {
                            if (is_string($relatedUuid) && \Symfony\Component\Uid\Uuid::isValid($relatedUuid)) {
                                // Map: target UUID -> property name -> source UUIDs
                                if (!isset($inverseRelationMap[$relatedUuid])) {
                                    $inverseRelationMap[$relatedUuid] = [];
                                }

                                if (!isset($inverseRelationMap[$relatedUuid][$inversedBy])) {
                                    $inverseRelationMap[$relatedUuid][$inversedBy] = [];
                                }

                                $inverseRelationMap[$relatedUuid][$inversedBy][] = $objectUuid;
                                $processedCount++;
                            }
                        }
                    }
                }//end if
            }//end foreach
        }//end foreach

        error_log('[ObjectService] Found '.$processedCount.' inverse relations to process for '.count($inverseRelationMap).' target objects');

        // Apply inverse relations back to objects in the current batch
        $appliedCount = 0;
        foreach ($preparedObjects as $index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $objectUuid = $selfData['id'] ?? null;

            if ($objectUuid && isset($inverseRelationMap[$objectUuid])) {
                foreach ($inverseRelationMap[$objectUuid] as $property => $relatedUuids) {
                    // Merge with existing values if any, ensuring uniqueness
                    $existingValues = $object[$property] ?? [];
                    if (!is_array($existingValues)) {
                        $existingValues = [];
                    }

                    $object[$property] = array_values(array_unique(array_merge($existingValues, $relatedUuids)));
                    $appliedCount++;
                }
            }
        }

        error_log('[ObjectService] Applied '.$appliedCount.' inverse relation updates');

    }//end handleBulkInverseRelations()


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
        error_log('[ObjectService] handlePostSaveInverseRelations started with '.count($savedObjects).' objects');
        $writeBackCount = 0;

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

                            // Save the updated source object
                            $this->objectEntityMapper->update($savedObject);
                            error_log('[ObjectService] Updated source object '.$savedObject->getUuid().' property '.$property.' with writeBack value');
                        }
                    } catch (\Exception $e) {
                        error_log('[ObjectService] WriteBack failed for object '.$savedObject->getUuid().': '.$e->getMessage());
                    }
                }//end if
            }//end foreach
        }//end foreach

        error_log('[ObjectService] Processed '.$writeBackCount.' writeBack operations');
        error_log('[ObjectService] handlePostSaveInverseRelations completed');

    }//end handlePostSaveInverseRelations()


    /**
     * Validate objects against their schema definitions
     *
     * This method validates each object against its schema definition if validation is enabled.
     * Objects that fail validation are moved to the 'invalid' array in the result, and their
     * validation errors are added to the 'errors' array. Only valid objects are returned.
     *
     * The validation uses the same logic as individual object validation:
     * - Checks if schema has hard validation enabled
     * - Uses ValidateObject handler to validate object data against schema
     * - Generates meaningful error messages for failed validation
     *
     * @param array $objects Array of objects in serialized format to validate
     * @param array &$result Reference to result array to populate with invalid objects and errors
     *
     * @return array Array of objects that passed validation
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return   array<int, array<string, mixed>>
     */
    private function validateObjectsAgainstSchema(array $objects, array &$result): array
    {
        $validObjects = [];
        $schemaCache  = [];
        // Cache schemas to avoid repeated database lookups
        foreach ($objects as $index => $object) {
            try {
                $self     = $object['@self'] ?? [];
                $schemaId = $self['schema'] ?? null;

                if ($schemaId === null) {
                    // This should not happen due to validateRequiredFields, but handle gracefully
                    $result['invalid'][] = [
                        'object' => $object,
                        'error'  => 'Object missing schema ID in @self section',
                        'index'  => $index,
                        'type'   => 'ValidationException',
                    ];
                    $result['statistics']['invalid']++;
                    continue;
                }

                // Get schema from cache or load it
                if (!isset($schemaCache[$schemaId])) {
                    try {
                        $schemaCache[$schemaId] = $this->schemaMapper->find($schemaId);
                    } catch (\Exception $e) {
                        $result['invalid'][] = [
                            'object' => $object,
                            'error'  => "Schema with ID '{$schemaId}' not found: ".$e->getMessage(),
                            'index'  => $index,
                            'type'   => 'ValidationException',
                        ];
                        $result['statistics']['invalid']++;
                        continue;
                    }
                }

                $schema = $schemaCache[$schemaId];

                // Only validate if schema has hard validation enabled
                // This follows the same logic as in the regular saveObject method
                if ($schema->getHardValidation() === true) {
                    // Extract object data (without @self) for validation
                    $objectData = $object;
                    unset($objectData['@self']);
                    // Remove @self section for validation
                    // Validate the object against the schema
                    $validationResult = $this->validateHandler->validateObject($objectData, $schema);

                    if ($validationResult->isValid() === false) {
                        // Object failed validation - add to invalid array with error details
                        $meaningfulMessage   = $this->validateHandler->generateErrorMessage($validationResult);
                        $result['invalid'][] = [
                            'object' => $object,
                            'error'  => $meaningfulMessage,
                            'index'  => $index,
                            'type'   => 'ValidationException',
                        ];
                        $result['statistics']['invalid']++;
                        continue;
                    }
                }//end if

                // Object passed validation (or schema doesn't require validation)
                $validObjects[] = $object;
            } catch (\Exception $e) {
                // Catch any unexpected errors during validation
                $result['invalid'][] = [
                    'object' => $object,
                    'error'  => 'Validation error: '.$e->getMessage(),
                    'index'  => $index,
                    'type'   => 'ValidationException',
                ];
                $result['statistics']['invalid']++;
            }//end try
        }//end foreach

        return $validObjects;

    }//end validateObjectsAgainstSchema()


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
     * Transform objects from serialized format to database format
     *
     * This method converts objects from the serialized format (with @self section)
     * to the database format where @self data is moved to root level and the
     * object data is stored in the 'object' property.
     *
     * @param array $objects Array of objects in serialized format
     *
     * @return array Array of objects in database format
     *
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-return array<int, array<string, mixed>>
     * @psalm-return   array<int, array<string, mixed>>
     */
    private function transformObjectsToDatabaseFormat(array $objects): array
    {
        $transformedObjects = [];

        foreach ($objects as $object) {
            // Extract @self data to root level
            $self = $object['@self'] ?? [];

            // Create object data by excluding @self
            $objectData = $object;
            unset($objectData['@self']);

            // Create transformed object with @self data at root and object data in 'object' property
            $transformedObject         = array_merge($self, ['object' => $objectData]);
            $transformedObject['uuid'] = $transformedObject['id'];
            unset($transformedObject['id']);

            // Preserve slug if present in @self
            if (isset($self['slug'])) {
                $transformedObject['slug'] = $self['slug'];
            }

            $transformedObjects[] = $transformedObject;
        }

        return $transformedObjects;

    }//end transformObjectsToDatabaseFormat()


    /**
     * Extract object IDs from transformed objects
     *
     * @param array $transformedObjects Array of transformed objects
     *
     * @return array Array of object IDs (UUIDs or IDs)
     *
     * @phpstan-param  array<int, array<string, mixed>> $transformedObjects
     * @psalm-param    array<int, array<string, mixed>> $transformedObjects
     * @phpstan-return array<int, string>
     * @psalm-return   array<int, string>
     */
    private function extractObjectIds(array $transformedObjects): array
    {
        $ids = [];

        foreach ($transformedObjects as $object) {
            // Try to get UUID first, then fall back to ID
            $id = $object['uuid'] ?? $object['id'] ?? null;

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_filter($ids);

    }//end extractObjectIds()


    /**
     * Find existing objects in the database by their IDs
     *
     * @param array $objectIds Array of object IDs to find
     *
     * @return array Associative array of existing objects indexed by their ID
     *
     * @phpstan-param  array<int, string> $objectIds
     * @psalm-param    array<int, string> $objectIds
     * @phpstan-return array<string, ObjectEntity>
     * @psalm-return   array<string, ObjectEntity>
     */
    private function findExistingObjects(array $objectIds): array
    {
        if (empty($objectIds)) {
            return [];
        }

        // Use mapper's findAll method to find existing objects by IDs
        $existingObjects = $this->objectEntityMapper->findAll(ids: $objectIds, includeDeleted: true);

        // Create associative array indexed by ID
        $indexedObjects = [];
        foreach ($existingObjects as $object) {
            $id = $object->getUuid() ?? $object->getId();
            if ($id !== null) {
                $indexedObjects[$id] = $object;
            }
        }

        return $indexedObjects;

    }//end findExistingObjects()


    /**
     * Merge new object data into existing object
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
        // Clone the existing object to avoid modifying the original
        $mergedObject = clone $existingObject;

        // Hydrate the merged object with new data (this will overwrite existing values)
        $mergedObject->hydrate($newObjectData);

        return $mergedObject;

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


}//end class
