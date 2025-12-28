<?php

/**
 * OpenRegister Register Mapper
 *
 * This file contains the class for handling register mapper related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IAppConfig;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FileService;

/**
 * RegisterMapper handles database operations for Register entities
 *
 * Handles database operations for Register entities with multi-tenancy support.
 * Provides CRUD operations with automatic organisation filtering, RBAC checks,
 * and event dispatching.
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @method Register insert(Entity $entity)
 * @method Register update(Entity $entity)
 * @method Register insertOrUpdate(Entity $entity)
 * @method Register delete(Entity $entity)
 * @method Register find(int|string $id)
 * @method Register findEntity(IQueryBuilder $query)
 * @method Register[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Register> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Register>
 */
class RegisterMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Schema mapper instance
     *
     * Used for finding schemas associated with registers.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * User session for multi-tenancy (from trait)
     *
     * Used to get current user context for multi-tenancy filtering.
     *
     * @var IUserSession User session instance
     */
    protected IUserSession $userSession;

    /**
     * Group manager for RBAC (from trait)
     *
     * Used to check user group memberships for permission verification.
     *
     * @var IGroupManager Group manager instance
     */
    protected IGroupManager $groupManager;

    /**
     * Event dispatcher instance
     *
     * Dispatches events when registers are created, updated, or deleted.
     *
     * @var IEventDispatcher Event dispatcher instance
     */
    private readonly IEventDispatcher $eventDispatcher;

    /**
     * The object entity mapper instance
     *
     * @var ObjectEntityMapper
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Organisation mapper for multi-tenancy (from trait)
     *
     * Used to get active organisation and apply organisation filters.
     *
     * @var OrganisationMapper Organisation mapper instance
     */
    protected OrganisationMapper $organisationMapper;

    /**
     * Constructor
     *
     * Initializes mapper with database connection and required dependencies
     * for multi-tenancy, RBAC, and event dispatching.
     *
     * @param IDBConnection      $db                 Database connection for queries
     * @param SchemaMapper       $schemaMapper       Schema mapper for schema operations
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher for register events
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper for object queries
     * @param OrganisationMapper $organisationMapper Organisation mapper for multi-tenancy
     * @param IUserSession       $userSession        User session for current user context
     * @param IGroupManager      $groupManager       Group manager for RBAC checks
     * @param IAppConfig         $appConfig          App configuration for multitenancy settings
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        SchemaMapper $schemaMapper,
        IEventDispatcher $eventDispatcher,
        ObjectEntityMapper $objectEntityMapper,
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig
    ) {
        // Initialize parent mapper with table name and entity class.
        parent::__construct($db, 'openregister_registers', Register::class);

        // Store dependencies for use in mapper methods.
        $this->schemaMapper       = $schemaMapper;
        $this->eventDispatcher    = $eventDispatcher;
        $this->objectEntityMapper = $objectEntityMapper;
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->appConfig          = $appConfig;
    }//end __construct()

    /**
     * Find a register by its ID, with optional extension for statistics
     *
     * Includes RBAC and organisation filtering for multi-tenancy.
     *
     * @param int|string $id        The ID of the register to find
     * @param array      $_extend   Optional array of extensions (e.g., ['@self.stats'])
     * @param bool|null  $published Whether to enable published bypass (default: null = check config)
     * @param bool       $rbac      Whether to apply RBAC permission checks (default: true)
     * @param bool       $multi     Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Register The found register, possibly with stats
     *
     * @throws \Exception If RBAC permission check fails
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function find(string | int $id, ?array $_extend = [], ?bool $published = null, bool $_rbac = true, bool $_multitenancy = true): Register
    {
        // Log search attempt for debugging.
        if (isset($this->logger) === true) {
            $this->logger->info(
                '[RegisterMapper] Searching for register',
                [
                        'identifier' => $id,
                        'rbac'       => $_rbac,
                        'multi'      => $_multitenancy,
                        'published'  => $published,
                    ]
            );
        }

        // Verify RBAC permission to read registers if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'register');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_registers')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                )
            );

        // Check if register exists before applying filters (for debugging).
        $qbBeforeFilter     = clone $qb;
        $existsBeforeFilter = false;
        try {
            $testResult         = $this->findEntity(query: $qbBeforeFilter);
            $existsBeforeFilter = true;
            if (isset($this->logger) === true) {
                $this->logger->debug(
                    '[RegisterMapper] Register exists before filters',
                    [
                            'identifier'   => $id,
                            'registerId'   => $testResult->getId(),
                            'organisation' => $testResult->getOrganisation(),
                            'published'    => $testResult->getPublished(),
                            'depublished'  => $testResult->getDepublished(),
                        ]
                );
            }
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            if (isset($this->logger) === true) {
                $this->logger->warning(
                    '[RegisterMapper] Register does not exist in database',
                    [
                            'identifier' => $id,
                        ]
                );
            }
        }//end try

        // Apply organisation filter with published entity bypass support
        // Published registers can bypass multi-tenancy restrictions if configured
        // ApplyOrganisationFilter handles $multiTenancyEnabled=false internally
        // Use $published parameter if provided, otherwise check config.
        $enablePublished = $this->shouldPublishedObjectsBypassMultiTenancy();
        if ($published !== null) {
            $enablePublished = $published;
        }

        // Log multitenancy configuration.
        if (isset($this->logger) === true) {
            $activeOrgUuids = $this->getActiveOrganisationUuids();
            $isAdmin        = false;
            $adminOverrideEnabled = false;
            $user = $this->userSession->getUser();
            if ($user !== null && isset($this->groupManager) === true) {
                $userGroups = $this->groupManager->getUserGroupIds($user);
                $isAdmin    = in_array('admin', $userGroups);
            }

            if ($isAdmin === true && isset($this->appConfig) === true) {
                $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
                if (empty($multitenancyConfig) === false) {
                    $multitenancyData     = json_decode($multitenancyConfig, true);
                    $adminOverrideEnabled = $multitenancyData['adminOverride'] ?? false;
                }
            }

            $this->logger->info(
                '[RegisterMapper] Applying multitenancy filters',
                [
                        'identifier'           => $id,
                        'multiEnabled'         => $_multitenancy,
                        'enablePublished'      => $enablePublished,
                        'activeOrganisations'  => $activeOrgUuids,
                        'isAdmin'              => $isAdmin,
                        'adminOverrideEnabled' => $adminOverrideEnabled,
                        'existsBeforeFilter'   => $existsBeforeFilter,
                    ]
            );
        }//end if

        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            tableAlias: '',
            enablePublished: $enablePublished,
            multiTenancyEnabled: $_multitenancy
        );

        // Just return the entity; do not attach stats here.
        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Log detailed error information.
            if (isset($this->logger) === true) {
                $this->logger->error(
                    '[RegisterMapper] Register not found after filters',
                    [
                            'identifier'         => $id,
                            'existsBeforeFilter' => $existsBeforeFilter,
                            'multiEnabled'       => $_multitenancy,
                            'enablePublished'    => $enablePublished,
                            'rbacEnabled'        => $_rbac,
                            'error'              => $e->getMessage(),
                        ]
                );
            }

            throw $e;
        }
    }//end find()

    /**
     * Finds multiple registers by id
     *
     * @param array $ids   The ids of the registers
     * @param bool  $rbac  Whether to apply RBAC permission checks (default: true)
     * @param bool  $multi Whether to apply multi-tenancy filtering (default: true)
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If a register does not exist
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @todo: refactor this into find all
     *
     * @return Register[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Register>
     */
    public function findMultiple(array $ids, ?bool $published = null, bool $_rbac = true, bool $_multitenancy = true): array
    {
        $result = [];
        foreach ($ids as $id) {
            try {
                $result[] = $this->find(id: $id, published: $published, _rbac: $_rbac, _multitenancy: $_multitenancy);
            } catch (\OCP\AppFramework\Db\DoesNotExistException | \OCP\AppFramework\Db\MultipleObjectsReturnedException | \OCP\DB\Exception) {
                // Catch all exceptions but do nothing.
            }
        }

        return $result;
    }//end findMultiple()

    /**
     * Find multiple registers by IDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple registers, register: * significantly improving performance compared to individual queries.
     *
     * @param array $ids Array of register IDs to find.
     *
     * @return Entity&Register[]
     *
     * @psalm-return array<Entity&Register>
     */
    public function findMultipleOptimized(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_registers')
            ->where(
                $qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY))
            );

        $result    = $qb->executeQuery();
        $registers = [];

        while (($row = $result->fetch()) !== false) {
            $register = new Register();
            $register = $register->fromRow($row);
            $registers[$row['id']] = $register;
        }

        return $registers;
    }//end findMultipleOptimized()

    /**
     * Find all registers, files: with optional extension for statistics
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions Array of search conditions
     * @param array|null $searchParams     Array of search parameters
     * @param array      $_extend          Optional array of extensions (e.g., ['@self.stats'])
     * @param bool|null  $published        Whether to enable published bypass (default: null = check config)
     * @param bool       $rbac             Whether to apply RBAC permission checks (default: true)
     * @param bool       $multi            Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Register[]
     *
     * @psalm-return     list<OCA\OpenRegister\Db\Register>
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $_extend = [],
        ?bool $published = null,
        bool $_rbac = true,
        bool $_multitenancy = true
    ): array {
        // Verify RBAC permission to read registers if RBAC is enabled.
        if ($_rbac === true) {
            // @todo: remove this hotfix for solr - uncomment when ready
            // $this->verifyRbacPermission('read', 'register');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_registers')
            ->setMaxResults($limit)
            ->setFirstResult($offset ?? 0);

        foreach ($filters ?? [] as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } elseif ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams ?? [] as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Apply organisation filter with published entity bypass support
        // Published registers can bypass multi-tenancy restrictions if configured
        // ApplyOrganisationFilter handles $multiTenancyEnabled=false internally
        // Use $published parameter if provided, otherwise check config.
        if ($published !== null) {
            $enablePublished = $published;
        } else {
            $enablePublished = $this->shouldPublishedObjectsBypassMultiTenancy();
        }

        $this->applyOrganisationFilter(
            qb: $qb,
            columnName: 'organisation',
            allowNullOrg: true,
            tableAlias: '',
            enablePublished: $enablePublished,
            multiTenancyEnabled: $_multitenancy
        );

        // Just return the entities; do not attach stats here.
        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Insert a new entity
     *
     * Includes RBAC permission check and auto-sets organisation from active session.
     *
     * @param Entity $entity The entity to insert
     *
     * @return Entity The inserted entity
     *
     * @throws         \Exception If RBAC permission check fails
     * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create registers
        // $this->verifyRbacPermission('create', 'register');
        // Auto-set organisation from active session.
        $this->setOrganisationOnCreate($entity);

        // Auto-set owner from current user session.
        $this->setOwnerOnCreate($entity);

        $entity = parent::insert($entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new RegisterCreatedEvent($entity));

        return $entity;
    }//end insert()

    /**
     * Ensures that a register object has a UUID and a slug.
     *
     * @param Register $register The register object to clean
     *
     * @return void
     */
    private function cleanObject(Register $register): void
    {
        // Check if UUID is set, if not, generate a new one.
        if ($register->getUuid() === null) {
            $register->setUuid((string) Uuid::v4());
        }

        // Ensure the object has a slug.
        if (empty($register->getSlug()) === true) {
            // Convert to lowercase and replace spaces with dashes.
            $slug = strtolower(trim($register->getTitle() ?? 'register'));
            // Assuming title is used for slug.
            // Remove special characters.
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            // Remove multiple dashes.
            $slug = preg_replace('/-+/', '-', $slug);
            // Remove leading/trailing dashes.
            $slug = trim($slug, '-');

            $register->setSlug($slug);
        }

        // Ensure the object has a version.
        if ($register->getVersion() === null) {
            $register->setVersion('0.0.1');
        }

        // Ensure the object has a source set to 'internal' by default.
        if ($register->getSource() === null || $register->getSource() === '') {
            $register->setSource('internal');
        }
    }//end cleanObject()

    /**
     * Create a new register from an array of data
     *
     * @param array $object The data to create the register from
     *
     * @return Register The created register
     */
    public function createFromArray(array $object): Register
    {
        $register = new Register();
        $register->hydrate(object: $object);

        // Clean the register object to ensure UUID, slug, and version are set.
        $this->cleanObject($register);

        $register = $this->insert(entity: $register);

        return $register;
    }//end createFromArray()

    /**
     * Update an entity
     *
     * @param Entity $entity The entity to update
     *
     * @return Entity The updated entity
     *
     * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update registers
        // $this->verifyRbacPermission('update', 'register');
        // Verify entity belongs to active organisation.
        $this->verifyOrganisationAccess($entity);

        // Fetch old entity directly without organisation filter for event comparison.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_registers')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
        $oldSchema = $this->findEntity(query: $qb);

        // Clean the register object to ensure UUID, slug, and version are set.
        $this->cleanObject($entity);

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new RegisterUpdatedEvent(newRegister: $entity, oldRegister: $oldSchema));

        return $entity;
    }//end update()

    /**
     * Update an existing register from an array of data
     *
     * @param int   $id     The ID of the register to update
     * @param array $object The new data for the register
     *
     * @return Register The updated register
     */
    public function updateFromArray(int $id, array $object): Register
    {
        // Disable multitenancy filtering for update operations.
        // When updating by ID, we want to find the register regardless of organisation.
        // Access verification happens in update() method via verifyOrganisationAccess().
        $register = $this->find(id: $id, _multitenancy: false);

        // Set or update the version.
        if (isset($object['version']) === false) {
            $currentVersion = $register->getVersion() ?? '0.0.0';
            $version        = explode('.', $currentVersion);
            $version[2]     = ((int) $version[2] + 1);
            $register->setVersion(implode('.', $version));
        }

        $register->hydrate(object: $object);

        // Clean the register object to ensure UUID, extend: slug, files: and version are set.
        $this->cleanObject($register);

        $register = $this->update($register);

        return $register;
    }//end updateFromArray()

    /**
     * Delete a register only if no objects are attached
     *
     * @param Register $entity The register to delete
     *
     * @throws \Exception If objects are still attached to the register
     *
     * @return Register The deleted register
     */
    public function delete(Entity $entity): Register
    {
        // Verify RBAC permission to delete registers
        // $this->verifyRbacPermission('delete', 'register');
        // Verify entity belongs to active organisation.
        $this->verifyOrganisationAccess($entity);

        // Check for attached objects before deleting.
        if (method_exists($entity, 'getId') === true) {
            $registerId = $entity->getId();
        } else {
            $registerId = $entity->id;
        }

        $stats = $this->objectEntityMapper->getStatistics(registerId: $registerId, schemaId: null);
        if (($stats['total'] ?? 0) > 0) {
            throw new ValidationException('Cannot delete register: objects are still attached.');
        }

        // Proceed with deletion if no objects are attached.
        $result = parent::delete($entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(
            new RegisterDeletedEvent($entity)
        );

        return $result;
    }//end delete()

    /**
     * Get all schemas associated with a register
     *
     * @param int       $registerId    The ID of the register
     * @param bool|null $published     Whether to enable published bypass (default: null = check config)
     * @param bool      $_rbac         Whether to apply RBAC permission checks (default: true)
     * @param bool      $_multitenancy Whether to apply multi-tenancy filtering (default: true)
     *
     * @return Schema[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Schema>
     */
    public function getSchemasByRegisterId(int $registerId, ?bool $published = null, bool $_rbac = true, bool $_multitenancy = true): array
    {
        $register  = $this->find(id: $registerId, _extend: [], published: $published, _rbac: $_rbac, _multitenancy: $_multitenancy);
        $schemaIds = $register->getSchemas();

        $schemas = [];

        // Fetch each schema by its ID.
        // Use $_multitenancy=false to bypass organization filter since the register has already passed access checks.
        // This ensures schemas linked to accessible registers can always be found.
        foreach ($schemaIds ?? [] as $schemaId) {
            try {
                $schemas[] = $this->schemaMapper->find((int) $schemaId, [], $published, $_rbac, false);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Schema not found, skip it (similar to RegistersController behavior).
                continue;
            }
        }

        return $schemas;
    }//end getSchemasByRegisterId()

    /**
     * Retrieves the ID of the first register that includes the given schema ID.
     *
     * This method searches the `openregister_registers` table for a register
     * whose `schemas` field (a string) contains the specified schema ID, register: using
     * a regular expression for exact word matching. If a match is found, schema: the ID
     * of the first such register is returned. Otherwise, extend: it returns null.
     *
     * @param int $schemaId The ID of the schema to search for.
     *
     * @return int|null The ID of the first matching register, files: or null if none found.
     */
    public function getFirstRegisterWithSchema(int $schemaId): ?int
    {
        $qb = $this->db->getQueryBuilder();

        // REGEXP: match number with optional whitespace and newlines.
        $pattern = '[[:<:]]' . $schemaId . '[[:>:]]';

        $qb->select('id')
            ->from('openregister_registers')
            ->where('`schemas` REGEXP :pattern')
            ->setParameter('pattern', $pattern)
            ->setMaxResults(1);

        $result = $qb->executeQuery()->fetchOne();

        if ($result !== false) {
            return (int) $result;
        }

        return null;
    }//end getFirstRegisterWithSchema()

    /**
     * Check if a register has a schema with a specific title
     *
     * @param int    $registerId  The ID of the register
     * @param string $schemaTitle The title of the schema to look for
     *
     * @return Schema|null The schema if found, multi: null otherwise
     */
    public function hasSchemaWithTitle(int $registerId, string $schemaTitle): ?Schema
    {
        $schemas = $this->getSchemasByRegisterId($registerId);

        // Check each schema for a matching title.
        foreach ($schemas as $schema) {
            if ($schema->getTitle() === $schemaTitle) {
                return $schema;
            }
        }

        return null;
    }//end hasSchemaWithTitle()

    /**
     * Get all register ID to slug mappings
     *
     * @return array<string,string> Array mapping register IDs to their slugs
     */
    public function getIdToSlugMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['id']] = $row['slug'];
        }

        return $mappings;
    }//end getIdToSlugMap()

    /**
     * Get all register slug to ID mappings
     *
     * @return array<string,string> Array mapping register slugs to their IDs
     */
    public function getSlugToIdMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result   = $qb->executeQuery();
        $mappings = [];
        while (($row = $result->fetch()) !== false) {
            $mappings[$row['slug']] = $row['id'];
        }

        return $mappings;
    }//end getSlugToIdMap()
}//end class
