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
     * Constructor for the ObjectEntityMapper
     *
     * @param IDBConnection    $db               The database connection
     * @param MySQLJsonService $mySQLJsonService The MySQL JSON service
     * @param IEventDispatcher $eventDispatcher  The event dispatcher
     * @param IUserSession     $userSession      The user session
     * @param SchemaMapper     $schemaMapper     The schema mapper
     * @param IGroupManager    $groupManager     The group manager
     * @param IUserManager     $userManager      The user manager
     */
    public function __construct(
        IDBConnection $db,
        MySQLJsonService $mySQLJsonService,
        IEventDispatcher $eventDispatcher,
        IUserSession $userSession,
        SchemaMapper $schemaMapper,
        IGroupManager $groupManager,
        IUserManager $userManager
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

    }//end __construct()


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
     *
     * @return void
     */
    private function applyRbacFilters(IQueryBuilder $qb, string $objectTableAlias = 'o', string $schemaTableAlias = 's', ?string $userId = null): void
    {
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
     *
     * @return void
     */
    private function applyOrganizationFilters(IQueryBuilder $qb, string $objectTableAlias = 'o', ?string $activeOrganisationUuid = null): void
    {
        // Get current user to check if they're admin
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
            
            // Admin users see all objects by default, but should still respect organization filtering
            // when an active organization is explicitly set (i.e., when they switch organizations)
            if (in_array('admin', $userGroups)) {
                // If no active organization is set, admin users see everything (no filtering)
                if ($activeOrganisationUuid === null) {
                    return;
                }
                // If an active organization IS set, admin users should see only that organization's objects
                // This allows admins to "switch context" to work within a specific organization
                // Continue with organization filtering logic below
            }
        }

        // Get user's organizations directly from database
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

        $organizationColumn = $objectTableAlias ? $objectTableAlias . '.organisation' : 'organisation';

        // Check if this is the system-wide default organization (not user's oldest organization)
        $defaultOrgQb = $this->db->getQueryBuilder();
        $defaultOrgQb->select('uuid')
                     ->from('openregister_organisations')
                     ->where($defaultOrgQb->expr()->eq('is_default', $defaultOrgQb->createNamedParameter(1)))
                     ->setMaxResults(1);
        
        $defaultResult = $defaultOrgQb->executeQuery();
        $systemDefaultOrgUuid = $defaultResult->fetchColumn();
        $defaultResult->closeCursor();
        
        $isSystemDefaultOrg = ($activeOrganisationUuid === $systemDefaultOrgUuid);

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
    public function find(string | int $identifier, ?Register $register=null, ?Schema $schema=null, bool $includeDeleted=false): ObjectEntity
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
        ?bool $published = false
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
        $this->applyRbacFilters($qb, 'o', 's');

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
    public function searchObjects(array $query = [], ?string $activeOrganisationUuid = null): array|int {
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
        $this->applyRbacFilters($queryBuilder, 'o', 's');

        // Apply organization filtering for multi-tenancy
        $this->applyOrganizationFilters($queryBuilder, 'o', $activeOrganisationUuid);

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
    public function countSearchObjects(array $query = [], ?string $activeOrganisationUuid = null): int
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
        $this->applyOrganizationFilters($queryBuilder, 'o', $activeOrganisationUuid);

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
        ?bool $published=false
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
        $this->applyRbacFilters($qb, 'o', 's');

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
        // error_log("ObjectEntityMapper: Dispatching ObjectCreatedEvent for object ID: " . ($entity->getId() ?? 'NULL') . ", UUID: " . ($entity->getUuid() ?? 'NULL'));
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
        error_log("ObjectEntityMapper->update() called with entity ID: " . ($entity->getId() ?? 'NULL'));
        error_log("ObjectEntityMapper->update() entity type: " . get_class($entity));

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId())));

        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        error_log("ObjectEntityMapper->update() about to execute findEntity with internal ID");
        $oldObject = $this->findEntity($qb);
        error_log("ObjectEntityMapper->update() successfully found old object for update");

        // Lets make sure that @self and id never enter the database.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

        $entity = parent::update($entity);

        // Dispatch update event.
        // error_log("ObjectEntityMapper: Dispatching ObjectUpdatedEvent for object ID: " . ($entity->getId() ?? 'NULL') . ", UUID: " . ($entity->getUuid() ?? 'NULL'));
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
        // error_log("ObjectEntityMapper: Dispatching ObjectDeletedEvent for object ID: " . ($object->getId() ?? 'NULL') . ", UUID: " . ($object->getUuid() ?? 'NULL'));
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
        // error_log("ObjectEntityMapper: Dispatching ObjectLockedEvent for object ID: " . ($object->getId() ?? 'NULL') . ", UUID: " . ($object->getUuid() ?? 'NULL') . ", Process: " . ($process ?? 'NULL'));
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
        // error_log("ObjectEntityMapper: Dispatching ObjectUnlockedEvent for object ID: " . ($object->getId() ?? 'NULL') . ", UUID: " . ($object->getUuid() ?? 'NULL'));
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

}//end class
