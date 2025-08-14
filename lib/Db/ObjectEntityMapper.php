<?php

/**
 * OpenRegister Object Entity Mapper
 *
 * This file contains the class for handling object entity mapper related operations
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

use Adbar\Dot;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use OC\DB\QueryBuilder\QueryBuilder;
use OCA\OpenRegister\Db\ObjectHandlers\MariaDbSearchHandler;
use OCA\OpenRegister\Db\ObjectHandlers\MetaDataFacetHandler;
use OCA\OpenRegister\Db\ObjectHandlers\MariaDbFacetHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\IDatabaseJsonService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Uid\Uuid;

/**
 * The ObjectEntityMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class ObjectEntityMapper extends QBMapper
{

    /**
     * Database JSON service instance
     *
     * @var IDatabaseJsonService
     */
    private IDatabaseJsonService $databaseJsonService;

    /**
     * Event dispatcher instance
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * User session instance
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Schema mapper instance
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Group manager instance
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * User manager instance
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * App configuration instance
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;



    /**
     * MariaDB search handler instance
     *
     * @var MariaDbSearchHandler|null
     */
    private ?MariaDbSearchHandler $searchHandler = null;

    /**
     * Metadata facet handler instance
     *
     * @var MetaDataFacetHandler|null
     */
    private ?MetaDataFacetHandler $metaDataFacetHandler = null;

    /**
     * MariaDB facet handler instance
     *
     * @var MariaDbFacetHandler|null
     */
    private ?MariaDbFacetHandler $mariaDbFacetHandler = null;

    public const MAIN_FILTERS = ['register', 'schema', 'uuid', 'created', 'updated'];

    public const DEFAULT_LOCK_DURATION = 3600;

    /**
     * Maximum packet size buffer percentage (0.1 = 10%, 0.5 = 50%)
     * Lower values = more conservative chunk sizes
     */
    private float $maxPacketSizeBuffer = 0.5;




    /**
     * Constructor for the ObjectEntityMapper
     *
     * @param IDBConnection    $db               The database connection
     * @param MySQLJsonService $mySQLJsonService The MySQL JSON service
     * @param IEventDispatcher $eventDispatcher  The event dispatcher
     * @param IUserSession     $userSession      The user session
     * @param SchemaMapper     $schemaMapper     The schema mapper
     * @param IGroupManager    $groupManager     The group manager
     * @param IUserManager     $userManager      The user manager
     * @param IAppConfig       $appConfig        The app configuration
     */
    public function __construct(
        IDBConnection $db,
        MySQLJsonService $mySQLJsonService,
        IEventDispatcher $eventDispatcher,
        IUserSession $userSession,
        SchemaMapper $schemaMapper,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IAppConfig $appConfig
    ) {
        parent::__construct($db, 'openregister_objects');

        if ($db->getDatabasePlatform() instanceof MySQLPlatform === true) {
            $this->databaseJsonService = $mySQLJsonService;
            $this->searchHandler = new MariaDbSearchHandler();
            $this->metaDataFacetHandler = new MetaDataFacetHandler($db);
            $this->mariaDbFacetHandler = new MariaDbFacetHandler($db);
        }

        $this->eventDispatcher = $eventDispatcher;
        $this->userSession     = $userSession;
        $this->schemaMapper    = $schemaMapper;
        $this->groupManager    = $groupManager;
        $this->userManager     = $userManager;
        $this->appConfig       = $appConfig;

        // Try to get max_allowed_packet from database configuration
        $this->initializeMaxPacketSize();
    }//end __construct()


    /**
     * Check if RBAC is enabled in app configuration
     *
     * @return bool True if RBAC is enabled, false otherwise
     */
    private function isRbacEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig)) {
            return false;
        }
        
        $rbacData = json_decode($rbacConfig, true);
        return $rbacData['enabled'] ?? false;
    }//end isRbacEnabled()


    /**
     * Check if multi-tenancy is enabled in app configuration
     *
     * @return bool True if multi-tenancy is enabled, false otherwise
     */
    private function isMultiTenancyEnabled(): bool
    {
        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig)) {
            return false;
        }
        
        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['enabled'] ?? false;
    }//end isMultiTenancyEnabled()


    /**
     * Check if RBAC admin override is enabled in app configuration
     *
     * @return bool True if RBAC admin override is enabled, false otherwise
     */
    private function isAdminOverrideEnabled(): bool
    {
        $rbacConfig = $this->appConfig->getValueString('openregister', 'rbac', '');
        if (empty($rbacConfig)) {
            return true; // Default to true if no RBAC config exists
        }
        
        $rbacData = json_decode($rbacConfig, true);
        return $rbacData['adminOverride'] ?? true;
    }//end isAdminOverrideEnabled()

    /**
     * Initialize the max packet size buffer based on database configuration
     */
    private function initializeMaxPacketSize(): void
    {
        try {
            // Try to get the actual max_allowed_packet value from the database
            $stmt = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
            $result = $stmt->fetch();
            
            if ($result && isset($result['Value'])) {
                $maxPacketSize = (int) $result['Value'];
                error_log('[ObjectEntityMapper] Detected max_allowed_packet: ' . number_format($maxPacketSize) . ' bytes');
                
                // Adjust buffer based on detected packet size
                if ($maxPacketSize > 67108864) { // > 64MB
                    $this->maxPacketSizeBuffer = 0.6; // 60% buffer
                } elseif ($maxPacketSize > 33554432) { // > 32MB
                    $this->maxPacketSizeBuffer = 0.5; // 50% buffer
                } elseif ($maxPacketSize > 16777216) { // > 16MB
                    $this->maxPacketSizeBuffer = 0.4; // 40% buffer
                } else {
                    $this->maxPacketSizeBuffer = 0.3; // 30% buffer for smaller packet sizes
                }
                
                error_log('[ObjectEntityMapper] Set max packet size buffer to ' . ($this->maxPacketSizeBuffer * 100) . '%');
            }
        } catch (\Exception $e) {
            error_log('[ObjectEntityMapper] Could not detect max_allowed_packet, using default buffer: ' . ($this->maxPacketSizeBuffer * 100) . '%');
        }
    }

    /**
     * Set the max packet size buffer for chunk size calculations
     *
     * @param float $buffer Buffer percentage (0.1 = 10%, 0.5 = 50%)
     */
    public function setMaxPacketSizeBuffer(float $buffer): void
    {
        if ($buffer > 0 && $buffer < 1) {
            $this->maxPacketSizeBuffer = $buffer;
            error_log('[ObjectEntityMapper] Max packet size buffer set to ' . ($buffer * 100) . '%');
        } else {
            error_log('[ObjectEntityMapper] Invalid buffer value: ' . $buffer . ', must be between 0.1 and 0.9');
        }
    }

    /**
     * Get the actual max_allowed_packet value from the database
     *
     * @return int The max_allowed_packet value in bytes
     */
    public function getMaxAllowedPacketSize(): int
    {
        try {
            $stmt = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
            $result = $stmt->fetch();
            
            if ($result && isset($result['Value'])) {
                return (int) $result['Value'];
            }
        } catch (\Exception $e) {
            error_log('[ObjectEntityMapper] Could not get max_allowed_packet, using default: 16777216 bytes');
        }
        
        // Default fallback value (16MB)
        return 16777216;
    }

    /**
     * Get the current max packet size buffer percentage
     *
     * @return float The current buffer percentage (0.1 = 10%, 0.5 = 50%)
     */
    public function getMaxPacketSizeBuffer(): float
    {
        return $this->maxPacketSizeBuffer;
    }


    /**
     * Apply RBAC permission filters to a query builder
     *
     * This method adds WHERE conditions to filter objects based on the current user's
     * permissions according to the schema's authorization configuration.
     *
     * @param IQueryBuilder $qb The query builder to modify
     * @param string $objectTableAlias Optional alias for the objects table (default: 'o')
     * @param string $schemaTableAlias Optional alias for the schemas table (default: 's')
     * @param string|null $userId Optional user ID (defaults to current user)
     * @param bool $rbac Whether to apply RBAC checks (default: true). If false, no filtering is applied.
     *
     * @return void
     */
    private function applyRbacFilters(IQueryBuilder $qb, string $objectTableAlias = 'o', string $schemaTableAlias = 's', ?string $userId = null, bool $rbac = true): void
    {
        // If RBAC is disabled, skip all permission filtering
        if ($rbac === false || !$this->isRbacEnabled()) {
            return;
        }
        // Get current user if not provided
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // For unauthenticated requests, show objects that allow public access OR are published
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $qb->andWhere(
                    $qb->expr()->orX(
                        // Schemas with no authorization (open access)
                        $qb->expr()->orX(
                            $qb->expr()->isNull("{$schemaTableAlias}.authorization"),
                            $qb->expr()->eq("{$schemaTableAlias}.authorization", $qb->createNamedParameter('{}'))
                        ),
                        // Schemas that explicitly allow public read access
                        $this->createJsonContainsCondition($qb, "{$schemaTableAlias}.authorization", '$.read', 'public'),
                        // Objects that are currently published (publication-based public access)
                        $qb->expr()->andX(
                            $qb->expr()->isNotNull("{$objectTableAlias}.published"),
                            $qb->expr()->lte("{$objectTableAlias}.published", $qb->createNamedParameter($now)),
                            $qb->expr()->orX(
                                $qb->expr()->isNull("{$objectTableAlias}.depublished"),
                                $qb->expr()->gt("{$objectTableAlias}.depublished", $qb->createNamedParameter($now))
                            )
                        )
                    )
                );
                return;
            }
            $userId = $user->getUID();
        }

        // Get user object first, then user groups
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, handle as unauthenticated with publication-based access
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->orX(
                        $qb->expr()->isNull("{$schemaTableAlias}.authorization"),
                        $qb->expr()->eq("{$schemaTableAlias}.authorization", $qb->createNamedParameter('{}'))
                    ),
                    $this->createJsonContainsCondition($qb, "{$schemaTableAlias}.authorization", '$.read', 'public'),
                    // Objects that are currently published (publication-based public access)
                    $qb->expr()->andX(
                        $qb->expr()->isNotNull("{$objectTableAlias}.published"),
                        $qb->expr()->lte("{$objectTableAlias}.published", $qb->createNamedParameter($now)),
                        $qb->expr()->orX(
                            $qb->expr()->isNull("{$objectTableAlias}.depublished"),
                            $qb->expr()->gt("{$objectTableAlias}.depublished", $qb->createNamedParameter($now))
                        )
                    )
                )
            );
            return;
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Admin users and schema owners see everything
        if (in_array('admin', $userGroups)) {
            return; // No filtering needed for admin users
        }

        // Build conditions for read access
        $readConditions = $qb->expr()->orX();

        // 1. Schemas with no authorization (open access)
        $readConditions->add(
            $qb->expr()->orX(
                $qb->expr()->isNull("{$schemaTableAlias}.authorization"),
                $qb->expr()->eq("{$schemaTableAlias}.authorization", $qb->createNamedParameter('{}'))
            )
        );

        // 2. Schemas where read action is not specified (open read access)
        // For now, skip this condition - it's complex to implement without NOT operator
        // This means we'll be slightly more restrictive but still functional

        // 3. User is the object owner
        $readConditions->add(
            $qb->expr()->eq("{$objectTableAlias}.owner", $qb->createNamedParameter($userId))
        );

        // 4. User's groups are in the authorized groups for read action
        foreach ($userGroups as $groupId) {
            $readConditions->add(
                $this->createJsonContainsCondition($qb, "{$schemaTableAlias}.authorization", '$.read', $groupId)
            );
        }

        // 5. Object is currently published (publication-based public access)
        // Objects are publicly accessible if published date has passed and depublished date hasn't
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $readConditions->add(
            $qb->expr()->andX(
                $qb->expr()->isNotNull("{$objectTableAlias}.published"),
                $qb->expr()->lte("{$objectTableAlias}.published", $qb->createNamedParameter($now)),
                $qb->expr()->orX(
                    $qb->expr()->isNull("{$objectTableAlias}.depublished"),
                    $qb->expr()->gt("{$objectTableAlias}.depublished", $qb->createNamedParameter($now))
                )
            )
        );

        $qb->andWhere($readConditions);

    }//end applyRbacFilters()


    /**
     * Apply organization filtering for multi-tenancy
     *
     * This method adds WHERE conditions to filter objects based on the user's
     * active organization. Users can only see objects that belong to their
     * active organization.
     *
     * @param IQueryBuilder $qb The query builder to modify
     * @param string $objectTableAlias Optional alias for the objects table (default: 'o')
     * @param string|null $activeOrganisationUuid The active organization UUID to filter by
     * @param bool $multi Whether to apply multitenancy filtering (default: true). If false, no filtering is applied.
     *
     * @return void
     */
    private function applyOrganizationFilters(IQueryBuilder $qb, string $objectTableAlias = 'o', ?string $activeOrganisationUuid = null, bool $multi = true): void
    {
        // If multitenancy is disabled, skip all organization filtering
        if ($multi === false || !$this->isMultiTenancyEnabled()) {
            return;
        }
        // Get current user to check if they're admin
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : null;
        
        if ($userId === null) {
            // For unauthenticated requests, show objects that are currently published
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull("{$objectTableAlias}.published"),
                    $qb->expr()->lte("{$objectTableAlias}.published", $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull("{$objectTableAlias}.depublished"),
                        $qb->expr()->gt("{$objectTableAlias}.depublished", $qb->createNamedParameter($now))
                    )
                )
            );
            return;
        }

        // Use provided active organization UUID or fall back to null (no filtering)
        if ($activeOrganisationUuid === null) {
            return;
        }

        // Check if this is the system-wide default organization (move this check up)
        $defaultOrgQb = $this->db->getQueryBuilder();
        $defaultOrgQb->select('uuid')
                     ->from('openregister_organisations')
                     ->where($defaultOrgQb->expr()->eq('is_default', $defaultOrgQb->createNamedParameter(1)))
                     ->setMaxResults(1);
        
        $defaultResult = $defaultOrgQb->executeQuery();
        $systemDefaultOrgUuid = $defaultResult->fetchColumn();
        $defaultResult->closeCursor();
        
        $isSystemDefaultOrg = ($activeOrganisationUuid === $systemDefaultOrgUuid);

        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
            
            // Check if user is admin and admin override is enabled
            if (in_array('admin', $userGroups)) {
                // If admin override is enabled, admin users see all objects regardless of organization
                if ($this->isAdminOverrideEnabled()) {
                    return; // No filtering for admin users when override is enabled
                }
                
                // If admin override is disabled, apply organization filtering logic for admin users
                // Admin users see all objects by default, but should respect organization filtering
                // when an active organization is explicitly set (i.e., when they switch organizations)
                // EXCEPTION: Admin users with the default organization should see everything (no filtering)
                
                // If no active organization is set, admin users see everything (no filtering)
                if ($activeOrganisationUuid === null) {
                    return;
                }
                // If admin user has the default organization set, they see everything (no filtering)
                if ($isSystemDefaultOrg) {
                    return;
                }
                // If an active organization IS set (and it's not default), admin users should see only that organization's objects
                // This allows admins to "switch context" to work within a specific organization
                // Continue with organization filtering logic below
            }
        }

        $organizationColumn = $objectTableAlias ? $objectTableAlias . '.organisation' : 'organisation';

        // Build organization filter conditions
        $orgConditions = $qb->expr()->orX();

        // Objects explicitly belonging to the user's organization
        $orgConditions->add(
            $qb->expr()->eq($organizationColumn, $qb->createNamedParameter($activeOrganisationUuid))
        );

        // ONLY if this is the system-wide default organization, include additional objects
        if ($isSystemDefaultOrg) {
            // Include objects with NULL organization (legacy data)
            $orgConditions->add(
                $qb->expr()->isNull($organizationColumn)
            );
            
            // Include published objects (for backwards compatibility with the system default org)
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $orgConditions->add(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull("{$objectTableAlias}.published"),
                    $qb->expr()->lte("{$objectTableAlias}.published", $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull("{$objectTableAlias}.depublished"),
                        $qb->expr()->gt("{$objectTableAlias}.depublished", $qb->createNamedParameter($now))
                    )
                )
            );
        }

        $qb->andWhere($orgConditions);

    }//end applyOrganizationFilters()


    /**
     * Create a JSON_CONTAINS condition for checking if an array contains a value
     *
     * @param IQueryBuilder $qb The query builder
     * @param string $column The JSON column name
     * @param string $path The JSON path (e.g., '$.read')
     * @param string $value The value to check for
     *
     * @return string The SQL condition
     */
    private function createJsonContainsCondition(IQueryBuilder $qb, string $column, string $path, string $value): string
    {
        // For MySQL/MariaDB, use JSON_CONTAINS to check if array contains value
        if ($this->db->getDatabasePlatform() instanceof MySQLPlatform) {
            return "JSON_CONTAINS({$column}, " . $qb->createNamedParameter(json_encode($value)) . ", '{$path}')";
        }

        // Fallback for other databases - this is less efficient but functional
        return "{$column} LIKE " . $qb->createNamedParameter('%"' . $value . '"%');

    }//end createJsonContainsCondition()


    /**
     * Create a condition to check if a JSON path/key exists
     *
     * @param IQueryBuilder $qb The query builder
     * @param string $column The JSON column name
     * @param string $path The JSON path (e.g., '$.read')
     *
     * @return string The SQL condition
     */
    private function createJsonContainsKeyCondition(IQueryBuilder $qb, string $column, string $path): string
    {
        // For MySQL/MariaDB, use JSON_EXTRACT to check if path exists
        if ($this->db->getDatabasePlatform() instanceof MySQLPlatform) {
            return "JSON_EXTRACT({$column}, '{$path}') IS NOT NULL";
        }

        // Fallback for other databases
        $key = str_replace('$.', '', $path);
        return "{$column} LIKE " . $qb->createNamedParameter('%"' . $key . '":%');

    }//end createJsonContainsKeyCondition()


    /**
     * Find an object by ID or UUID with optional register and schema
     *
     * @param int|string    $identifier     The ID or UUID of the object to find.
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects are found.
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return ObjectEntity The ObjectEntity.
     */
    public function find(string | int $identifier, ?Register $register=null, ?Schema $schema=null, bool $includeDeleted=false, bool $rbac=true, bool $multi=true): ObjectEntity
    {
        $qb = $this->db->getQueryBuilder();

        // Determine ID parameter based on whether identifier is numeric.
        $idParam = -1;
        if (is_numeric($identifier) === true) {
            $idParam = $identifier;
        }

        // Build the base query.
        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT)
                    ),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
                )
            );

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Add optional register filter if provided.
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Add optional schema filter if provided.
        if ($schema !== null) {
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        return $this->findEntity($qb);

    }//end find()



    /**
     * Find all ObjectEntities
     *
     * @param int|null      $limit            The number of objects to return.
     * @param int|null      $offset           The offset of the objects to return.
     * @param array|null    $filters          The filters to apply to the objects.
     * @param array|null    $searchConditions The search conditions to apply to the objects.
     * @param array|null    $searchParams     The search parameters to apply to the objects.
     * @param array         $sort             The sort order to apply.
     * @param string|null   $search           The search string to apply.
     * @param array|null    $ids              Array of IDs or UUIDs to filter by.
     * @param string|null   $uses             Value that must be present in relations.
     * @param bool          $includeDeleted   Whether to include deleted objects.
     * @param Register|null $register         Optional register to filter objects.
     * @param Schema|null   $schema           Optional schema to filter objects.
     * @param bool|null     $published        If true, only return currently published objects.
     *
     * @phpstan-param int|null $limit
     * @phpstan-param int|null $offset
     * @phpstan-param array|null $filters
     * @phpstan-param array|null $searchConditions
     * @phpstan-param array|null $searchParams
     * @phpstan-param array $sort
     * @phpstan-param string|null $search
     * @phpstan-param array|null $ids
     * @phpstan-param string|null $uses
     * @phpstan-param bool $includeDeleted
     * @phpstan-param Register|null $register
     * @phpstan-param Schema|null $schema
     * @phpstan-param bool|null $published
     *
     * @psalm-param int|null $limit
     * @psalm-param int|null $offset
     * @psalm-param array|null $filters
     * @psalm-param array|null $searchConditions
     * @psalm-param array|null $searchParams
     * @psalm-param array $sort
     * @psalm-param string|null $search
     * @psalm-param array|null $ids
     * @psalm-param string|null $uses
     * @psalm-param bool $includeDeleted
     * @psalm-param Register|null $register
     * @psalm-param Schema|null $schema
     * @psalm-param bool|null $published
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return array<int, ObjectEntity> An array of ObjectEntity objects.
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $sort = [],
        ?string $search = null,
        ?array $ids = null,
        ?string $uses = null,
        bool $includeDeleted = false,
        ?Register $register = null,
        ?Schema $schema = null,
        ?bool $published = false,
        bool $rbac = true,
        bool $multi = true
    ): array {
        // Filter out system variables (starting with _).
        $filters = array_filter(
            $filters ?? [],
            function ($key) {
                return str_starts_with($key, '_') === false;
            },
            ARRAY_FILTER_USE_KEY
        );

        // Remove pagination parameters.
        unset(
            $filters['extend'],
            $filters['limit'],
            $filters['offset'],
            $filters['order'],
            $filters['page']
        );

        // Add register to filters if provided.
        if ($register !== null) {
            $filters['register'] = $register;
        }

        // Add schema to filters if provided.
        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('o.*')
            ->from('openregister_objects', 'o')
            ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply RBAC filtering based on user permissions
        $this->applyRbacFilters($qb, 'o', 's', null, $rbac);

		// By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('o.deleted'));
        }

        // If published filter is set, only include objects that are currently published.
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            // published <= now AND (depublished IS NULL OR depublished > now)
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('o.published'),
                    $qb->expr()->lte('o.published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('o.depublished'),
                        $qb->expr()->gt('o.depublished', $qb->createNamedParameter($now))
                    )
                )
            );
        }

        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== null && empty($ids) === false) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('o.id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('o.uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $qb->andWhere($orX);
        }

        // Handle filtering by uses in relations if provided.
        if ($uses !== null) {
            $qb->andWhere(
                $qb->expr()->isNotNull(
                    $qb->createFunction(
                        "JSON_SEARCH(relations, 'one', ".$qb->createNamedParameter($uses).", NULL, '$')"
                    )
                )
            );
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NOT NULL.
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NULL.
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                if (is_array($value) === true) {
                    // If the value is an array, use IN to search for any of the values in the array.
                    $qb->andWhere($qb->expr()->in($filter, $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    // Otherwise, use equality for the filter.
                    $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
                }
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Filter and search the objects.
        $qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
        $qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);

        $sortInRoot = [];
        foreach ($sort as $key => $descOrAsc) {
            if (str_starts_with($key, '@self.')) {
                $sortInRoot = [str_replace('@self.', '', $key) => $descOrAsc];
                break;
            }
        }

        if (empty($sortInRoot) === false) {
            $qb = $this->databaseJsonService->orderInRoot(builder: $qb, order: $sortInRoot);
        } else {
            $qb = $this->databaseJsonService->orderJson(builder: $qb, order: $sort);
        }

        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Process search parameter to handle multiple search words
     *
     * This method handles the _search parameter which can be:
     * - A string with comma-separated values
     * - An array of search terms
     * - A single search term
     *
     * @param mixed $search The search parameter (string or array)
     *
     * @return string|null The processed search string ready for the search handler
     */
    private function processSearchParameter(mixed $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $searchTerms = [];

        // Handle array search terms
        if (is_array($search) === true) {
            $searchTerms = array_filter(
                array_map('trim', $search),
                function ($term) {
                    return empty($term) === false;
                }
            );
        } else if (is_string($search) === true) {
            // Handle comma-separated values in string
            $searchTerms = array_filter(
                array_map('trim', explode(',', $search)),
                function ($term) {
                    return empty($term) === false;
                }
            );
        }

        // If no valid search terms, return null
        if (empty($searchTerms) === true) {
            return null;
        }

        // Process each search term to make them case-insensitive and support partial matches
        $processedTerms = [];
        foreach ($searchTerms as $term) {
            // Convert to lowercase for case-insensitive matching
            $lowerTerm = strtolower(trim($term));

            // Add wildcards for partial matching if not already present
            if (str_starts_with($lowerTerm, '*') === false && str_starts_with($lowerTerm, '%') === false) {
                $lowerTerm = '*' . $lowerTerm;
            }
            if (str_ends_with($lowerTerm, '*') === false && str_ends_with($lowerTerm, '%') === false) {
                $lowerTerm = $lowerTerm . '*';
            }

            $processedTerms[] = $lowerTerm;
        }

        // Join multiple terms with OR logic (any term can match)
        return implode(' OR ', $processedTerms);

    }//end processSearchParameter()


    /**
     * Search objects using a clean query structure
     *
     * This method provides a cleaner alternative to findAll with better separation
     * of metadata and object field searches. It uses a single array parameter that
     * contains all search criteria, filters, and options organized by purpose.
     *
     * ## Query Structure Overview
     *
     * The query array is organized into three main categories:
     * 1. **Metadata filters** - Via `@self` key for database table columns
     * 2. **Object field filters** - Direct keys for JSON object data searches
     * 3. **Search options** - Underscore-prefixed keys for pagination, sorting, etc.
     *
     * ## Metadata Filters (@self)
     *
     * Metadata filters target database table columns and are specified under the `@self` key:
     *
     * **Supported metadata fields:**
     * - `register` - Filter by register ID(s), objects, or mixed arrays
     * - `schema` - Filter by schema ID(s), objects, or mixed arrays
     * - `uuid` - Filter by UUID(s)
     * - `owner` - Filter by owner user ID(s)
     * - `organisation` - Filter by organisation name(s)
     * - `application` - Filter by application name(s)
     * - `created` - Filter by creation date(s)
     * - `updated` - Filter by update date(s)
     *
     * **Value types supported:**
     * - Single values: `'register' => 1` or `'register' => $registerObject`
     * - Arrays: `'register' => [1, 2, 3]` or `'register' => [$reg1, $reg2]`
     * - Mixed arrays: `'register' => [1, '2', $registerObject]`
     * - Objects: Automatically converted using `getId()` method
     * - Null checks: `'owner' => 'IS NULL'` or `'owner' => 'IS NOT NULL'`
     *
     * **Examples:**
     * ```php
     * '@self' => [
     *     'register' => 1,                    // Single register ID
     *     'schema' => [2, 3],                 // Multiple schema IDs
     *     'owner' => 'IS NOT NULL',           // Has an owner
     *     'organisation' => ['org1', 'org2']  // Multiple organisations
     * ]
     * ```
     *
     * ## Object Field Filters
     *
     * Object field filters search within the JSON `object` column data.
     * These are specified as direct keys in the query array (not under `@self`).
     *
     * **Supported patterns:**
     * - Simple fields: `'name' => 'John Doe'`
     * - Nested fields: `'address.city' => 'Amsterdam'` (dot notation)
     * - Array values: `'status' => ['active', 'pending']` (one-of search)
     * - Null checks: `'description' => 'IS NULL'`
     *
     * **Examples:**
     * ```php
     * 'name' => 'John Doe',               // Exact match
     * 'age' => 25,                        // Numeric value
     * 'address.city' => 'Amsterdam',      // Nested field
     * 'tags' => ['vip', 'customer'],      // Array search (OR)
     * 'archived' => 'IS NULL'             // Not archived
     * ```
     *
     * ## Search Options (Underscore-Prefixed)
     *
     * Search options control pagination, sorting, and special behaviors.
     * All options are prefixed with underscore (`_`) to distinguish them from filters.
     *
     * **Available options:**
     *
     * ### `_limit` (int|null)
     * Maximum number of results to return
     * ```php
     * '_limit' => 50
     * ```
     *
     * ### `_offset` (int|null)
     * Number of results to skip (for pagination)
     * ```php
     * '_offset' => 100
     * ```
     *
     * ### `_order` (array)
     * Sorting criteria with field => direction mapping
     * - Metadata fields: Use `@self.fieldname` syntax
     * - Object fields: Use direct field names (supports dot notation)
     * - Direction: 'ASC' or 'DESC' (case-insensitive)
     * ```php
     * '_order' => [
     *     '@self.created' => 'DESC',   // Sort by creation date
     *     'name' => 'ASC',             // Then by object name
     *     'priority' => 'DESC'         // Then by priority
     * ]
     * ```
     *
     * ### `_search` (string|array|null)
     * Full-text search within JSON object data
     * Supports multiple search words:
     * - String with comma-separated values: `'_search' => 'customer,service,important'`
     * - Array of search terms: `'_search' => ['customer', 'service', 'important']`
     * - Single search term: `'_search' => 'customer service important'`
     * ```php
     * '_search' => 'customer service important'
     * '_search' => ['customer', 'service', 'important']
     * '_search' => 'customer,service,important'
     * ```
     *
     * ### `_includeDeleted` (bool)
     * Whether to include soft-deleted objects (default: false)
     * ```php
     * '_includeDeleted' => true
     * ```
     *
     * ### `_published` (bool)
     * Filter for currently published objects only
     * Checks: published <= now AND (depublished IS NULL OR depublished > now)
     * ```php
     * '_published' => true
     * ```
     *
     * ### `_ids` (array|null)
     * Filter objects by specific IDs or UUIDs
     * Searches both the 'id' column (integer) and 'uuid' column (string)
     * ```php
     * '_ids' => [1, 2, 3]                           // Filter by IDs
     * '_ids' => ['uuid1', 'uuid2', 'uuid3']         // Filter by UUIDs
     * '_ids' => [1, 'uuid2', 3, 'uuid4']            // Mixed IDs and UUIDs
     * ```
     *
     * ### `_count` (bool)
     * Return only the count of matching objects instead of the objects themselves
     * When true, returns an integer count instead of an array of ObjectEntity objects
     * Optimized for performance using COUNT(*) instead of selecting all data
     * ```php
     * '_count' => true                               // Returns integer count
     * '_count' => false                              // Returns ObjectEntity array (default)
     * ```
     *
     * ## Complete Query Examples
     *
     * **Basic metadata search:**
     * ```php
     * $query = [
     *     '@self' => [
     *         'register' => 1,
     *         'owner' => 'user123'
     *     ]
     * ];
     * ```
     *
     * **Complex mixed search:**
     * ```php
     * $query = [
     *     '@self' => [
     *         'register' => [1, 2, 3],        // Multiple registers
     *         'schema' => $schemaObject,       // Schema object
     *         'organisation' => 'IS NOT NULL' // Has organisation
     *     ],
     *     'name' => 'John',                    // Object field search
     *     'status' => ['active', 'pending'],   // Multiple statuses
     *     'address.city' => 'Amsterdam',       // Nested field
     *     '_search' => 'important customer',   // Full-text search
     *     '_ids' => [1, 'uuid-123', 5],        // Specific IDs/UUIDs
     *     '_order' => [
     *         '@self.created' => 'DESC',       // Newest first
     *         'priority' => 'ASC'              // Then by priority
     *     ],
     *     '_limit' => 25,                      // Pagination
     *     '_offset' => 50,
     *     '_published' => true                 // Only published
     * ];
     * ```
     *
     * **Count query (same filters, optimized for counting):**
     * ```php
     * $countQuery = [
     *     '@self' => [
     *         'register' => [1, 2, 3],        // Same filters as above
     *         'organisation' => 'IS NOT NULL'
     *     ],
     *     'name' => 'John',
     *     'status' => ['active', 'pending'],
     *     '_search' => 'important customer',
     *     '_published' => true,
     *     '_count' => true                     // Returns integer count instead of objects
     * ];
     * // Note: _limit, _offset, _order are ignored for count queries
     * ```
     *
     * ## Performance Notes
     *
     * - Metadata filters are indexed and perform better than object field filters
     * - Use metadata filters when possible for better performance
     * - Full-text search (`_search`) is optimized but can be slower on large datasets
     * - Consider pagination (`_limit`/`_offset`) for large result sets
     *
     * @param array $query The search query array containing filters and options
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array<int, ObjectEntity>|int An array of ObjectEntity objects matching the criteria, or integer count if _count is true
     */
    public function searchObjects(array $query = [], ?string $activeOrganisationUuid = null, bool $rbac = true, bool $multi = true): array|int {
        // Extract options from query (prefixed with _)
        $limit = $query['_limit'] ?? null;
        $offset = $query['_offset'] ?? null;
        $order = $query['_order'] ?? [];
        $search = $this->processSearchParameter($query['_search'] ?? null);
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $published = $query['_published'] ?? false;
        $ids = $query['_ids'] ?? null;
        $count = $query['_count'] ?? false;

        // Extract metadata from @self
        $metadataFilters = [];
        $register = null;
        $schema = null;

        if (isset($query['@self']) === true && is_array($query['@self']) === true) {
            $metadataFilters = $query['@self'];

            // Process register: convert objects to IDs and handle arrays
            if (isset($metadataFilters['register']) === true) {
                $register = $this->processRegisterSchemaValue($metadataFilters['register'], 'register');
                // Keep in metadataFilters for search handler to process properly with other filters
                $metadataFilters['register'] = $register;
            }

            // Process schema: convert objects to IDs and handle arrays
            if (isset($metadataFilters['schema']) === true) {
                $schema = $this->processRegisterSchemaValue($metadataFilters['schema'], 'schema');
                // Keep in metadataFilters for search handler to process properly with other filters
                $metadataFilters['schema'] = $schema;
            }
        }

        // Clean the query: remove @self and all properties prefixed with _
        $cleanQuery = array_filter($query, function($key) {
            return $key !== '@self' && str_starts_with($key, '_') === false;
        }, ARRAY_FILTER_USE_KEY);


        // If search handler is not available, fall back to the original methods
        if ($this->searchHandler === null) {
            if ($count === true) {
                return $this->countAll(
                    filters: $cleanQuery,
                    search: $search,
                    ids: $ids,
                    uses: null,
                    includeDeleted: $includeDeleted,
                    register: $register,
                    schema: $schema,
                    published: $published
                );
            }

            return $this->findAll(
                limit: $limit,
                offset: $offset,
                filters: $cleanQuery,
                sort: $order,
                search: $search,
                ids: $ids,
                includeDeleted: $includeDeleted,
                register: $register,
                schema: $schema,
                published: $published
            );
        }

        $queryBuilder = $this->db->getQueryBuilder();

        // Build base query - different for count vs search
        if ($count === true) {
            // For count queries, use COUNT(o.*) and skip pagination, include schema join for RBAC
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(o.*)'), 'count')
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id');
        } else {
            // For search queries, select all object columns and apply pagination, include schema join for RBAC
            $queryBuilder->select('o.*')
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->setMaxResults($limit)
                ->setFirstResult($offset);
        }

        // Apply RBAC filtering based on user permissions
        $this->applyRbacFilters($queryBuilder, 'o', 's', null, $rbac);

        // Apply organization filtering for multi-tenancy
        $this->applyOrganizationFilters($queryBuilder, 'o', $activeOrganisationUuid, $multi);

        // Handle basic filters - skip register/schema if they're in metadata filters (to avoid double filtering)
        $basicRegister = isset($metadataFilters['register']) ? null : $register;
        $basicSchema = isset($metadataFilters['schema']) ? null : $schema;
        $this->applyBasicFilters($queryBuilder, $includeDeleted, $published, $basicRegister, $basicSchema, 'o');

        // Handle filtering by IDs/UUIDs if provided
        if ($ids !== null && empty($ids) === false) {
            $orX = $queryBuilder->expr()->orX();
            $orX->add($queryBuilder->expr()->in('o.id', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($queryBuilder->expr()->in('o.uuid', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $queryBuilder->andWhere($orX);
        }

        // Use cleaned query as object filters
        $objectFilters = $cleanQuery;

        // Apply metadata filters (register, schema, etc.)
        if (empty($metadataFilters) === false) {
            $queryBuilder = $this->searchHandler->applyMetadataFilters($queryBuilder, $metadataFilters);
        }

        // Apply object field filters (JSON searches)
        if (empty($objectFilters) === false) {
            $queryBuilder = $this->searchHandler->applyObjectFilters($queryBuilder, $objectFilters);
        }

        // Apply full-text search if provided
        if ($search !== null && trim($search) !== '') {
            $queryBuilder = $this->searchHandler->applyFullTextSearch($queryBuilder, trim($search));
        }

        // Apply ordering (skip for count queries as it's not needed and would be inefficient)
        if ($count === false && empty($order) === false) {
            $metadataSort = [];
            $objectSort = [];

            foreach ($order as $field => $direction) {
                if (str_starts_with($field, '@self.') === true) {
                    // Remove @self. prefix for metadata sorting
                    $metadataField = str_replace('@self.', '', $field);
                    $metadataSort[$metadataField] = $direction;
                } else {
                    // Object field sorting
                    $objectSort[$field] = $direction;
                }
            }

            // Apply metadata sorting (standard SQL fields)
            foreach ($metadataSort as $field => $direction) {
                $direction = strtoupper($direction);
                if (in_array($direction, ['ASC', 'DESC']) === false) {
                    $direction = 'ASC';
                }
                $queryBuilder->addOrderBy($field, $direction);
            }

            // Apply object field sorting (JSON fields)
            if (empty($objectSort) === false) {
                $queryBuilder = $this->searchHandler->applySorting($queryBuilder, $objectSort);
            }
        }

        // Return appropriate result based on count flag
        if ($count === true) {
            $result = $queryBuilder->executeQuery();
            return (int) $result->fetchOne();
        } else {
            return $this->findEntities($queryBuilder);
        }

    }//end searchObjects()


    /**
     * Count objects using clean query structure (optimized for pagination)
     *
     * This method provides an optimized count query that mirrors the searchObjects
     * functionality but returns only the count of matching objects. It uses the same
     * query structure and filters as searchObjects but performs a COUNT(*) operation
     * instead of selecting all data.
     *
     * @param array $query The search query array containing filters and options
     *                     - @self: Metadata filters (register, schema, uuid, etc.)
     *                     - Direct keys: Object field filters for JSON data
     *                     - _search: Full-text search term (string or array)
     *                     - _includeDeleted: Include soft-deleted objects
     *                     - _published: Only published objects
     *                     - _ids: Array of IDs/UUIDs to filter by
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return int The number of objects matching the criteria
     */
    public function countSearchObjects(array $query = [], ?string $activeOrganisationUuid = null, bool $rbac = true, bool $multi = true): int
    {
        // Extract options from query (prefixed with _)
        $search = $this->processSearchParameter($query['_search'] ?? null);
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $published = $query['_published'] ?? false;
        $ids = $query['_ids'] ?? null;

        // Extract metadata from @self
        $metadataFilters = [];
        $register = null;
        $schema = null;

        if (isset($query['@self']) === true && is_array($query['@self']) === true) {
            $metadataFilters = $query['@self'];

            // Process register: convert objects to IDs and handle arrays
            if (isset($metadataFilters['register']) === true) {
                $register = $this->processRegisterSchemaValue($metadataFilters['register'], 'register');
                // Keep in metadataFilters for search handler to process properly with other filters
                $metadataFilters['register'] = $register;
            }

            // Process schema: convert objects to IDs and handle arrays
            if (isset($metadataFilters['schema']) === true) {
                $schema = $this->processRegisterSchemaValue($metadataFilters['schema'], 'schema');
                // Keep in metadataFilters for search handler to process properly with other filters
                $metadataFilters['schema'] = $schema;
            }
        }

        // Clean the query: remove @self and all properties prefixed with _
        $cleanQuery = array_filter($query, function($key) {
            return $key !== '@self' && str_starts_with($key, '_') === false;
        }, ARRAY_FILTER_USE_KEY);

        // If search handler is not available, fall back to the original countAll method
        if ($this->searchHandler === null) {
            return $this->countAll(
                filters: $cleanQuery,
                search: $search,
                ids: $ids,
                uses: null,
                includeDeleted: $includeDeleted,
                register: $register,
                schema: $schema,
                published: $published
            );
        }

        $queryBuilder = $this->db->getQueryBuilder();

        // Build base count query - use COUNT(*) instead of selecting all columns
        $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'count')
            ->from('openregister_objects', 'o');

        // Handle basic filters - skip register/schema if they're in metadata filters (to avoid double filtering)
        $basicRegister = isset($metadataFilters['register']) ? null : $register;
        $basicSchema = isset($metadataFilters['schema']) ? null : $schema;
        $this->applyBasicFilters($queryBuilder, $includeDeleted, $published, $basicRegister, $basicSchema, 'o');

        // Apply organization filtering for multi-tenancy (no RBAC in count queries due to no schema join)
        $this->applyOrganizationFilters($queryBuilder, 'o', $activeOrganisationUuid, $multi);

        // Handle filtering by IDs/UUIDs if provided (same as searchObjects)
        if ($ids !== null && empty($ids) === false) {
            $orX = $queryBuilder->expr()->orX();
            $orX->add($queryBuilder->expr()->in('o.id', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($queryBuilder->expr()->in('o.uuid', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $queryBuilder->andWhere($orX);
        }

        // Use cleaned query as object filters
        $objectFilters = $cleanQuery;

        // Apply metadata filters (register, schema, etc.)
        if (empty($metadataFilters) === false) {
            $queryBuilder = $this->searchHandler->applyMetadataFilters($queryBuilder, $metadataFilters);
        }

        // Apply object field filters (JSON searches)
        if (empty($objectFilters) === false) {
            $queryBuilder = $this->searchHandler->applyObjectFilters($queryBuilder, $objectFilters);
        }

        // Apply full-text search if provided
        if ($search !== null && trim($search) !== '') {
            $queryBuilder = $this->searchHandler->applyFullTextSearch($queryBuilder, trim($search));
        }

        // Note: We don't apply sorting for count queries as it's not needed and would be inefficient

        $result = $queryBuilder->executeQuery();
        return (int) $result->fetchOne();

    }//end countSearchObjects()


    /**
     * Sum the size of search objects based on query parameters
     *
     * @param array       $query                   Query parameters for filtering
     * @param string|null $activeOrganisationUuid UUID of the active organisation
     * @param bool        $rbac                    Whether to apply RBAC filters
     * @param bool        $multi                   Whether to apply multi-tenancy filters
     *
     * @return int Total size of matching objects in bytes
     */
    public function sizeSearchObjects(array $query = [], ?string $activeOrganisationUuid = null, bool $rbac = true, bool $multi = true): int
    {
        // Extract options from query (prefixed with _) - same as countSearchObjects
        $search = $this->processSearchParameter($query['_search'] ?? null);
        $includeDeleted = $query['_includeDeleted'] ?? false;
        $published = $query['_published'] ?? false;
        $ids = $query['_ids'] ?? null;

        // Extract metadata from @self
        $metadataFilters = [];
        $register = null;
        $schema = null;

        if (isset($query['@self']) === true && is_array($query['@self']) === true) {
            $metadataFilters = $query['@self'];

            // Process register: convert objects to IDs and handle arrays
            if (isset($metadataFilters['register']) === true) {
                $register = $this->processRegisterSchemaValue($metadataFilters['register'], 'register');
                $metadataFilters['register'] = $register;
            }

            // Process schema: convert objects to IDs and handle arrays
            if (isset($metadataFilters['schema']) === true) {
                $schema = $this->processRegisterSchemaValue($metadataFilters['schema'], 'schema');
                $metadataFilters['schema'] = $schema;
            }
        }

        // Clean the query: remove @self and all properties prefixed with _
        $cleanQuery = array_filter($query, function($key) {
            return $key !== '@self' && str_starts_with($key, '_') === false;
        }, ARRAY_FILTER_USE_KEY);

        // If search handler is not available, fall back to a basic size query
        if ($this->searchHandler === null) {
            $queryBuilder = $this->db->getQueryBuilder();
            $queryBuilder->select($queryBuilder->func()->sum('size'))
                ->from($this->getTableName());
            
            $this->applyBasicFilters($queryBuilder, $includeDeleted, $published, $register, $schema);
            $this->applyOrganizationFilters($queryBuilder, '', null, $multi);
            
            $result = $queryBuilder->executeQuery();
            $size = $result->fetchOne();
            $result->closeCursor();
            return (int) ($size ?? 0);
        }

        $queryBuilder = $this->db->getQueryBuilder();

        // Build base size query - use SUM(size) instead of COUNT(*)
        $queryBuilder->select($queryBuilder->func()->sum('o.size'))
            ->from('openregister_objects', 'o');

        // Handle basic filters - skip register/schema if they're in metadata filters
        $basicRegister = isset($metadataFilters['register']) ? null : $register;
        $basicSchema = isset($metadataFilters['schema']) ? null : $schema;
        $this->applyBasicFilters($queryBuilder, $includeDeleted, $published, $basicRegister, $basicSchema, 'o');

        // Apply organization filtering for multi-tenancy
        $this->applyOrganizationFilters($queryBuilder, 'o', $activeOrganisationUuid, $multi);

        // Handle filtering by IDs/UUIDs if provided
        if ($ids !== null && empty($ids) === false) {
            $orX = $queryBuilder->expr()->orX();
            $orX->add($queryBuilder->expr()->in('o.id', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($queryBuilder->expr()->in('o.uuid', $queryBuilder->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $queryBuilder->andWhere($orX);
        }

        // Use cleaned query as object filters
        $objectFilters = $cleanQuery;

        // Apply metadata filters (register, schema, etc.)
        if (empty($metadataFilters) === false) {
            $queryBuilder = $this->searchHandler->applyMetadataFilters($queryBuilder, $metadataFilters);
        }

        // Apply object field filters (JSON searches)
        if (empty($objectFilters) === false) {
            $queryBuilder = $this->searchHandler->applyObjectFilters($queryBuilder, $objectFilters);
        }

        // Apply full-text search if provided
        if ($search !== null && trim($search) !== '') {
            $queryBuilder = $this->searchHandler->applyFullTextSearch($queryBuilder, trim($search));
        }

        $result = $queryBuilder->executeQuery();
        $size = $result->fetchOne();
        $result->closeCursor();

        return (int) ($size ?? 0);

    }//end sizeSearchObjects()


    /**
     * Apply basic filters to the query builder
     *
     * Handles common filters like deleted, published, register, and schema.
     *
     * @param IQueryBuilder     $queryBuilder   The query builder to modify
     * @param bool              $includeDeleted Whether to include deleted objects
     * @param bool|null         $published      If true, only return currently published objects
     * @param mixed             $register       Optional register(s) to filter by (single/array, string/int/object)
     * @param mixed             $schema         Optional schema(s) to filter by (single/array, string/int/object)
     * @param string            $tableAlias     The table alias to use (default: '')
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param bool $includeDeleted
     * @phpstan-param bool|null $published
     * @phpstan-param mixed $register
     * @phpstan-param mixed $schema
     * @phpstan-param string $tableAlias
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param bool $includeDeleted
     * @psalm-param bool|null $published
     * @psalm-param mixed $register
     * @psalm-param mixed $schema
     * @psalm-param string $tableAlias
     *
     * @return void
     */
    private function applyBasicFilters(
        IQueryBuilder $queryBuilder,
        bool $includeDeleted,
        ?bool $published,
        mixed $register,
        mixed $schema,
        string $tableAlias = ''
    ): void {
        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true
        $deletedColumn = $tableAlias ? $tableAlias . '.deleted' : 'deleted';
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull($deletedColumn));
        }

        // If published filter is set, only include objects that are currently published
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $publishedColumn = $tableAlias ? $tableAlias . '.published' : 'published';
            $depublishedColumn = $tableAlias ? $tableAlias . '.depublished' : 'depublished';
            $queryBuilder->andWhere(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->isNotNull($publishedColumn),
                    $queryBuilder->expr()->lte($publishedColumn, $queryBuilder->createNamedParameter($now)),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->isNull($depublishedColumn),
                        $queryBuilder->expr()->gt($depublishedColumn, $queryBuilder->createNamedParameter($now))
                    )
                )
            );
        }

        // Add register filter if provided
        if ($register !== null) {
            $registerColumn = $tableAlias ? $tableAlias . '.register' : 'register';
            if (is_array($register) === true) {
                // Handle array of register IDs
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in($registerColumn, $queryBuilder->createNamedParameter($register, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
                );
            } else if (is_object($register) === true && method_exists($register, 'getId') === true) {
                // Handle single register object
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($registerColumn, $queryBuilder->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
                );
            } else {
                // Handle single register ID (string/int)
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($registerColumn, $queryBuilder->createNamedParameter($register, IQueryBuilder::PARAM_INT))
                );
            }
        }

        // Add schema filter if provided
        if ($schema !== null) {
            $schemaColumn = $tableAlias ? $tableAlias . '.schema' : 'schema';
            if (is_array($schema) === true) {
                // Handle array of schema IDs
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in($schemaColumn, $queryBuilder->createNamedParameter($schema, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
                );
            } else if (is_object($schema) === true && method_exists($schema, 'getId') === true) {
                // Handle single schema object
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($schemaColumn, $queryBuilder->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
                );
            } else {
                // Handle single schema ID (string/int)
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($schemaColumn, $queryBuilder->createNamedParameter($schema, IQueryBuilder::PARAM_INT))
                );
            }
        }

    }//end applyBasicFilters()


    /**
     * Process register or schema values to handle objects and arrays
     *
     * Converts objects to IDs using getId() method and handles both single values and arrays.
     *
     * @param mixed  $value The register or schema value (string, object, or array)
     * @param string $type  The type ('register' or 'schema') for error reporting
     *
     * @phpstan-param mixed $value
     * @phpstan-param string $type
     *
     * @psalm-param mixed $value
     * @psalm-param string $type
     *
     * @return Register|Schema|array|null The processed value
     */
    private function processRegisterSchemaValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        // Handle arrays
        if (is_array($value) === true) {
            $processedValues = [];
            foreach ($value as $item) {
                if (is_object($item) === true && method_exists($item, 'getId') === true) {
                    // Convert object to ID
                    $processedValues[] = $item->getId();
                } else if (is_string($item) === true || is_int($item) === true) {
                    // Keep string/int values as-is
                    $processedValues[] = $item;
                } else {
                    // Invalid value type, skip it
                    continue;
                }
            }
            return empty($processedValues) === false ? $processedValues : null;
        }

        // Handle single values
        if (is_object($value) === true) {
            if (method_exists($value, 'getId') === true) {
                // Return the object itself for the basic filter logic to handle
                return $value;
            } else {
                // Invalid object type
                return null;
            }
        }

        // Handle string/int values
        if (is_string($value) === true || is_int($value) === true) {
            return $value;
        }

        // Invalid value type
        return null;

    }//end processRegisterSchemaValue()


    /**
     * Counts all objects with optional register and schema filters
     *
     * @param array|null    $filters        The filters to apply
     * @param string|null   $search         The search string to apply
     * @param bool          $includeDeleted Whether to include deleted objects
     * @param Register|null $register       Optional register to filter by
     * @param Schema|null   $schema         Optional schema to filter by
     *
     * @return int The number of objects
     */
    public function countAll(
        ?array $filters=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null,
        ?bool $published=false,
        bool $rbac=true,
        bool $multi=true
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(select: $qb->createFunction(call: 'count(o.id)'), alias: 'count')
            ->from('openregister_objects', 'o')
            ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id');

        // Filter out system variables (starting with _)
        $filters = array_filter(
            $filters ?? [],
            function ($key) {
                return !str_starts_with($key, '_');
            },
            ARRAY_FILTER_USE_KEY
        );

        // Remove pagination parameters.
        unset(
            $filters['extend'],
            $filters['limit'],
            $filters['offset'],
            $filters['order'],
            $filters['page']
        );

        // Add register to filters if provided
        if ($register !== null) {
            $filters['register'] = $register;
        }

        // Add schema to filters if provided
        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        // Apply RBAC filtering based on user permissions
        $this->applyRbacFilters($qb, 'o', 's', null, $rbac);

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('o.deleted'));
        }

        // If published filter is set, only include objects that are currently published.
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            // published <= now AND (depublished IS NULL OR depublished > now)
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('o.published'),
                    $qb->expr()->lte('o.published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('o.depublished'),
                        $qb->expr()->gt('o.depublished', $qb->createNamedParameter($now))
                    )
                )
            );
        }


        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== null && empty($ids) === false) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('o.id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('o.uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $qb->andWhere($orX);
        }

        // Handle filtering by uses in relations if provided.
        if ($uses !== null) {
            $qb->andWhere(
                $qb->expr()->isNotNull(
                    $qb->createFunction(
                        "JSON_SEARCH(relations, 'one', ".$qb->createNamedParameter($uses).", NULL, '$')"
                    )
                )
            );
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NOT NULL
                $qb->andWhere($qb->expr()->isNotNull('o.' . $filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NULL
                $qb->andWhere($qb->expr()->isNull('o.' . $filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                if (is_array($value)) {
                    // If the value is an array, use IN to search for any of the values in the array
                    $qb->andWhere($qb->expr()->in('o.' . $filter, $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    // Otherwise, use equality for the filter
                    $qb->andWhere($qb->expr()->eq('o.' . $filter, $qb->createNamedParameter($value)));
                }
            }
        }

        // Filter and search the objects.
        $qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
        $qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);

        $result = $qb->executeQuery();

        return $result->fetchAll()[0]['count'];

    }//end countAll()


    /**
     * Inserts a new entity into the database.
     *
     * @param Entity $entity The entity to insert.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return Entity The inserted entity.
     */
    public function insert(Entity $entity): Entity
    {
        // Lets make sure that @self and id never enter the database.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

        $entity = parent::insert($entity);

        // Dispatch creation event.

        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Creates an object from an array
     *
     * @param array $object The object to create
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return ObjectEntity The created object
     */
    public function createFromArray(array $object): ObjectEntity
    {
        $obj = new ObjectEntity();

        // Ensure we have a UUID
        if (empty($object['uuid'])) {
            $object['uuid'] = Uuid::v4();
        }

        $obj->hydrate(object: $object);

        // Prepare the object before insertion.
        return $this->insert($obj);

    }//end createFromArray()


    /**
     * Updates an entity in the database
     *
     * @param Entity $entity        The entity to update
     * @param bool   $includeDeleted Whether to include deleted objects when finding the old object
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
     *
     * @return Entity The updated entity
     */
    public function update(Entity $entity, bool $includeDeleted = false): Entity
    {
        // For ObjectEntity, we need to find by the internal database ID, not UUID
        // The getId() method returns the database primary key


        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId())));

        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        $oldObject = $this->findEntity($qb);

        // Lets make sure that @self and id never enter the database.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

        $entity = parent::update($entity);

        // Dispatch update event.

        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent($entity, $oldObject));

        return $entity;

    }//end update()


    /**
     * Updates an object from an array
     *
     * @param int   $id     The id of the object to update
     * @param array $object The object to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found
     *
     * @return ObjectEntity The updated object
     */
    public function updateFromArray(int $id, array $object): ObjectEntity
    {
        $oldObject = $this->find($id);
        $newObject = clone $oldObject;

        // Ensure we preserve the UUID if it exists, or create a new one if it doesn't
        if (empty($object['id']) && empty($oldObject->getUuid())) {
            $object['id'] = Uuid::v4();
        } else if (empty($object['uuid'])) {
            $object['id'] = $oldObject->getUuid();
        }

        $newObject->hydrate($object);

        // Prepare the object before updating.
        return $this->update($this->prepareEntity($newObject));

    }//end updateFromArray()


    /**
     * Delete an object
     *
     * @param ObjectEntity $object The object to delete
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return ObjectEntity The deleted object
     */
    public function delete(Entity $object): ObjectEntity
    {
        $result = parent::delete($object);

        // Dispatch deletion event.

        $this->eventDispatcher->dispatchTyped(
            new ObjectDeletedEvent($object)
        );

        return $result;

    }//end delete()


    /**
     * Gets the facets for the objects (LEGACY METHOD - DO NOT USE DIRECTLY)
     *
     * @deprecated This method is legacy and should not be used directly.
     *             Use getSimpleFacets() with _facets configuration instead.
     *             This method remains only for internal compatibility.
     *
     * @param array       $filters The filters to apply
     * @param string|null $search  The search string to apply
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array The facets
     */
    public function getFacets(array $filters=[], ?string $search=null): array
    {
        $register = null;
        $schema   = null;

        if (array_key_exists('register', $filters) === true) {
            $register = $filters['register'];
        }

        if (array_key_exists('schema', $filters) === true) {
            $schema = $filters['schema'];
        }

        $fields = [];
        if (isset($filters['_queries']) === true) {
            $fields = $filters['_queries'];
        }

        unset(
            $filters['_fields'],
            $filters['register'],
            $filters['schema'],
            $filters['created'],
            $filters['updated'],
            $filters['uuid']
        );

        return $this->databaseJsonService->getAggregations(
            builder: $this->db->getQueryBuilder(),
            fields: $fields,
            register: $register,
            schema: $schema,
            filters: $filters,
            search: $search
        );

    }//end getFacets()


    /**
     * Find objects that have a specific URI or UUID in their relations
     *
     * @param string $search       The URI or UUID to search for in relations
     * @param bool   $partialMatch Whether to search for partial matches (default: false)
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array An array of ObjectEntities that have the specified URI/UUID
     */
    public function findByRelation(string $search, bool $partialMatch=true): array
    {
        $qb = $this->db->getQueryBuilder();

        // For partial matches, we use '%' wildcards and 'all' mode to search anywhere in the JSON.
        // For exact matches, we use 'one' mode which finds exact string matches.
        $mode       = 'one';
        $searchTerm = $search;

        if ($partialMatch === true) {
            $mode       = 'all';
            $searchTerm = '%'.$search.'%';
        }

        $searchFunction = "JSON_SEARCH(relations, '".$mode."', ".$qb->createNamedParameter($searchTerm);
        if ($partialMatch === true) {
            $searchFunction .= ", NULL, '$')";
        } else {
            $searchFunction .= ")";
        }

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->isNotNull(
                    $qb->createFunction($searchFunction)
                )
            );

        return $this->findEntities($qb);

    }//end findByRelation()


    /**
     * Lock an object
     *
     * @param string|int  $identifier Object ID, UUID, or URI
     * @param string|null $process    Optional process identifier
     * @param int|null    $duration   Lock duration in seconds
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If locking fails
     *
     * @return ObjectEntity The locked object
     */
    public function lockObject($identifier, ?string $process=null, ?int $duration=null): ObjectEntity
    {
        $object = $this->find($identifier);

        if ($duration === null) {
            $duration = $this::DEFAULT_LOCK_DURATION;
        }

        // Check if user has permission to lock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new \Exception('Must be logged in to lock objects');
        }

        // Attempt to lock the object.
        $object->lock($this->userSession, $process, $duration);

        // Save the locked object.
        $object = $this->update($object);

        // Dispatch lock event.

        $this->eventDispatcher->dispatchTyped(new ObjectLockedEvent($object));

        return $object;

    }//end lockObject()


    /**
     * Unlock an object
     *
     * @param string|int $identifier Object ID, UUID, or URI
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If unlocking fails
     *
     * @return ObjectEntity The unlocked object
     */
    public function unlockObject($identifier): ObjectEntity
    {
        $object = $this->find($identifier);

        // Check if user has permission to unlock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new \Exception('Must be logged in to unlock objects');
        }

        // Attempt to unlock the object.
        $object->unlock($this->userSession);

        // Save the unlocked object.
        $object = $this->update($object);

        // Dispatch unlock event.

        $this->eventDispatcher->dispatchTyped(new ObjectUnlockedEvent($object));

        return $object;

    }//end unlockObject()


    /**
     * Check if an object is locked
     *
     * @param string|int $identifier Object ID, UUID, or URI
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     *
     * @return bool True if object is locked, false otherwise
     */
    public function isObjectLocked($identifier): bool
    {
        $object = $this->find($identifier);
        return $object->isLocked();

    }//end isObjectLocked()


    /**
     * Find multiple objects by their IDs, UUIDs, or URIs
     *
     * @param array $ids Array of IDs, UUIDs, or URIs to find
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array An array of ObjectEntity objects
     */
    public function findMultiple(array $ids): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->orWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('slug', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uri', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));

        return $this->findEntities($qb);

    }//end findMultiple()


    /**
     * Get statistics for objects with optional filtering
     *
     * @param int|int[]|null $registerId The register ID(s) (null for all registers).
     * @param int|int[]|null $schemaId   The schema ID(s) (null for all schemas).
     * @param array          $exclude    Array of register/schema combinations to exclude, format: [['register' => id, 'schema' => id], ...].
     *
     * @phpstan-param int|array|null $registerId
     * @phpstan-param int|array|null $schemaId
     * @phpstan-param array $exclude
     *
     * @psalm-param int|array|null $registerId
     * @psalm-param int|array|null $schemaId
     * @psalm-param array $exclude
     *
     * @return array<string, int> Array containing statistics about objects:
     *               - total: Total number of objects.
     *               - size: Total size of all objects in bytes.
     *               - invalid: Number of objects with validation errors.
     *               - deleted: Number of deleted objects.
     *               - locked: Number of locked objects.
     *               - published: Number of published objects.
     */
    public function getStatistics(int|array|null $registerId = null, int|array|null $schemaId = null, array $exclude = []): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $qb->select(
                $qb->createFunction('COUNT(id) as total'),
                $qb->createFunction('COALESCE(SUM(size), 0) as size'),
                $qb->createFunction('COUNT(CASE WHEN validation IS NOT NULL THEN 1 END) as invalid'),
                $qb->createFunction('COUNT(CASE WHEN deleted IS NOT NULL THEN 1 END) as deleted'),
                $qb->createFunction('COUNT(CASE WHEN locked IS NOT NULL AND locked = TRUE THEN 1 END) as locked'),
                // Only count as published if published <= now and (depublished is null or depublished > now)
                $qb->createFunction(
                    "COUNT(CASE WHEN published IS NOT NULL AND published <= '".$now."' AND (depublished IS NULL OR depublished > '".$now."') THEN 1 END) as published"
                )
            )
                ->from($this->getTableName());

            // Add register filter if provided (support int or array)
            if ($registerId !== null) {
                if (is_array($registerId)) {
                    $qb->andWhere($qb->expr()->in('register', $qb->createNamedParameter($registerId, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
                }
            }

            // Add schema filter if provided (support int or array)
            if ($schemaId !== null) {
                if (is_array($schemaId)) {
                    $qb->andWhere($qb->expr()->in('schema', $qb->createNamedParameter($schemaId, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
                }
            }

            // Add exclusions if provided.
            if (empty($exclude) === false) {
                foreach ($exclude as $combination) {
                    $orConditions = $qb->expr()->orX();

                    // Handle register exclusion.
                    if (isset($combination['register']) === true) {
                        $orConditions->add($qb->expr()->isNull('register'));
                        $orConditions->add($qb->expr()->neq('register', $qb->createNamedParameter($combination['register'], IQueryBuilder::PARAM_INT)));
                    }

                    // Handle schema exclusion.
                    if (isset($combination['schema']) === true) {
                        $orConditions->add($qb->expr()->isNull('schema'));
                        $orConditions->add($qb->expr()->neq('schema', $qb->createNamedParameter($combination['schema'], IQueryBuilder::PARAM_INT)));
                    }

                    // Add the OR conditions to the main query.
                    if ($orConditions->count() > 0) {
                        $qb->andWhere($orConditions);
                    }
                }//end foreach
            }//end if

            $result = $qb->executeQuery()->fetch();

            return [
                'total'     => (int) ($result['total'] ?? 0),
                'size'      => (int) ($result['size'] ?? 0),
                'invalid'   => (int) ($result['invalid'] ?? 0),
                'deleted'   => (int) ($result['deleted'] ?? 0),
                'locked'    => (int) ($result['locked'] ?? 0),
                'published' => (int) ($result['published'] ?? 0),
            ];
        } catch (\Exception $e) {
            return [
                'total'     => 0,
                'size'      => 0,
                'invalid'   => 0,
                'deleted'   => 0,
                'locked'    => 0,
                'published' => 0,
            ];
        }//end try

    }//end getStatistics()


    /**
     * Get chart data for objects grouped by register
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of register names.
     *               - series: Array of object counts per register.
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Join with registers table to get register names.
            $qb->select(
                'r.title as register_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->getTableName(), 'o')
                ->leftJoin('o', 'openregister_registers', 'r', 'o.register = r.id')
                ->groupBy('r.id', 'r.title')
                ->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('o.register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(function ($row) {
                    return $row['register_name'] ?? 'Unknown';
                }, $results),
                'series' => array_map(function ($row) {
                    return (int) $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getRegisterChartData()


    /**
     * Get chart data for objects grouped by schema
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of schema names.
     *               - series: Array of object counts per schema.
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Join with schemas table to get schema names.
            $qb->select(
                's.title as schema_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->getTableName(), 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->groupBy('s.id', 's.title')
                ->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('o.register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(function ($row) {
                    return $row['schema_name'] ?? 'Unknown';
                }, $results),
                'series' => array_map(function ($row) {
                    return (int) $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getSchemaChartData()


    /**
     * Get chart data for objects grouped by size ranges
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of size range labels.
     *               - series: Array of object counts per size range.
     */
    public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Define size ranges in bytes.
            $ranges = [
                ['min' => 0, 'max' => 1024, 'label' => '0-1 KB'],
                ['min' => 1024, 'max' => 10240, 'label' => '1-10 KB'],
                ['min' => 10240, 'max' => 102400, 'label' => '10-100 KB'],
                ['min' => 102400, 'max' => 1048576, 'label' => '100 KB-1 MB'],
                ['min' => 1048576, 'max' => null, 'label' => '> 1 MB'],
            ];

            $results = [];
            foreach ($ranges as $range) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*) as count'))
                    ->from($this->getTableName());

                // Add size range conditions.
                if ($range['min'] !== null) {
                    $qb->andWhere($qb->expr()->gte('size', $qb->createNamedParameter($range['min'], IQueryBuilder::PARAM_INT)));
                }
                if ($range['max'] !== null) {
                    $qb->andWhere($qb->expr()->lt('size', $qb->createNamedParameter($range['max'], IQueryBuilder::PARAM_INT)));
                }

                // Add register filter if provided.
                if ($registerId !== null) {
                    $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
                }

                // Add schema filter if provided.
                if ($schemaId !== null) {
                    $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
                }

                $count = $qb->executeQuery()->fetchOne();
                $results[] = [
                    'label' => $range['label'],
                    'count' => (int) $count,
                ];
            }//end foreach

            return [
                'labels' => array_map(function ($row) {
                    return $row['label'];
                }, $results),
                'series' => array_map(function ($row) {
                    return $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getSizeDistributionChartData()


    /**
     * Get simple facets using the new handlers
     *
     * This method provides a simple interface to the new facet handlers.
     * It supports basic terms facets for both metadata and object fields.
     *
     * @param array $query The search query array containing filters and facet configuration
     *                     - _facets: Simple facet configuration
     *                       - @self: Metadata field facets
     *                       - Direct keys: Object field facets
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @psalm-param array<string, mixed> $query
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Simple facet data using the new handlers
     */
    public function getSimpleFacets(array $query = []): array
    {
        // Check if handlers are available
        if ($this->metaDataFacetHandler === null || $this->mariaDbFacetHandler === null) {
            return [];
        }

        // Extract facet configuration
        $facetConfig = $query['_facets'] ?? [];
        if (empty($facetConfig)) {
            return [];
        }

        // Extract base query (without facet config)
        $baseQuery = $query;
        unset($baseQuery['_facets']);

        $facets = [];

        // Process metadata facets (@self)
        if (isset($facetConfig['@self']) && is_array($facetConfig['@self'])) {
            $facets['@self'] = [];
            foreach ($facetConfig['@self'] as $field => $config) {
                $type = $config['type'] ?? 'terms';

                if ($type === 'terms') {
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getTermsFacet($field, $baseQuery);
                } else if ($type === 'date_histogram') {
                    $interval = $config['interval'] ?? 'month';
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getDateHistogramFacet($field, $interval, $baseQuery);
                } else if ($type === 'range') {
                    $ranges = $config['ranges'] ?? [];
                    $facets['@self'][$field] = $this->metaDataFacetHandler->getRangeFacet($field, $ranges, $baseQuery);
                }
            }
        }

        // Process object field facets
        $objectFacetConfig = array_filter($facetConfig, function($key) {
            return $key !== '@self';
        }, ARRAY_FILTER_USE_KEY);

        foreach ($objectFacetConfig as $field => $config) {
            $type = $config['type'] ?? 'terms';

            if ($type === 'terms') {
                $facets[$field] = $this->mariaDbFacetHandler->getTermsFacet($field, $baseQuery);
            } else if ($type === 'date_histogram') {
                $interval = $config['interval'] ?? 'month';
                $facets[$field] = $this->mariaDbFacetHandler->getDateHistogramFacet($field, $interval, $baseQuery);
            } else if ($type === 'range') {
                $ranges = $config['ranges'] ?? [];
                $facets[$field] = $this->mariaDbFacetHandler->getRangeFacet($field, $ranges, $baseQuery);
            }
        }

        return $facets;

    }//end getSimpleFacets()


    /**
     * Get facetable fields for discovery
     *
     * This method combines metadata and object field discovery to provide
     * a comprehensive list of fields that can be used for faceting.
     * It helps frontends understand what faceting options are available.
     *
     * @param array $baseQuery Base query filters to apply for context
     * @param int   $sampleSize Maximum number of objects to analyze for object fields
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Comprehensive facetable field information
     */
    public function getFacetableFields(array $baseQuery = [], int $sampleSize = 100): array
    {
        $facetableFields = [
            '@self' => [],
            'object_fields' => []
        ];

        // Get metadata facetable fields if handler is available
        if ($this->metaDataFacetHandler !== null) {
            $facetableFields['@self'] = $this->metaDataFacetHandler->getFacetableFields($baseQuery);
        }

        // Get object field facetable fields from schemas instead of analyzing objects
        $facetableFields['object_fields'] = $this->getFacetableFieldsFromSchemas($baseQuery);

        return $facetableFields;

    }//end getFacetableFields()





    /**
     * Get facetable fields from schemas
     *
     * This method analyzes schema properties to determine which fields
     * are marked as facetable in the schema definitions. This is more
     * efficient than analyzing object data and provides consistent
     * faceting based on schema definitions.
     *
     * @param array $baseQuery Base query filters to apply for context
     *
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Facetable fields with their configuration based on schema definitions
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery = []): array
    {
        $facetableFields = [];

        // Get schemas to analyze based on query context
        $schemas = $this->getSchemasForQuery($baseQuery);

        if (empty($schemas)) {
            return [];
        }

        // Process each schema's properties
        foreach ($schemas as $schema) {
            $properties = $schema->getProperties();

            if (empty($properties)) {
                continue;
            }

            // Analyze each property for facetable configuration
            foreach ($properties as $propertyKey => $property) {
                if ($this->isPropertyFacetable($property)) {
                    $fieldConfig = $this->generateFieldConfigFromProperty($propertyKey, $property);

                    if ($fieldConfig !== null) {
                        // If field already exists from another schema, merge configurations
                        if (isset($facetableFields[$propertyKey])) {
                            $facetableFields[$propertyKey] = $this->mergeFieldConfigs(
                                $facetableFields[$propertyKey],
                                $fieldConfig
                            );
                        } else {
                            $facetableFields[$propertyKey] = $fieldConfig;
                        }
                    }
                }
            }
        }

        return $facetableFields;

    }//end getFacetableFieldsFromSchemas()


    /**
     * Get schemas for query context
     *
     * Returns schemas that are relevant for the current query context.
     * If specific schemas are filtered in the query, only those are returned.
     * Otherwise, all schemas are returned.
     *
     * @param array $baseQuery Base query filters to apply
     *
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Array of Schema objects
     */
    private function getSchemasForQuery(array $baseQuery): array
    {
        $schemaFilters = [];

        // Check if specific schemas are requested in the query
        if (isset($baseQuery['@self']['schema'])) {
            $schemaValue = $baseQuery['@self']['schema'];
            if (is_array($schemaValue)) {
                $schemaFilters = $schemaValue;
            } else {
                $schemaFilters = [$schemaValue];
            }
        }

        // Get schemas from the schema mapper
        if (empty($schemaFilters)) {
            // Get all schemas
            return $this->schemaMapper->findAll();
        } else {
            // Get specific schemas
            return $this->schemaMapper->findMultiple($schemaFilters);
        }

    }//end getSchemasForQuery()


    /**
     * Check if a property is facetable
     *
     * @param array $property The property definition
     *
     * @phpstan-param array<string, mixed> $property
     *
     * @psalm-param array<string, mixed> $property
     *
     * @return bool True if the property is facetable
     */
    private function isPropertyFacetable(array $property): bool
    {
        return isset($property['facetable']) && $property['facetable'] === true;

    }//end isPropertyFacetable()


    /**
     * Generate field configuration from property definition
     *
     * @param string $propertyKey The property key
     * @param array  $property    The property definition
     *
     * @phpstan-param string $propertyKey
     * @phpstan-param array<string, mixed> $property
     *
     * @psalm-param string $propertyKey
     * @psalm-param array<string, mixed> $property
     *
     * @return array|null Field configuration or null if not suitable for faceting
     */
    private function generateFieldConfigFromProperty(string $propertyKey, array $property): ?array
    {
        $type = $property['type'] ?? 'string';
        $format = $property['format'] ?? '';
        $title = $property['title'] ?? $propertyKey;
        $description = $property['description'] ?? "Schema field: $propertyKey";
        $example = $property['example'] ?? null;

        // Determine appropriate facet types based on property type and format
        $facetTypes = $this->determineFacetTypesFromProperty($type, $format);

        if (empty($facetTypes)) {
            return null;
        }

        $config = [
            'type' => $type,
            'format' => $format,
            'title' => $title,
            'description' => $description,
            'facet_types' => $facetTypes,
            'source' => 'schema'
        ];

        // Add example if available
        if ($example !== null) {
            $config['example'] = $example;
        }

        // Add additional configuration based on type
        switch ($type) {
            case 'string':
                if ($format === 'date' || $format === 'date-time') {
                    $config['intervals'] = ['day', 'week', 'month', 'year'];
                } else {
                    $config['cardinality'] = 'text';
                }
                break;

            case 'integer':
            case 'number':
                $config['cardinality'] = 'numeric';
                if (isset($property['minimum'])) {
                    $config['minimum'] = $property['minimum'];
                }
                if (isset($property['maximum'])) {
                    $config['maximum'] = $property['maximum'];
                }
                break;

            case 'boolean':
                $config['cardinality'] = 'binary';
                break;

            case 'array':
                $config['cardinality'] = 'array';
                break;
        }

        return $config;

    }//end generateFieldConfigFromProperty()


    /**
     * Determine facet types based on property type and format
     *
     * @param string $type   The property type
     * @param string $format The property format
     *
     * @phpstan-param string $type
     * @phpstan-param string $format
     *
     * @psalm-param string $type
     * @psalm-param string $format
     *
     * @return array Array of suitable facet types
     */
    private function determineFacetTypesFromProperty(string $type, string $format): array
    {
        switch ($type) {
            case 'string':
                if ($format === 'date' || $format === 'date-time') {
                    return ['date_histogram', 'range'];
                } else if ($format === 'email' || $format === 'uri' || $format === 'uuid') {
                    return ['terms'];
                } else {
                    return ['terms'];
                }

            case 'integer':
            case 'number':
                return ['range', 'terms'];

            case 'boolean':
                return ['terms'];

            case 'array':
                return ['terms'];

            default:
                return ['terms'];
        }

    }//end determineFacetTypesFromProperty()


    /**
     * Merge field configurations from multiple schemas
     *
     * @param array $existing The existing field configuration
     * @param array $new      The new field configuration
     *
     * @phpstan-param array<string, mixed> $existing
     * @phpstan-param array<string, mixed> $new
     *
     * @psalm-param array<string, mixed> $existing
     * @psalm-param array<string, mixed> $new
     *
     * @return array Merged field configuration
     */
    private function mergeFieldConfigs(array $existing, array $new): array
    {
        // Merge facet types
        $existingFacetTypes = $existing['facet_types'] ?? [];
        $newFacetTypes = $new['facet_types'] ?? [];
        $merged = $existing;

        $merged['facet_types'] = array_unique(array_merge($existingFacetTypes, $newFacetTypes));

        // Use the more descriptive title and description if available
        if (empty($existing['title']) && !empty($new['title'])) {
            $merged['title'] = $new['title'];
        }

        if (empty($existing['description']) && !empty($new['description'])) {
            $merged['description'] = $new['description'];
        }

        // Add example if not already present
        if (!isset($existing['example']) && isset($new['example'])) {
            $merged['example'] = $new['example'];
        }

        return $merged;

    }//end mergeFieldConfigs()


    /**
     * Save multiple objects using bulk operations
     *
     * This method processes objects in optimized chunks to prevent memory issues
     * and connection timeouts. It uses dynamic batch sizing based on actual data size.
     *
     * @param array $insertObjects Array of objects to insert
     * @param array $updateObjects Array of objects to update
     *
     * @return array Array of saved object IDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<int, array<string, mixed>> $insertObjects
     * @phpstan-param array<int, ObjectEntity> $updateObjects
     * @phpstan-return array<int, string>
     * @psalm-param array<int, array<string, mixed>> $insertObjects
     * @psalm-param array<int, ObjectEntity> $updateObjects
     * @psalm-return array<int, string>
     */
    public function saveObjects(array $insertObjects = [], array $updateObjects = []): array
    {
        // Perform bulk operations within a database transaction for consistency
        $savedObjectIds = [];
        $maxRetries = 3;
        $retryCount = 0;
        
        // Calculate optimal chunk sizes based on data size to prevent max_allowed_packet errors
        $maxChunkSize = $this->calculateOptimalChunkSize($insertObjects, $updateObjects);
        $totalObjects = count($insertObjects) + count($updateObjects);
        
        error_log('[ObjectEntityMapper] Starting saveObjects with ' . $totalObjects . ' total objects (insert: ' . count($insertObjects) . ', update: ' . count($updateObjects) . ')');
        error_log('[ObjectEntityMapper] Using dynamic chunk size: ' . $maxChunkSize . ' objects per chunk');

        // Separate extremely large objects that should be processed individually
        $insertObjectGroups = $this->separateLargeObjects($insertObjects, 500000); // 500KB threshold
        $updateObjectGroups = $this->separateLargeObjects($updateObjects, 500000); // 500KB threshold
        
        $largeInsertObjects = $insertObjectGroups['large'];
        $normalInsertObjects = $insertObjectGroups['normal'];
        $largeUpdateObjects = $updateObjectGroups['large'];
        $normalUpdateObjects = $updateObjectGroups['normal'];
        
        error_log('[ObjectEntityMapper] Object separation: ' . count($normalInsertObjects) . ' normal inserts, ' . count($largeInsertObjects) . ' large inserts, ' . count($normalUpdateObjects) . ' normal updates, ' . count($largeUpdateObjects) . ' large updates');

        while ($retryCount < $maxRetries) {
            try {
                        // First, process large objects individually to prevent packet size errors
        $largeInsertIds = $this->processLargeObjectsIndividually($largeInsertObjects);
        
        // Process large update objects individually using the update method
        $largeUpdateIds = [];
        foreach ($largeUpdateObjects as $largeUpdateObject) {
            try {
                $updatedObject = $this->update($largeUpdateObject);
                if ($updatedObject && $updatedObject->getUuid()) {
                    $largeUpdateIds[] = $updatedObject->getUuid();
                }
                error_log('[ObjectEntityMapper] Successfully processed large update object individually');
            } catch (\Exception $e) {
                error_log('[ObjectEntityMapper] Error processing large update object individually: ' . $e->getMessage());
                // Continue with other objects even if one fails
            }
        }
        
        // Add large object IDs to the saved list
        $savedObjectIds = array_merge($largeInsertIds, $largeUpdateIds);
                
                // Process normal objects in chunks to avoid large transactions and packet size issues
                $insertChunks = array_chunk($normalInsertObjects, $maxChunkSize);
                $updateChunks = array_chunk($normalUpdateObjects, $maxChunkSize);
                
                $chunkNumber = 1;
                $totalChunks = count($insertChunks) + count($updateChunks);
                
                error_log('[ObjectEntityMapper] Processing ' . $totalChunks . ' chunks with max ' . $maxChunkSize . ' objects per chunk');
                
                // Process insert chunks
                foreach ($insertChunks as $insertChunk) {
                    error_log('[ObjectEntityMapper] Processing insert chunk ' . $chunkNumber . '/' . $totalChunks . ' with ' . count($insertChunk) . ' objects');
                    
                    $chunkIds = $this->processInsertChunk($insertChunk);
                    $savedObjectIds = array_merge($savedObjectIds, $chunkIds);
                    
                    // Clear memory after each chunk
                    unset($insertChunk, $chunkIds);
                    gc_collect_cycles();
                    
                    $chunkNumber++;
                }
                
                // Process update chunks
                foreach ($updateChunks as $updateChunk) {
                    error_log('[ObjectEntityMapper] Processing update chunk ' . $chunkNumber . '/' . $totalChunks . ' with ' . count($updateChunk) . ' objects');
                    
                    $chunkIds = $this->processUpdateChunk($updateChunk);
                    $savedObjectIds = array_merge($savedObjectIds, $chunkIds);
                    
                    // Clear memory after each chunk
                    unset($updateChunk, $chunkIds);
                    gc_collect_cycles();
                    
                    $chunkNumber++;
                }
                
                error_log('[ObjectEntityMapper] Successfully processed all chunks, total saved: ' . count($savedObjectIds));
                break;

            } catch (\Exception $e) {
                error_log('[ObjectEntityMapper] Error in saveObjects (attempt ' . ($retryCount + 1) . '): ' . $e->getMessage());
                
                // Check if this is a packet size error that requires smaller chunks
                $errorMessage = $e->getMessage();
                $isPacketSizeError = (
                    strpos($errorMessage, 'Got a packet bigger than \'max_allowed_packet\' bytes') !== false ||
                    strpos($errorMessage, 'max_allowed_packet') !== false ||
                    strpos($errorMessage, 'packet too large') !== false ||
                    strpos($errorMessage, 'packet size') !== false
                );
                
                // Check if this is a connection-related error that we should retry
                $isConnectionError = (
                    strpos($errorMessage, 'MySQL server has gone away') !== false ||
                    strpos($errorMessage, 'Lost connection') !== false ||
                    strpos($errorMessage, 'Connection refused') !== false ||
                    strpos($errorMessage, 'Connection timed out') !== false ||
                    strpos($errorMessage, 'Server has gone away') !== false
                );

                if ($isPacketSizeError) {
                    // Reduce chunk size more aggressively and retry with smaller batches
                    $maxChunkSize = max(1, intval($maxChunkSize * 0.3)); // Reduce by 70%, minimum 1
                    error_log('[ObjectEntityMapper] Packet size error detected, reducing chunk size to ' . $maxChunkSize . ' and retrying');
                    
                    // Rechunk the data with smaller size
                    $insertChunks = array_chunk($insertObjects, $maxChunkSize);
                    $updateChunks = array_chunk($updateObjects, $maxChunkSize);
                    continue;
                }

                if ($isConnectionError && $retryCount < $maxRetries - 1) {
                    $retryCount++;
                    error_log('[ObjectEntityMapper] Connection error detected, retrying in 5 seconds (attempt ' . ($retryCount + 1) . '/' . $maxRetries . ')');
                    
                    // Wait before retrying
                    sleep(5);
                    
                    // Try to reconnect
                    try {
                        $this->db->close();
                        $this->db->connect();
                        error_log('[ObjectEntityMapper] Reconnected to database');
                    } catch (\Exception $reconnectException) {
                        error_log('[ObjectEntityMapper] Failed to reconnect: ' . $reconnectException->getMessage());
                    }
                    
                    continue;
                }

                // Either not a retryable error or max retries reached
                throw $e;
            }
        }

        return $savedObjectIds;

    }//end saveObjects()

    /**
     * Calculate optimal chunk size based on actual data size to prevent max_allowed_packet errors
     *
     * @param array $insertObjects Array of objects to insert
     * @param array $updateObjects Array of objects to update
     *
     * @return int Optimal chunk size in number of objects
     *
     * @phpstan-param array<int, array<string, mixed>> $insertObjects
     * @phpstan-param array<int, ObjectEntity> $updateObjects
     */
    private function calculateOptimalChunkSize(array $insertObjects, array $updateObjects): int
    {
        // Start with a very conservative chunk size to prevent packet size issues
        $baseChunkSize = 25;
        
        // Sample objects to estimate data size
        $sampleSize = min(20, max(5, count($insertObjects) + count($updateObjects)));
        $sampleObjects = array_merge(
            array_slice($insertObjects, 0, intval($sampleSize / 2)),
            array_slice($updateObjects, 0, intval($sampleSize / 2))
        );
        
        if (empty($sampleObjects)) {
            return $baseChunkSize;
        }
        
        // Calculate average object size in bytes
        $totalSize = 0;
        $objectCount = 0;
        $maxObjectSize = 0;
        
        foreach ($sampleObjects as $object) {
            $objectSize = $this->estimateObjectSize($object);
            $totalSize += $objectSize;
            $maxObjectSize = max($maxObjectSize, $objectSize);
            $objectCount++;
        }
        
        if ($objectCount === 0) {
            return $baseChunkSize;
        }
        
        $averageObjectSize = $totalSize / $objectCount;
        
        // Use the maximum object size to be extra safe, not the average
        // This prevents issues when some objects are much larger than others
        $safetyObjectSize = max($averageObjectSize, $maxObjectSize);
        
        // Calculate safe chunk size based on actual max_allowed_packet value
        // Use the dynamic buffer percentage for SQL overhead, column names, and safety
        $maxPacketSize = $this->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
        $safeChunkSize = intval($maxPacketSize / $safetyObjectSize);
        
        // Ensure chunk size is within very conservative bounds
        // Maximum of 100 objects per chunk to prevent memory issues
        $optimalChunkSize = max(5, min(100, $safeChunkSize));
        
        // If we have very large objects, be extra conservative
        if ($safetyObjectSize > 1000000) { // 1MB per object
            $optimalChunkSize = max(5, min(25, $optimalChunkSize));
        }
        
        // If we have extremely large objects, be very conservative
        if ($safetyObjectSize > 5000000) { // 5MB per object
            $optimalChunkSize = max(1, min(10, $optimalChunkSize));
        }
        
        error_log('[ObjectEntityMapper] Estimated average object size: ' . number_format($averageObjectSize) . ' bytes');
        error_log('[ObjectEntityMapper] Maximum object size in sample: ' . number_format($maxObjectSize) . ' bytes');
        error_log('[ObjectEntityMapper] Using safety object size: ' . number_format($safetyObjectSize) . ' bytes');
        error_log('[ObjectEntityMapper] Calculated optimal chunk size: ' . $optimalChunkSize . ' objects');
        error_log('[ObjectEntityMapper] Max packet size buffer: ' . number_format($maxPacketSize) . ' bytes (' . ($this->maxPacketSizeBuffer * 100) . '% of ' . number_format($this->getMaxAllowedPacketSize()) . ' bytes)');
        
        return $optimalChunkSize;
        
    }//end calculateOptimalChunkSize()

    /**
     * Estimate the size of an object in bytes for chunk size calculation
     *
     * @param mixed $object The object to estimate size for
     *
     * @return int Estimated size in bytes
     */
    private function estimateObjectSize(mixed $object): int
    {
        if (is_array($object)) {
            // For array objects (insert case)
            $size = 0;
            foreach ($object as $key => $value) {
                $size += strlen($key);
                if (is_string($value)) {
                    $size += strlen($value);
                } elseif (is_array($value)) {
                    $size += strlen(json_encode($value));
                } elseif (is_numeric($value)) {
                    $size += strlen((string) $value);
                } else {
                    $size += 50; // Default estimate for other types
                }
            }
            return $size;
        } elseif (is_object($object)) {
            // For ObjectEntity objects (update case)
            $size = 0;
            $reflection = new \ReflectionClass($object);
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($object);
                
                if (is_string($value)) {
                    $size += strlen($value);
                } elseif (is_array($value)) {
                    $size += strlen(json_encode($value));
                } elseif (is_numeric($value)) {
                    $size += strlen((string) $value);
                } else {
                    $size += 50; // Default estimate for other types
                }
            }
            return $size;
        }
        
        return 1000; // Default estimate for unknown types
    }//end estimateObjectSize()

    /**
     * Calculate optimal batch size for bulk insert operations based on actual data size
     *
     * This method estimates the size of the SQL query that would be generated
     * and calculates a safe batch size to prevent max_allowed_packet errors.
     *
     * @param array $insertObjects Array of objects to insert
     * @param array $columns Array of column names
     *
     * @return int Optimal batch size in number of objects
     *
     * @phpstan-param array<int, array<string, mixed>> $insertObjects
     * @psalm-param array<int, array<string, mixed>> $insertObjects
     */
    private function calculateOptimalBatchSize(array $insertObjects, array $columns): int
    {
        // Start with a very conservative batch size to prevent packet size issues
        $baseBatchSize = 25;
        
        // Sample objects to estimate data size
        $sampleSize = min(20, max(5, count($insertObjects)));
        $sampleObjects = array_slice($insertObjects, 0, $sampleSize);
        
        if (empty($sampleObjects)) {
            return $baseBatchSize;
        }
        
        // Calculate average and maximum object size in bytes
        $totalSize = 0;
        $objectCount = 0;
        $maxObjectSize = 0;
        
        foreach ($sampleObjects as $object) {
            $objectSize = $this->estimateObjectSize($object);
            $totalSize += $objectSize;
            $maxObjectSize = max($maxObjectSize, $objectSize);
            $objectCount++;
        }
        
        if ($objectCount === 0) {
            return $baseBatchSize;
        }
        
        $averageObjectSize = $totalSize / $objectCount;
        
        // Use the maximum object size to be extra safe, not the average
        // This prevents issues when some objects are much larger than others
        $safetyObjectSize = max($averageObjectSize, $maxObjectSize);
        
        // Calculate safe batch size based on actual max_allowed_packet value
        // Use the dynamic buffer percentage for SQL overhead, column names, and safety
        $maxPacketSize = $this->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
        $safeBatchSize = intval($maxPacketSize / $safetyObjectSize);
        
        // Ensure batch size is within very conservative bounds
        // Maximum of 100 objects per batch to prevent memory issues
        $optimalBatchSize = max(5, min(100, $safeBatchSize));
        
        // If we have very large objects, be extra conservative
        if ($safetyObjectSize > 1000000) { // 1MB per object
            $optimalBatchSize = max(5, min(25, $optimalBatchSize));
        }
        
        // If we have extremely large objects, be very conservative
        if ($safetyObjectSize > 5000000) { // 5MB per object
            $optimalBatchSize = max(1, min(10, $optimalBatchSize));
        }
        
        error_log('[Bulk Insert] Estimated average object size: ' . number_format($averageObjectSize) . ' bytes');
        error_log('[Bulk Insert] Maximum object size in sample: ' . number_format($maxObjectSize) . ' bytes');
        error_log('[Bulk Insert] Using safety object size: ' . number_format($safetyObjectSize) . ' bytes');
        error_log('[Bulk Insert] Calculated optimal batch size: ' . $optimalBatchSize . ' objects');
        error_log('[Bulk Insert] Max packet size buffer: ' . number_format($maxPacketSize) . ' bytes (' . ($this->maxPacketSizeBuffer * 100) . '% of ' . number_format($this->getMaxAllowedPacketSize()) . ' bytes)');
        
        return $optimalBatchSize;
        
    }//end calculateOptimalBatchSize()

    /**
     * Process a single chunk of insert objects within a transaction
     *
     * @param array $insertChunk Array of objects to insert
     *
     * @return array Array of inserted object UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<int, array<string, mixed>> $insertChunk
     * @psalm-param array<int, array<string, mixed>> $insertChunk
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function processInsertChunk(array $insertChunk): array
    {
        $transactionStarted = false;
        
        try {
            // Start a new transaction for this chunk
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }
            
            // Process the insert chunk
            $insertedIds = $this->bulkInsert($insertChunk);
            
            // Commit transaction if we started it
            if ($transactionStarted === true) {
                $this->db->commit();
            }
            
            return $insertedIds;
            
        } catch (\Exception $e) {
            // Rollback transaction if we started it
            if ($transactionStarted === true) {
                try {
                    $this->db->rollBack();
                } catch (\Exception $rollbackException) {
                    error_log('[ObjectEntityMapper] Error during rollback: ' . $rollbackException->getMessage());
                }
            }
            throw $e;
        }
    }

    /**
     * Process a single chunk of update objects within a transaction
     *
     * @param array $updateChunk Array of ObjectEntity instances to update
     *
     * @return array Array of updated object UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<int, ObjectEntity> $updateChunk
     * @psalm-param array<int, ObjectEntity> $updateChunk
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function processUpdateChunk(array $updateChunk): array
    {
        $transactionStarted = false;
        
        try {
            // Start a new transaction for this chunk
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }
            
            // Process the update chunk
            $updatedIds = $this->bulkUpdate($updateChunk);
            
            // Commit transaction if we started it
            if ($transactionStarted === true) {
                $this->db->commit();
            }
            
            return $updatedIds;
            
        } catch (\Exception $e) {
            // Rollback transaction if we started it
            if ($transactionStarted === true) {
                try {
                    $this->db->rollBack();
                } catch (\Exception $rollbackException) {
                    error_log('[ObjectEntityMapper] Error during rollback: ' . $rollbackException->getMessage());
                }
            }
            throw $e;
        }
    }





    /**
     * Perform true bulk insert of objects using single SQL statement
     *
     * This method uses a single INSERT statement with multiple VALUES for optimal performance.
     * It bypasses individual entity creation and event dispatching for maximum speed.
     * 
     * The 'object' field is automatically JSON-encoded when it contains array data to ensure
     * proper database storage and prevent constraint violations.
     *
     * @param array $insertObjects Array of objects to insert
     *
     * @return array Array of inserted object UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<int, array<string, mixed>> $insertObjects
     * @psalm-param array<int, array<string, mixed>> $insertObjects
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function bulkInsert(array $insertObjects): array
    {
        if (empty($insertObjects)) {
            error_log('[Bulk Insert] No objects to insert');
            return [];
        }

        error_log('[Bulk Insert] Starting bulk insert of ' . count($insertObjects) . ' objects');

        // Use the proper table name method to avoid prefix issues @todo: make dynamic
        $tableName = 'oc_openregister_objects';
        error_log('[Bulk Insert] Using table: ' . $tableName);
        
        // Get the first object to determine column structure
        $firstObject = $insertObjects[0];
        $columns = array_keys($firstObject);
        error_log('[Bulk Insert] Columns: ' . implode(', ', $columns));
        
        // Calculate optimal batch size based on actual data size to prevent max_allowed_packet errors
        $batchSize = $this->calculateOptimalBatchSize($insertObjects, $columns);
        $insertedIds = [];
        
        error_log('[Bulk Insert] Using dynamic batch size: ' . $batchSize . ' objects per batch');
        
        for ($i = 0; $i < count($insertObjects); $i += $batchSize) {
            $batch = array_slice($insertObjects, $i, $batchSize);
            $batchNumber = ($i / $batchSize) + 1;
            $totalBatches = ceil(count($insertObjects) / $batchSize);
            
            error_log('[Bulk Insert] Processing batch ' . $batchNumber . '/' . $totalBatches . ' with ' . count($batch) . ' objects');
            
            // Check database connection health before processing batch
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (\Exception $e) {
                error_log('[Bulk Insert] Database connection check failed: ' . $e->getMessage());
                throw new \OCP\DB\Exception('Database connection lost during bulk insert', 0, $e);
            }
            
            // Build VALUES clause for this batch
            $valuesClause = [];
            $parameters = [];
            $paramIndex = 0;
            
            foreach ($batch as $objectData) {
                $rowValues = [];
                foreach ($columns as $column) {
                    $paramName = 'param_' . $paramIndex . '_' . $column;
                    $rowValues[] = ':' . $paramName;
                    
                    $value = $objectData[$column] ?? null;
                    
                    // JSON encode the object field if it's an array
                    if ($column === 'object' && is_array($value)) {
                        $value = json_encode($value);
                    }
                    
                    $parameters[$paramName] = $value;
                    $paramIndex++;
                }
                $valuesClause[] = '(' . implode(', ', $rowValues) . ')';
            }
            
            // Build the complete INSERT statement for this batch
            $batchSql = "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES " . implode(', ', $valuesClause);
            error_log('[Bulk Insert] SQL: ' . substr($batchSql, 0, 200) . '...');
            
            // Execute the batch insert with retry logic and packet size error handling
            $maxBatchRetries = 3;
            $batchRetryCount = 0;
            $batchSuccess = false;
            $currentBatchSize = $batchSize;
            
            while ($batchRetryCount <= $maxBatchRetries && !$batchSuccess) {
                try {
                    $stmt = $this->db->prepare($batchSql);
                    $result = $stmt->execute($parameters);
                    
                    if ($result) {
                        error_log('[Bulk Insert] Batch ' . $batchNumber . ' executed successfully');
                        $batchSuccess = true;
                    } else {
                        throw new \Exception('Statement execution returned false');
                    }
                    
                } catch (\Exception $e) {
                    $batchRetryCount++;
                    $errorMessage = $e->getMessage();
                    error_log('[Bulk Insert] Error executing batch ' . $batchNumber . ' (attempt ' . $batchRetryCount . '): ' . $errorMessage);
                    
                    // Check if this is a packet size error
                    $isPacketSizeError = (
                        strpos($errorMessage, 'Got a packet bigger than \'max_allowed_packet\' bytes') !== false ||
                        strpos($errorMessage, 'max_allowed_packet') !== false ||
                        strpos($errorMessage, 'packet too large') !== false ||
                        strpos($errorMessage, 'packet size') !== false
                    );
                    
                    if ($isPacketSizeError && $currentBatchSize > 1) {
                        // Reduce batch size more aggressively and retry with smaller batch
                        $currentBatchSize = max(1, intval($currentBatchSize * 0.3)); // Reduce by 70%, minimum 1
                        error_log('[Bulk Insert] Packet size error detected, reducing batch size to ' . $currentBatchSize . ' and retrying');
                        
                        // Recreate the batch with smaller size
                        $batch = array_slice($insertObjects, $i, $currentBatchSize);
                        $valuesClause = [];
                        $parameters = [];
                        $paramIndex = 0;
                        
                        foreach ($batch as $objectData) {
                            $rowValues = [];
                            foreach ($columns as $column) {
                                $paramName = 'param_' . $paramIndex . '_' . $column;
                                $rowValues[] = ':' . $paramName;
                                
                                $value = $objectData[$column] ?? null;
                                
                                if ($column === 'object' && is_array($value)) {
                                    $value = json_encode($value);
                                }
                                
                                $parameters[$paramName] = $value;
                                $paramIndex++;
                            }
                            $valuesClause[] = '(' . implode(', ', $rowValues) . ')';
                        }
                        
                        $batchSql = "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES " . implode(', ', $valuesClause);
                        continue;
                    }
                    
                    if ($batchRetryCount <= $maxBatchRetries) {
                        error_log('[Bulk Insert] Retrying batch ' . $batchNumber . ' in 2 seconds...');
                        sleep(2);
                        
                        // Try to reconnect if it's a connection error
                        if (strpos($errorMessage, 'MySQL server has gone away') !== false) {
                            try {
                                $this->db->close();
                                $this->db->connect();
                                error_log('[Bulk Insert] Reconnected to database for batch retry');
                            } catch (\Exception $reconnectException) {
                                error_log('[Bulk Insert] Failed to reconnect: ' . $reconnectException->getMessage());
                            }
                        }
                    } else {
                        error_log('[Bulk Insert] Max retries reached for batch ' . $batchNumber . ', failing');
                        throw $e;
                    }
                }
            }
            
            // Collect UUIDs from the inserted objects for return
            foreach ($batch as $objectData) {
                if (isset($objectData['uuid'])) {
                    $insertedIds[] = $objectData['uuid'];
                }
            }
            
            // Clear batch variables to free memory
            unset($batch, $valuesClause, $parameters, $batchSql);
            gc_collect_cycles();
            
            error_log('[Bulk Insert] Completed batch ' . $batchNumber . '/' . $totalBatches);
        }
        
        error_log('[Bulk Insert] Completed bulk insert, returning ' . count($insertedIds) . ' UUIDs');
        return $insertedIds;

    }//end bulkInsert()


    /**
     * Perform bulk update of objects using optimized SQL
     *
     * This method uses CASE statements for efficient bulk updates.
     * It bypasses individual entity updates for maximum performance.
     *
     * @param array $updateObjects Array of ObjectEntity instances to update
     *
     * @return array Array of updated object UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @phpstan-param array<int, ObjectEntity> $updateObjects
     * @psalm-param array<int, ObjectEntity> $updateObjects
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function bulkUpdate(array $updateObjects): array
    {
        if (empty($updateObjects)) {
            return [];
        }

        // Use the proper table name method to avoid prefix issues @todo: make dynamic
        $tableName = 'openregister_objects';
        $updatedIds = [];
        
        // Process each object individually for better compatibility
        foreach ($updateObjects as $object) {
            $dbId = $object->getId();
            if ($dbId === null) {
                continue; // Skip objects without database ID
            }
            
            // Get all column names from the object
            $columns = $this->getEntityColumns($object);
            
            // Build UPDATE statement for this object
            $qb = $this->db->getQueryBuilder();
            $qb->update($tableName);
            
            // Set values for each column
            foreach ($columns as $column) {
                if ($column === 'id') {
                    continue; // Skip primary key
                }
                
                $value = $this->getEntityValue($object, $column);
                $qb->set($column, $qb->createNamedParameter($value));
            }
            
            // Add WHERE clause for this specific ID
            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)));
            
            // Execute the update for this object
            $qb->executeStatement();
            
            // Collect UUID for return (findAll() accepts UUIDs)
            $updatedIds[] = $object->getUuid();
        }
        
        return $updatedIds;

    }//end bulkUpdate()


    /**
     * Get all column names from an entity for bulk operations
     *
     * @param ObjectEntity $entity The entity to extract columns from
     *
     * @return array Array of column names
     *
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function getEntityColumns(ObjectEntity $entity): array
    {
        // Get all field types to determine which fields are database columns
        $fieldTypes = $entity->getFieldTypes();
        $columns = [];
        
        foreach ($fieldTypes as $fieldName => $fieldType) {
            // Skip virtual fields that don't exist in the database
            if ($fieldType !== 'virtual') {
                // Skip schemaVersion column for now in bulk operations
                if ($fieldName === 'schemaVersion') {
                    continue;
                }
                $columns[] = $fieldName;
            }
        }
        
        return $columns;

    }//end getEntityColumns()


    /**
     * Get the value of a specific column from an entity
     *
     * This method retrieves the raw value from the entity property and performs
     * necessary transformations for database storage. The 'object' field is 
     * automatically JSON-encoded when it contains array data, and DateTime objects
     * are converted to the appropriate database format.
     *
     * @param ObjectEntity $entity The entity to get the value from
     * @param string       $column The column name
     *
     * @return mixed The column value, with proper transformations applied for database storage
     */
    private function getEntityValue(ObjectEntity $entity, string $column): mixed
    {
        // Use reflection to get the value of the property
        $reflection = new \ReflectionClass($entity);
        
        try {
            $property = $reflection->getProperty($column);
            $property->setAccessible(true);
            $value = $property->getValue($entity);
        } catch (\ReflectionException $e) {
            // If property doesn't exist, try to get it using getter method
            $getterMethod = 'get' . ucfirst($column);
            if (method_exists($entity, $getterMethod)) {
                $value = $entity->$getterMethod();
            } else {
                return null;
            }
        }
        
        // Handle DateTime objects by converting them to database format
        if ($value instanceof \DateTime) {
            $value = $value->format('Y-m-d H:i:s');
        }
        
        // Handle boolean values by converting them to integers for database storage
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        
        // Handle null values explicitly
        if ($value === null) {
            return null;
        }
        
        // JSON encode the object field if it's an array
        if ($column === 'object' && is_array($value)) {
            $value = json_encode($value);
        }
        
        // Handle other array values that might need JSON encoding
        if (is_array($value) && in_array($column, ['files', 'relations', 'locked', 'authorization', 'deleted', 'validation'])) {
            $value = json_encode($value);
        }
        
        return $value;

    }//end getEntityValue()


    /**
     * Perform bulk delete operations on objects by UUID
     *
     * This method handles both soft delete and hard delete based on the current state
     * of the objects. If an object has no deleted value set, it performs a soft delete
     * by setting the deleted timestamp. If an object already has a deleted value set,
     * it performs a hard delete by removing the object from the database.
     *
     * @param array $uuids Array of object UUIDs to delete
     *
     * @return array Array of UUIDs of deleted objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function bulkDelete(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        error_log('[Bulk Delete] Starting bulk delete of ' . count($uuids) . ' objects');

        // Use the proper table name method to avoid prefix issues
        $tableName = $this->getTableName();
        $deletedIds = [];
        
        // Process deletes in smaller chunks to prevent connection issues
        $chunkSize = 500;
        $chunks = array_chunk($uuids, $chunkSize);
        $totalChunks = count($chunks);
        
        error_log('[Bulk Delete] Processing ' . $totalChunks . ' chunks with max ' . $chunkSize . ' objects per chunk');
        
        foreach ($chunks as $chunkIndex => $uuidChunk) {
            $chunkNumber = $chunkIndex + 1;
            error_log('[Bulk Delete] Processing chunk ' . $chunkNumber . '/' . $totalChunks . ' with ' . count($uuidChunk) . ' objects');
            
            // Check database connection health before processing chunk
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (\Exception $e) {
                error_log('[Bulk Delete] Database connection check failed: ' . $e->getMessage());
                throw new \OCP\DB\Exception('Database connection lost during bulk delete', 0, $e);
            }
            
            // First, get the current state of objects to determine soft vs hard delete
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid', 'deleted')
                ->from($tableName)
                ->where($qb->expr()->in('uuid', $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            
            $objects = $qb->execute()->fetchAll();
            
            // Separate objects for soft delete and hard delete
            $softDeleteIds = [];
            $hardDeleteIds = [];
            
            foreach ($objects as $object) {
                if (empty($object['deleted'])) {
                    // No deleted value set - perform soft delete
                    $softDeleteIds[] = $object['id'];
                } else {
                    // Already has deleted value - perform hard delete
                    $hardDeleteIds[] = $object['id'];
                }
                $deletedIds[] = $object['uuid'];
            }
            
            // Perform soft deletes (set deleted timestamp)
            if (!empty($softDeleteIds)) {
                $currentTime = (new \DateTime())->format('Y-m-d H:i:s');
                $qb = $this->db->getQueryBuilder();
                $qb->update($tableName)
                    ->set('deleted', $qb->createNamedParameter(json_encode([
                        'timestamp' => $currentTime,
                        'reason' => 'bulk_delete'
                    ])))
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($softDeleteIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                
                $qb->executeStatement();
                error_log('[Bulk Delete] Soft deleted ' . count($softDeleteIds) . ' objects in chunk ' . $chunkNumber);
            }
            
            // Perform hard deletes (remove from database)
            if (!empty($hardDeleteIds)) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($tableName)
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($hardDeleteIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                
                $qb->executeStatement();
                error_log('[Bulk Delete] Hard deleted ' . count($hardDeleteIds) . ' objects in chunk ' . $chunkNumber);
            }
            
            // Clear chunk variables to free memory
            unset($uuidChunk, $objects, $softDeleteIds, $hardDeleteIds);
            gc_collect_cycles();
            
            error_log('[Bulk Delete] Completed chunk ' . $chunkNumber . '/' . $totalChunks);
        }
        
        error_log('[Bulk Delete] Completed bulk delete, returning ' . count($deletedIds) . ' UUIDs');
        return $deletedIds;

    }//end bulkDelete()


    /**
     * Perform bulk publish operations on objects by UUID
     *
     * This method sets the published timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the published timestamp.
     *
     * @param array         $uuids    Array of object UUIDs to publish
     * @param DateTime|bool $datetime Optional datetime for publishing (false to unset)
     *
     * @return array Array of UUIDs of published objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function bulkPublish(array $uuids, \DateTime|bool $datetime = true): array
    {
        if (empty($uuids)) {
            return [];
        }

        error_log('[Bulk Publish] Starting bulk publish of ' . count($uuids) . ' objects');

        // Use the proper table name method to avoid prefix issues
        $tableName = $this->getTableName();
        
        // Determine the published value based on the datetime parameter
        if ($datetime === false) {
            // Unset published timestamp
            $publishedValue = null;
        } elseif ($datetime instanceof \DateTime) {
            // Use provided datetime
            $publishedValue = $datetime->format('Y-m-d H:i:s');
        } else {
            // Use current datetime
            $publishedValue = (new \DateTime())->format('Y-m-d H:i:s');
        }
        
        // Process publishes in smaller chunks to prevent connection issues
        $chunkSize = 500;
        $chunks = array_chunk($uuids, $chunkSize);
        $totalChunks = count($chunks);
        $publishedIds = [];
        
        error_log('[Bulk Publish] Processing ' . $totalChunks . ' chunks with max ' . $chunkSize . ' objects per chunk');
        
        foreach ($chunks as $chunkIndex => $uuidChunk) {
            $chunkNumber = $chunkIndex + 1;
            error_log('[Bulk Publish] Processing chunk ' . $chunkNumber . '/' . $totalChunks . ' with ' . count($uuidChunk) . ' objects');
            
            // Check database connection health before processing chunk
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (\Exception $e) {
                error_log('[Bulk Publish] Database connection check failed: ' . $e->getMessage());
                throw new \OCP\DB\Exception('Database connection lost during bulk publish', 0, $e);
            }
            
            // Get object IDs for the UUIDs in this chunk
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid')
                ->from($tableName)
                ->where($qb->expr()->in('uuid', $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            
            $objects = $qb->execute()->fetchAll();
            $objectIds = array_column($objects, 'id');
            $chunkPublishedIds = array_column($objects, 'uuid');
            
            if (!empty($objectIds)) {
                // Update published timestamp for this chunk
                $qb = $this->db->getQueryBuilder();
                $qb->update($tableName);
                
                if ($publishedValue === null) {
                    $qb->set('published', $qb->createNamedParameter(null));
                } else {
                    $qb->set('published', $qb->createNamedParameter($publishedValue));
                }
                
                $qb->where($qb->expr()->in('id', $qb->createNamedParameter($objectIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                
                $qb->executeStatement();
                error_log('[Bulk Publish] Published ' . count($objectIds) . ' objects in chunk ' . $chunkNumber);
            }
            
            // Add chunk results to total results
            $publishedIds = array_merge($publishedIds, $chunkPublishedIds);
            
            // Clear chunk variables to free memory
            unset($uuidChunk, $objects, $objectIds, $chunkPublishedIds);
            gc_collect_cycles();
            
            error_log('[Bulk Publish] Completed chunk ' . $chunkNumber . '/' . $totalChunks);
        }
        
        error_log('[Bulk Publish] Completed bulk publish, returning ' . count($publishedIds) . ' UUIDs');
        return $publishedIds;

    }//end bulkPublish()


    /**
     * Perform bulk depublish operations on objects by UUID
     *
     * This method sets the depublished timestamp for the specified objects.
     * If a datetime is provided, it uses that value; otherwise, it uses the current datetime.
     * If false is provided, it unsets the depublished timestamp.
     *
     * @param array         $uuids    Array of object UUIDs to depublish
     * @param DateTime|bool $datetime Optional datetime for depublishing (false to unset)
     *
     * @return array Array of UUIDs of depublished objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    private function bulkDepublish(array $uuids, \DateTime|bool $datetime = true): array
    {
        if (empty($uuids)) {
            return [];
        }

        error_log('[Bulk Depublish] Starting bulk depublish of ' . count($uuids) . ' objects');

        // Use the proper table name method to avoid prefix issues
        $tableName = $this->getTableName();
        
        // Determine the depublished value based on the datetime parameter
        if ($datetime === false) {
            // Unset depublished timestamp
            $depublishedValue = null;
        } elseif ($datetime instanceof \DateTime) {
            // Use provided datetime
            $depublishedValue = $datetime->format('Y-m-d H:i:s');
        } else {
            // Use current datetime
            $depublishedValue = (new \DateTime())->format('Y-m-d H:i:s');
        }
        
        // Process depublishes in smaller chunks to prevent connection issues
        $chunkSize = 500;
        $chunks = array_chunk($uuids, $chunkSize);
        $totalChunks = count($chunks);
        $depublishedIds = [];
        
        error_log('[Bulk Depublish] Processing ' . $totalChunks . ' chunks with max ' . $chunkSize . ' objects per chunk');
        
        foreach ($chunks as $chunkIndex => $uuidChunk) {
            $chunkNumber = $chunkIndex + 1;
            error_log('[Bulk Depublish] Processing chunk ' . $chunkNumber . '/' . $totalChunks . ' with ' . count($uuidChunk) . ' objects');
            
            // Check database connection health before processing chunk
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (\Exception $e) {
                error_log('[Bulk Depublish] Database connection check failed: ' . $e->getMessage());
                throw new \OCP\DB\Exception('Database connection lost during bulk depublish', 0, $e);
            }
            
            // Get object IDs for the UUIDs in this chunk
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid')
                ->from($tableName)
                ->where($qb->expr()->in('uuid', $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            
            $objects = $qb->execute()->fetchAll();
            $objectIds = array_column($objects, 'id');
            $chunkDepublishedIds = array_column($objects, 'uuid');
            
            if (!empty($objectIds)) {
                // Update depublished timestamp for this chunk
                $qb = $this->db->getQueryBuilder();
                $qb->update($tableName);
                
                if ($depublishedValue === null) {
                    $qb->set('depublished', $qb->createNamedParameter(null));
                } else {
                    $qb->set('depublished', $qb->createNamedParameter($depublishedValue));
                }
                
                $qb->where($qb->expr()->in('id', $qb->createNamedParameter($objectIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                
                $qb->executeStatement();
                error_log('[Bulk Depublish] Depublished ' . count($objectIds) . ' objects in chunk ' . $chunkNumber);
            }
            
            // Add chunk results to total results
            $depublishedIds = array_merge($depublishedIds, $chunkDepublishedIds);
            
            // Clear chunk variables to free memory
            unset($uuidChunk, $objects, $objectIds, $chunkDepublishedIds);
            gc_collect_cycles();
            
            error_log('[Bulk Depublish] Completed chunk ' . $chunkNumber . '/' . $totalChunks);
        }
        
        error_log('[Bulk Depublish] Completed bulk depublish, returning ' . count($depublishedIds) . ' UUIDs');
        return $depublishedIds;

    }//end bulkDepublish()


    /**
     * Perform bulk delete operations on objects by UUID
     *
     * This method handles both soft delete and hard delete based on the current state
     * of the objects. If an object has no deleted value set, it performs a soft delete
     * by setting the deleted timestamp. If an object already has a deleted value set,
     * it performs a hard delete by removing the object from the database.
     *
     * @param array $uuids Array of object UUIDs to delete
     *
     * @return array Array of UUIDs of deleted objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    public function deleteObjects(array $uuids = []): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Perform bulk operations within a database transaction for consistency
        $deletedObjectIds = [];
        $transactionStarted = false;

        try {
            // Check if there's already an active transaction
            if ($this->db->inTransaction() === false) {
                // Start database transaction only if none exists
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Bulk delete objects
            $deletedIds = $this->bulkDelete($uuids);
            $deletedObjectIds = array_merge($deletedObjectIds, $deletedIds);

            // Commit transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->commit();
            }

        } catch (\Exception $e) {
            // Rollback transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }

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
     *
     * @return array Array of UUIDs of published objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    public function publishObjects(array $uuids = [], \DateTime|bool $datetime = true): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Perform bulk operations within a database transaction for consistency
        $publishedObjectIds = [];
        $transactionStarted = false;

        try {
            // Check if there's already an active transaction
            if ($this->db->inTransaction() === false) {
                // Start database transaction only if none exists
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Bulk publish objects
            $publishedIds = $this->bulkPublish($uuids, $datetime);
            $publishedObjectIds = array_merge($publishedObjectIds, $publishedIds);

            // Commit transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->commit();
            }

        } catch (\Exception $e) {
            // Rollback transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }

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
     *
     * @return array Array of UUIDs of depublished objects
     *
     * @phpstan-param array<int, string> $uuids
     * @psalm-param array<int, string> $uuids
     * @phpstan-return array<int, string>
     * @psalm-return array<int, string>
     */
    public function depublishObjects(array $uuids = [], \DateTime|bool $datetime = true): array
    {
        if (empty($uuids)) {
            return [];
        }

        // Perform bulk operations within a database transaction for consistency
        $depublishedObjectIds = [];
        $transactionStarted = false;

        try {
            // Check if there's already an active transaction
            if ($this->db->inTransaction() === false) {
                // Start database transaction only if none exists
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Bulk depublish objects
            $depublishedIds = $this->bulkDepublish($uuids, $datetime);
            $depublishedObjectIds = array_merge($depublishedObjectIds, $depublishedIds);

            // Commit transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->commit();
            }

        } catch (\Exception $e) {
            // Rollback transaction only if we started it
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $depublishedObjectIds;

    }//end depublishObjects()

    /**
     * Detect and separate extremely large objects that should be processed individually
     *
     * @param array $objects Array of objects to check
     * @param int $maxSafeSize Maximum safe size in bytes for batch processing
     *
     * @return array Array with 'large' and 'normal' object arrays
     *
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @phpstan-param int $maxSafeSize
     * @phpstan-return array{large: array<int, array<string, mixed>>, normal: array<int, array<string, mixed>>}
     */
    private function separateLargeObjects(array $objects, int $maxSafeSize = 1000000): array
    {
        $largeObjects = [];
        $normalObjects = [];
        
        foreach ($objects as $index => $object) {
            $objectSize = $this->estimateObjectSize($object);
            
            if ($objectSize > $maxSafeSize) {
                error_log('[ObjectEntityMapper] Large object detected at index ' . $index . ' with size ' . number_format($objectSize) . ' bytes, will process individually');
                $largeObjects[] = $object;
            } else {
                $normalObjects[] = $object;
            }
        }
        
        error_log('[ObjectEntityMapper] Separated objects: ' . count($normalObjects) . ' normal, ' . count($largeObjects) . ' large');
        
        return [
            'large' => $largeObjects,
            'normal' => $normalObjects
        ];
    }

    /**
     * Process large objects individually to prevent packet size errors
     * 
     * Note: This method is designed for INSERT operations and expects array data.
     * For UPDATE operations, use the individual update() method instead.
     *
     * @param array $largeObjects Array of large objects to process (must be arrays for INSERT)
     *
     * @return array Array of processed object UUIDs
     *
     * @phpstan-param array<int, array<string, mixed>> $largeObjects
     * @phpstan-return array<int, string>
     */
    private function processLargeObjectsIndividually(array $largeObjects): array
    {
        if (empty($largeObjects)) {
            return [];
        }
        
        error_log('[ObjectEntityMapper] Processing ' . count($largeObjects) . ' large objects individually');
        
        $processedIds = [];
        $tableName = 'oc_openregister_objects';
        
        foreach ($largeObjects as $index => $objectData) {
            try {
                error_log('[ObjectEntityMapper] Processing large object ' . ($index + 1) . '/' . count($largeObjects));
                
                // Ensure we have array data for INSERT operations
                if (!is_array($objectData)) {
                    error_log('[ObjectEntityMapper] Skipping large object ' . ($index + 1) . ' - not array data, cannot process as INSERT');
                    continue;
                }
                
                // Get columns from the object
                $columns = array_keys($objectData);
                
                // Build single INSERT statement
                $placeholders = ':' . implode(', :', $columns);
                $sql = "INSERT INTO {$tableName} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
                
                // Prepare parameters
                $parameters = [];
                foreach ($columns as $column) {
                    $value = $objectData[$column] ?? null;
                    
                    // JSON encode the object field if it's an array
                    if ($column === 'object' && is_array($value)) {
                        $value = json_encode($value);
                    }
                    
                    $parameters[':' . $column] = $value;
                }
                
                // Execute single insert
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute($parameters);
                
                if ($result && isset($objectData['uuid'])) {
                    $processedIds[] = $objectData['uuid'];
                    error_log('[ObjectEntityMapper] Successfully processed large object ' . ($index + 1));
                }
                
                // Clear memory after each large object
                unset($parameters, $sql);
                gc_collect_cycles();
                
            } catch (\Exception $e) {
                error_log('[ObjectEntityMapper] Error processing large object ' . ($index + 1) . ': ' . $e->getMessage());
                
                // If it's still a packet size error, log it but continue
                if (strpos($e->getMessage(), 'max_allowed_packet') !== false) {
                    error_log('[ObjectEntityMapper] Large object ' . ($index + 1) . ' still too large for database, skipping');
                } else {
                    // Re-throw non-packet size errors
                    throw $e;
                }
            }
        }
        
        error_log('[ObjectEntityMapper] Completed processing large objects, successful: ' . count($processedIds));
        return $processedIds;
    }

    /**
     * Calculate optimal chunk size based on actual data size to prevent max_allowed_packet errors
     */


    /**
     * Bulk assign default owner and organization to objects that don't have them assigned.
     *
     * This method updates objects in batches to assign default values where they are missing.
     * It only updates objects that have null or empty values for owner or organization.
     *
     * @param string|null $defaultOwner Default owner to assign to objects without an owner
     * @param string|null $defaultOrganisation Default organization UUID to assign to objects without an organization
     * @param int $batchSize Number of objects to process in each batch (default: 1000)
     *
     * @return array Array containing statistics about the bulk operation
     * @throws \Exception If the bulk operation fails
     */
    public function bulkOwnerDeclaration(?string $defaultOwner = null, ?string $defaultOrganisation = null, int $batchSize = 1000): array
    {
        if ($defaultOwner === null && $defaultOrganisation === null) {
            throw new \InvalidArgumentException('At least one of defaultOwner or defaultOrganisation must be provided');
        }

        $results = [
            'totalProcessed' => 0,
            'ownersAssigned' => 0,
            'organisationsAssigned' => 0,
            'errors' => [],
            'startTime' => new \DateTime(),
        ];

        try {
            $offset = 0;
            $hasMoreRecords = true;

            while ($hasMoreRecords) {
                // Build query to find objects without owner or organization
                $qb = $this->db->getQueryBuilder();
                $qb->select('id', 'uuid', 'owner', 'organisation')
                   ->from($this->tableName)
                   ->setMaxResults($batchSize)
                   ->setFirstResult($offset);

                // Add conditions for missing owner or organization
                $conditions = [];
                if ($defaultOwner !== null) {
                    $conditions[] = $qb->expr()->orX(
                        $qb->expr()->isNull('owner'),
                        $qb->expr()->eq('owner', $qb->createNamedParameter(''))
                    );
                }
                if ($defaultOrganisation !== null) {
                    $conditions[] = $qb->expr()->orX(
                        $qb->expr()->isNull('organisation'),
                        $qb->expr()->eq('organisation', $qb->createNamedParameter(''))
                    );
                }

                if (!empty($conditions)) {
                    $qb->where($qb->expr()->orX(...$conditions));
                }

                $result = $qb->executeQuery();
                $objects = $result->fetchAll();

                if (empty($objects)) {
                    $hasMoreRecords = false;
                    break;
                }

                // Process batch of objects
                $batchResults = $this->processBulkOwnerDeclarationBatch($objects, $defaultOwner, $defaultOrganisation);
                
                // Update statistics
                $results['totalProcessed'] += count($objects);
                $results['ownersAssigned'] += $batchResults['ownersAssigned'];
                $results['organisationsAssigned'] += $batchResults['organisationsAssigned'];
                $results = array_merge_recursive($results, ['errors' => $batchResults['errors']]);

                $offset += $batchSize;

                // If we got fewer records than the batch size, we're done
                if (count($objects) < $batchSize) {
                    $hasMoreRecords = false;
                }
            }

            $results['endTime'] = new \DateTime();
            $results['duration'] = $results['endTime']->diff($results['startTime'])->format('%H:%I:%S');

            return $results;

        } catch (\Exception $e) {
            error_log('[BulkOwnerDeclaration] Error during bulk owner declaration: ' . $e->getMessage());
            throw new \RuntimeException('Bulk owner declaration failed: ' . $e->getMessage());
        }
    }//end bulkOwnerDeclaration()


    /**
     * Process a batch of objects for bulk owner declaration.
     *
     * @param array $objects Array of object data from database
     * @param string|null $defaultOwner Default owner to assign
     * @param string|null $defaultOrganisation Default organization UUID to assign
     *
     * @return array Batch processing results
     */
    private function processBulkOwnerDeclarationBatch(array $objects, ?string $defaultOwner, ?string $defaultOrganisation): array
    {
        $batchResults = [
            'ownersAssigned' => 0,
            'organisationsAssigned' => 0,
            'errors' => []
        ];

        foreach ($objects as $objectData) {
            try {
                $needsUpdate = false;
                $updateData = [];

                // Check if owner needs to be assigned
                if ($defaultOwner !== null && (empty($objectData['owner']) || $objectData['owner'] === null)) {
                    $updateData['owner'] = $defaultOwner;
                    $needsUpdate = true;
                    $batchResults['ownersAssigned']++;
                }

                // Check if organization needs to be assigned
                if ($defaultOrganisation !== null && (empty($objectData['organisation']) || $objectData['organisation'] === null)) {
                    $updateData['organisation'] = $defaultOrganisation;
                    $needsUpdate = true;
                    $batchResults['organisationsAssigned']++;
                }

                // Update the object if needed
                if ($needsUpdate) {
                    $this->updateObjectOwnership((int)$objectData['id'], $updateData);
                }

            } catch (\Exception $e) {
                $error = 'Error updating object ' . $objectData['uuid'] . ': ' . $e->getMessage();
                error_log('[BulkOwnerDeclaration] ' . $error);
                $batchResults['errors'][] = $error;
            }
        }

        return $batchResults;
    }//end processBulkOwnerDeclarationBatch()


    /**
     * Update ownership information for a specific object.
     *
     * @param int $objectId The ID of the object to update
     * @param array $updateData Array containing owner and/or organisation data
     *
     * @return void
     * @throws \Exception If the update fails
     */
    private function updateObjectOwnership(int $objectId, array $updateData): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->tableName)
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)));

        foreach ($updateData as $field => $value) {
            $qb->set($field, $qb->createNamedParameter($value));
        }

        // Update the modified timestamp
        $qb->set('modified', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE));

        $qb->executeStatement();
    }//end updateObjectOwnership()
    /**
     * Clear expired objects from the database
     *
     * This method deletes all objects that have expired (i.e., their 'expires' date is earlier than the current date and time)
     * and have the 'expires' column set. This helps maintain database performance by removing old objects that are no longer needed.
     *
     * @return bool True if any objects were deleted, false otherwise
     *
     * @throws \Exception Database operation exceptions
     */
    public function clearObjects(): bool
    {
        try {
            // Get the query builder for database operations
            $qb = $this->db->getQueryBuilder();

            // Build the delete query to remove expired objects that have the 'expires' column set
            $qb->delete($this->getTableName())
               ->where($qb->expr()->isNotNull('expires'))
               ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));

            // Execute the query and get the number of affected rows
            $result = $qb->executeStatement();

            // Return true if any rows were affected (i.e., any objects were deleted)
            return $result > 0;
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \OC::$server->getLogger()->error('Failed to clear expired objects: ' . $e->getMessage(), [
                'app' => 'openregister',
                'exception' => $e
            ]);
            
            // Re-throw the exception so the caller knows something went wrong
            throw $e;
        }

    }//end clearObjects()


    /**
     * Set expiry dates for objects based on retention period in milliseconds
     *
     * Updates the expires column for objects based on their deleted date plus the retention period.
     * Only affects objects that have been soft-deleted and don't already have an expiry date set.
     * Objects without a deleted date will not get an expiry date.
     *
     * @param int $retentionMs Retention period in milliseconds
     *
     * @return int Number of objects updated
     *
     * @throws \Exception Database operation exceptions
     */
    public function setExpiryDate(int $retentionMs): int
    {
        try {
            // Convert milliseconds to seconds for DateTime calculation
            $retentionSeconds = intval($retentionMs / 1000);
            
            // Get the query builder
            $qb = $this->db->getQueryBuilder();
            
            // Update objects that have been deleted but don't have an expiry date set
            // We need to extract the timestamp from the JSON deleted field
            $qb->update($this->getTableName())
               ->set('expires', $qb->createFunction(
                   sprintf('DATE_ADD(JSON_UNQUOTE(JSON_EXTRACT(deleted, "$.deletedAt")), INTERVAL %d SECOND)', $retentionSeconds)
               ))
               ->where($qb->expr()->isNull('expires'))
               ->andWhere($qb->expr()->isNotNull('deleted'))
               ->andWhere($qb->expr()->neq('deleted', $qb->createNamedParameter('null')));
            
            // Execute the update and return number of affected rows
            return $qb->executeStatement();
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \OC::$server->getLogger()->error('Failed to set expiry dates for objects: ' . $e->getMessage(), [
                'app' => 'openregister',
                'exception' => $e
            ]);
            
            // Re-throw the exception so the caller knows something went wrong
            throw $e;
        }
    }//end setExpiryDate()

}//end class
