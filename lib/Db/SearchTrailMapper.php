<?php

declare(strict_types=1);

/*
 * OpenRegister SearchTrailMapper
 *
 * This file contains the mapper class for SearchTrail entities,
 * providing database operations and statistical queries for search analytics.
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

use DateTime;
use OCA\OpenRegister\Db\SearchTrail;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IRequest;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * SearchTrailMapper handles database operations for SearchTrail entities
 *
 * Provides comprehensive CRUD operations and statistical query methods
 * for search analytics and optimization.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method SearchTrail insert(Entity $entity)
 * @method SearchTrail update(Entity $entity)
 * @method SearchTrail insertOrUpdate(Entity $entity)
 * @method SearchTrail delete(Entity $entity)
 * @method SearchTrail find(int|string $id)
 * @method SearchTrail findEntity(IQueryBuilder $query)
 * @method SearchTrail[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<SearchTrail> findEntities(IQueryBuilder $query)
 */
class SearchTrailMapper extends QBMapper
{


    /**
     * Constructor for SearchTrailMapper
     *
     * @param IDBConnection $db          Database connection
     * @param IRequest      $request     Request object for getting request data
     * @param IUserSession  $userSession User session for getting user information
     */
    public function __construct(
        IDBConnection $db,
        private readonly IRequest $request,
        private readonly IUserSession $userSession
    ) {
        parent::__construct($db, 'openregister_search_trails', SearchTrail::class);

    }//end __construct()


    /**
     * Find a search trail by ID
     *
     * @param int $id The search trail ID
     *
     * @return SearchTrail The search trail entity
     *
     * @throws DoesNotExistException         If the search trail is not found
     * @throws MultipleObjectsReturnedException If multiple search trails are found
     */
    public function find(int $id): SearchTrail
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);

    }//end find()


    /**
     * Find all search trails with optional filters
     *
     * @param int|null      $limit   Maximum number of results to return
     * @param int|null      $offset  Number of results to skip
     * @param array         $filters Filter criteria
     * @param array         $sort    Sort criteria
     * @param string|null   $search  Search term
     * @param DateTime|null $from    Start date filter
     * @param DateTime|null $to      End date filter
     *
     * @return array Array of SearchTrail entities
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        array $filters=[],
        array $sort=[],
        ?string $search=null,
        ?DateTime $from=null,
        ?DateTime $to=null
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        // Apply filters.
        $this->applyFilters($qb, $filters);

        // Apply search term.
        if ($search !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('search_term', $qb->createNamedParameter('%'.$search.'%')),
                    $qb->expr()->like('request_uri', $qb->createNamedParameter('%'.$search.'%')),
                    $qb->expr()->like('user_agent', $qb->createNamedParameter('%'.$search.'%'))
                )
            );
        }

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        // Apply sorting.
        if (empty($sort) === false) {
            foreach ($sort as $field => $direction) {
                $qb->addOrderBy($field, $direction);
            }
        } else {
            $qb->orderBy('created', 'DESC');
        }

        // Apply pagination.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Count search trails with optional filters
     *
     * @param array         $filters Filter criteria
     * @param string|null   $search  Search term
     * @param DateTime|null $from    Start date filter
     * @param DateTime|null $to      End date filter
     *
     * @return int Number of matching search trails
     */
    public function count(
        array $filters=[],
        ?string $search=null,
        ?DateTime $from=null,
        ?DateTime $to=null
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName());

        // Apply filters.
        $this->applyFilters($qb, $filters);

        // Apply search term.
        if ($search !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('search_term', $qb->createNamedParameter('%'.$search.'%')),
                    $qb->expr()->like('request_uri', $qb->createNamedParameter('%'.$search.'%')),
                    $qb->expr()->like('user_agent', $qb->createNamedParameter('%'.$search.'%'))
                )
            );
        }

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end count()


    /**
     * Create a new search trail entry
     *
     * @param array  $searchQuery   The search query parameters
     * @param int    $resultCount   The number of results returned
     * @param int    $totalResults  The total number of matching results
     * @param float  $responseTime  The response time in milliseconds
     * @param string $executionType The execution type ('sync' or 'async')
     *
     * @return SearchTrail The created search trail entity
     */
    public function createSearchTrail(
        array $searchQuery,
        int $resultCount,
        int $totalResults,
        float $responseTime,
        string $executionType='sync'
    ): SearchTrail {
        $searchTrail = new SearchTrail();
        $searchTrail->setUuid(Uuid::v4()->toRfc4122());
        $searchTrail->setCreated(new DateTime());
        $searchTrail->setExecutionType($executionType);
        $searchTrail->setResultCount($resultCount);
        $searchTrail->setTotalResults($totalResults);
        $searchTrail->setResponseTime((int) round($responseTime));

        // Extract and set search parameters.
        $this->extractSearchParameters($searchTrail, $searchQuery);

        // Set request information.
        $this->setRequestInformation($searchTrail);

        // Set user information.
        $this->setUserInformation($searchTrail);

        // Calculate and set the size of the search trail entry, with a minimum default of 14 bytes.
        $serializedSize = strlen(serialize($searchTrail->jsonSerialize()));
        $searchTrail->setSize(max($serializedSize, 14));

        return $this->insert($searchTrail);

    }//end createSearchTrail()


    /**
     * Get search statistics for the given time period
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return array Array of search statistics
     */
    public function getSearchStatistics(?DateTime $from=null, ?DateTime $to=null): array
    {
        $qb = $this->db->getQueryBuilder();

        // Base query for time period.
        $qb->select(
                [
                    $qb->func()->count('*', 'total_searches'),
                    $qb->createFunction('COALESCE(SUM(CASE WHEN total_results IS NOT NULL THEN total_results ELSE 0 END), 0) AS total_results'),
                ]
                )
            ->addSelect($qb->createFunction('AVG(CASE WHEN total_results IS NOT NULL THEN total_results END) AS avg_results_per_search'))
            ->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
            ->addSelect($qb->createFunction('COUNT(CASE WHEN total_results > 0 THEN 1 END) AS non_empty_searches'))
            ->from($this->getTableName());

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $stats  = $result->fetch();
        $result->closeCursor();

        return [
            'total_searches'         => (int) ($stats['total_searches'] ?? 0),
            'total_results'          => (int) ($stats['total_results'] ?? 0),
            'avg_results_per_search' => round((float) ($stats['avg_results_per_search'] ?? 0), 2),
            'avg_response_time'      => round((float) ($stats['avg_response_time'] ?? 0), 2),
            'non_empty_searches'     => (int) ($stats['non_empty_searches'] ?? 0),
        ];

    }//end getSearchStatistics()


    /**
     * Get most popular search terms
     *
     * @param int           $limit Maximum number of terms to return
     * @param DateTime|null $from  Start date filter
     * @param DateTime|null $to    End date filter
     *
     * @return array Array of popular search terms with counts
     */
    public function getPopularSearchTerms(int $limit=10, ?DateTime $from=null, ?DateTime $to=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
                [
                    'search_term',
                    $qb->func()->count('*', 'search_count'),
                ]
                )
            ->addSelect($qb->createFunction('AVG(total_results) AS avg_results'))
            ->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('search_term'))
            ->andWhere($qb->expr()->neq('search_term', $qb->createNamedParameter('')))
            ->groupBy('search_term')
            ->orderBy('search_count', 'DESC')
            ->setMaxResults($limit);

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $terms  = $result->fetchAll();
        $result->closeCursor();

        return array_map(
                function ($term) {
                    return [
                        'term'              => $term['search_term'],
                        'count'             => (int) $term['search_count'],
                        'avg_results'       => round((float) $term['avg_results'], 2),
                        'avg_response_time' => round((float) $term['avg_response_time'], 2),
                    ];
                },
                $terms
                );

    }//end getPopularSearchTerms()


    /**
     * Get search activity by time period
     *
     * @param string        $interval Time interval ('hour', 'day', 'week', 'month')
     * @param DateTime|null $from     Start date filter
     * @param DateTime|null $to       End date filter
     *
     * @return array Array of search activity by time period
     */
    public function getSearchActivityByTime(string $interval='day', ?DateTime $from=null, ?DateTime $to=null): array
    {
        $qb = $this->db->getQueryBuilder();

        // Format date based on interval.
        $dateFormat = match ($interval) {
            'hour' => '%Y-%m-%d %H:00:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $qb->select(
                [
                    $qb->func()->count('*', 'search_count'),
                ]
                )
            ->addSelect($qb->createFunction('AVG(total_results) AS avg_results'))
            ->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
            ->from($this->getTableName())
            ->groupBy('date_period')
            ->orderBy('date_period', 'ASC');

        // Add date formatting based on database type.
        // getDatabasePlatform() returns a platform instance.
        $platform = $this->db->getDatabasePlatform();
        if ($platform->getName() === 'mysql') {
            $qb->addSelect($qb->createFunction("DATE_FORMAT(created, '{$dateFormat}') AS date_period"));
        } else {
            // For SQLite and PostgreSQL - convert MySQL format to SQLite format.
            $sqliteFormat = match ($interval) {
                'hour' => '%Y-%m-%d %H:00:00',
                'day' => '%Y-%m-%d',
                'week' => '%Y-%W',
                'month' => '%Y-%m',
                default => '%Y-%m-%d',
            };

            $qb->addSelect($qb->createFunction("strftime('{$sqliteFormat}', created) AS date_period"));
        }

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result   = $qb->executeQuery();
        $activity = $result->fetchAll();
        $result->closeCursor();

        return array_map(
                function ($period) {
                    return [
                        'period'            => $period['date_period'],
                        'count'             => (int) $period['search_count'],
                        'avg_results'       => round((float) $period['avg_results'], 2),
                        'avg_response_time' => round((float) $period['avg_response_time'], 2),
                    ];
                },
                $activity
                );

    }//end getSearchActivityByTime()


    /**
     * Get search statistics by register and schema
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return array Array of search statistics by register and schema
     */
    public function getSearchStatisticsByRegisterSchema(?DateTime $from=null, ?DateTime $to=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
                [
                    'register',
                    'schema',
                    'register_uuid',
                    'schema_uuid',
                    $qb->func()->count('*', 'search_count'),
                ]
                )
            ->addSelect($qb->createFunction('AVG(total_results) AS avg_results'))
            ->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
            ->from($this->getTableName())
            ->groupBy('register', 'schema', 'register_uuid', 'schema_uuid')
            ->orderBy('search_count', 'DESC');

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $stats  = $result->fetchAll();
        $result->closeCursor();

        return array_map(
                function ($stat) {
                    return [
                        'register'          => $stat['register'],
                        'schema'            => $stat['schema'],
                        'register_uuid'     => $stat['register_uuid'],
                        'schema_uuid'       => $stat['schema_uuid'],
                        'count'             => (int) $stat['search_count'],
                        'avg_results'       => round((float) $stat['avg_results'], 2),
                        'avg_response_time' => round((float) $stat['avg_response_time'], 2),
                    ];
                },
                $stats
                );

    }//end getSearchStatisticsByRegisterSchema()


    /**
     * Get user agent statistics
     *
     * @param int           $limit Maximum number of user agents to return
     * @param DateTime|null $from  Start date filter
     * @param DateTime|null $to    End date filter
     *
     * @return array Array of user agent statistics
     */
    public function getUserAgentStatistics(int $limit=10, ?DateTime $from=null, ?DateTime $to=null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
                [
                    'user_agent',
                    $qb->func()->count('*', 'search_count'),
                ]
                )
            ->addSelect($qb->createFunction('AVG(total_results) AS avg_results'))
            ->addSelect($qb->createFunction('AVG(response_time) AS avg_response_time'))
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('user_agent'))
            ->groupBy('user_agent')
            ->orderBy('search_count', 'DESC')
            ->setMaxResults($limit);

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $stats  = $result->fetchAll();
        $result->closeCursor();

        return array_map(
                function ($stat) {
                    return [
                        'user_agent'        => $stat['user_agent'],
                        'count'             => (int) $stat['search_count'],
                        'avg_results'       => round((float) $stat['avg_results'], 2),
                        'avg_response_time' => round((float) $stat['avg_response_time'], 2),
                    ];
                },
                $stats
                );

    }//end getUserAgentStatistics()


    /**
     * Get count of unique search terms for the given time period
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return int Number of unique search terms
     */
    public function getUniqueSearchTermsCount(?DateTime $from=null, ?DateTime $to=null): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('search_term')
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('search_term'))
            ->andWhere($qb->expr()->neq('search_term', $qb->createNamedParameter('')));

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $terms  = $result->fetchAll();
        $result->closeCursor();

        return count($terms);

    }//end getUniqueSearchTermsCount()


    /**
     * Get count of unique users for the given time period
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return int Number of unique users
     */
    public function getUniqueUsersCount(?DateTime $from=null, ?DateTime $to=null): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('user')
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('user'))
            ->andWhere($qb->expr()->neq('user', $qb->createNamedParameter('')));

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $users  = $result->fetchAll();
        $result->closeCursor();

        return count($users);

    }//end getUniqueUsersCount()


    /**
     * Get average searches per session for the given time period
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return float Average searches per session
     */
    public function getAverageSearchesPerSession(?DateTime $from=null, ?DateTime $to=null): float
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            $qb->func()->count('*', 'total_searches'),
            $qb->createFunction('COUNT(DISTINCT session) as unique_sessions')
        )
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('session'))
            ->andWhere($qb->expr()->neq('session', $qb->createNamedParameter('')));

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $data   = $result->fetch();
        $result->closeCursor();

        $totalSearches  = (int) ($data['total_searches'] ?? 0);
        $uniqueSessions = (int) ($data['unique_sessions'] ?? 0);

        return $uniqueSessions > 0 ? round($totalSearches / $uniqueSessions, 2) : 0.0;

    }//end getAverageSearchesPerSession()


    /**
     * Get average object views per session for the given time period
     *
     * This method queries the audit trail table to count 'read' actions per session
     *
     * @param DateTime|null $from Start date filter
     * @param DateTime|null $to   End date filter
     *
     * @return float Average object views per session
     */
    public function getAverageObjectViewsPerSession(?DateTime $from=null, ?DateTime $to=null): float
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
            $qb->func()->count('*', 'total_views'),
            $qb->createFunction('COUNT(DISTINCT session) as unique_sessions')
        )
            ->from('openregister_audit_trails')
            ->where($qb->expr()->eq('action', $qb->createNamedParameter('read')))
            ->andWhere($qb->expr()->isNotNull('session'))
            ->andWhere($qb->expr()->neq('session', $qb->createNamedParameter('')));

        // Apply date filters.
        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))));
        }

        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))));
        }

        $result = $qb->executeQuery();
        $data   = $result->fetch();
        $result->closeCursor();

        $totalViews     = (int) ($data['total_views'] ?? 0);
        $uniqueSessions = (int) ($data['unique_sessions'] ?? 0);

        return $uniqueSessions > 0 ? round($totalViews / $uniqueSessions, 2) : 0.0;

    }//end getAverageObjectViewsPerSession()


    /**
     * Clear expired search trail logs from the database
     *
     * This method deletes all search trail logs that have expired (i.e., their 'expires' date is earlier than the current date and time)
     * and have the 'expires' column set. This helps maintain database performance by removing old log entries that are no longer needed.
     *
     * @return bool True if any logs were deleted, false otherwise
     *
     * @throws \Exception Database operation exceptions
     */
    public function clearLogs(): bool
    {
        try {
            // Get the query builder for database operations.
            $qb = $this->db->getQueryBuilder();

            // Build the delete query to remove expired search trail logs that have the 'expires' column set.
            $qb->delete($this->getTableName())
                ->where($qb->expr()->isNotNull('expires'))
                ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));

            // Execute the query and get the number of affected rows.
            $result = $qb->executeStatement();

            // Return true if any rows were affected (i.e., any logs were deleted).
            return $result > 0;
        } catch (\Exception $e) {
            // Log the error for debugging purposes.
            \OC::$server->getLogger()->error(
                    'Failed to clear expired search trail logs: '.$e->getMessage(),
                    [
                        'app'       => 'openregister',
                        'exception' => $e,
                    ]
                    );

            // Re-throw the exception so the caller knows something went wrong.
            throw $e;
        }//end try

    }//end clearLogs()


    /**
     * Apply filters to the query builder
     *
     * @param IQueryBuilder $qb      The query builder
     * @param array         $filters The filters to apply
     *
     * @return void
     */
    private function applyFilters(IQueryBuilder $qb, array $filters): void
    {
        // Valid column names for SearchTrail.
        $validColumns = [
            'id',
            'uuid',
            'created',
            'expires',
            'search_term',
            'page',
            'limit',
            'offset',
            'facets_requested',
            'facetable_requested',
            'register',
            'register_uuid',
            'schema',
            'schema_uuid',
            'sort_parameters',
            'published_only',
            'filters',
            'query_parameters',
            'result_count',
            'total_results',
            'response_time',
            'execution_type',
            'ip_address',
            'user_agent',
            'request_uri',
            'http_method',
            'user',
            'user_name',
            'session',
            'size',
        ];

        foreach ($filters as $field => $value) {
            // Skip system variables and ensure valid column names.
            if (str_starts_with($field, '_') === true || !in_array($field, $validColumns)) {
                continue;
            }

            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($field));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($field));
            } else if (is_array($value) === true) {
                // Handle array values like ['IS NULL', ''].
                $conditions = [];
                foreach ($value as $val) {
                    if ($val === 'IS NULL') {
                        $conditions[] = $qb->expr()->isNull($field);
                    } else if ($val === 'IS NOT NULL') {
                        $conditions[] = $qb->expr()->isNotNull($field);
                    } else {
                        $conditions[] = $qb->expr()->eq($field, $qb->createNamedParameter($val));
                    }
                }

                if (!empty($conditions)) {
                    $qb->andWhere($qb->expr()->orX(...$conditions));
                }
            } else {
                // Handle comma-separated values.
                if (is_string($value) === true && strpos($value, ',') !== false) {
                    $values = array_map('trim', explode(',', $value));
                    $qb->andWhere($qb->expr()->in($field, $qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
                }
            }//end if
        }//end foreach

    }//end applyFilters()


    /**
     * Extract search parameters from the query and set them on the search trail
     *
     * @param SearchTrail $searchTrail The search trail entity
     * @param array       $query       The search query parameters
     *
     * @return void
     */
    private function extractSearchParameters(SearchTrail $searchTrail, array $query): void
    {
        // Extract search term.
        $searchTerm = $query['_search'] ?? null;
        $searchTrail->setSearchTerm($searchTerm);

        // Extract pagination parameters.
        $searchTrail->setPage($query['_page'] ?? null);
        $searchTrail->setLimit($query['_limit'] ?? null);
        $searchTrail->setOffset($query['_offset'] ?? null);

        // Extract facet parameters.
        $searchTrail->setFacetsRequested(isset($query['_facets']));
        $searchTrail->setFacetableRequested(isset($query['_facetable']) && $query['_facetable'] === true);

        // Extract metadata filters.
        $metadataFilters = $query['@self'] ?? [];
        if (($metadataFilters['register'] ?? null) !== null) {
            $searchTrail->setRegister(is_numeric($metadataFilters['register']) === true ? (int) $metadataFilters['register'] : null);
            $searchTrail->setRegisterUuid(is_string($metadataFilters['register']) === true ? $metadataFilters['register'] : null);
        }

        if (($metadataFilters['schema'] ?? null) !== null) {
            $searchTrail->setSchema(is_numeric($metadataFilters['schema']) === true ? (int) $metadataFilters['schema'] : null);
            $searchTrail->setSchemaUuid(is_string($metadataFilters['schema']) === true ? $metadataFilters['schema'] : null);
        }

        // Extract sort parameters.
        $sortParams = [];
        if (($query['_order'] ?? null) !== null) {
            $sortParams = is_array($query['_order']) === true ? $query['_order'] : [$query['_order']];
        }

        $searchTrail->setSortParameters($sortParams);

        // Extract published filter.
        $searchTrail->setPublishedOnly($query['_published'] ?? false);

        // Extract non-system parameters as filters.
        $filters = [];
        foreach ($query as $key => $value) {
            if (strpos($key, '_') !== 0 && $key !== '@self') {
                $filters[$key] = $value;
            }
        }

        $searchTrail->setFilters($filters);

        // Store original query parameters (excluding system parameters).
        $queryParams = [];
        foreach ($query as $key => $value) {
            if (strpos($key, '_') !== 0) {
                $queryParams[$key] = $value;
            }
        }

        $searchTrail->setQueryParameters($queryParams);

    }//end extractSearchParameters()


    /**
     * Set request information on the search trail
     *
     * @param SearchTrail $searchTrail The search trail entity
     *
     * @return void
     */
    private function setRequestInformation(SearchTrail $searchTrail): void
    {
        $searchTrail->setIpAddress($this->request->getRemoteAddress());
        $searchTrail->setUserAgent($this->request->getHeader('User-Agent'));
        $searchTrail->setRequestUri($this->request->getRequestUri());
        $searchTrail->setHttpMethod($this->request->getMethod());

    }//end setRequestInformation()


    /**
     * Set user information on the search trail
     *
     * @param SearchTrail $searchTrail The search trail entity
     *
     * @return void
     */
    private function setUserInformation(SearchTrail $searchTrail): void
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $searchTrail->setUser($user->getUID());
            $searchTrail->setUserName($user->getDisplayName());
        }

        $sessionId = $this->request->getHeader('X-Session-ID');
        if ($sessionId === '') {
            $sessionId = session_id();
        }

        $searchTrail->setSession($sessionId);

    }//end setUserInformation()


    /**
     * Set expiry dates for search trails based on retention period in milliseconds
     *
     * Updates the expires column for search trails based on their creation date plus the retention period.
     * Only affects search trails that don't already have an expiry date set.
     *
     * @param int $retentionMs Retention period in milliseconds
     *
     * @return int Number of search trails updated
     *
     * @throws \Exception Database operation exceptions
     */
    public function setExpiryDate(int $retentionMs): int
    {
        try {
            // Convert milliseconds to seconds for DateTime calculation.
            $retentionSeconds = intval($retentionMs / 1000);

            // Get the query builder.
            $qb = $this->db->getQueryBuilder();

            // Update search trails that don't have an expiry date set.
            $qb->update($this->getTableName())
                ->set(
                       'expires',
                       $qb->createFunction(
                   sprintf('DATE_ADD(created, INTERVAL %d SECOND)', $retentionSeconds)
                )
                       )
                ->where($qb->expr()->isNull('expires'));

            // Execute the update and return number of affected rows.
            return $qb->executeStatement();
        } catch (\Exception $e) {
            // Log the error for debugging purposes.
            \OC::$server->getLogger()->error(
                    'Failed to set expiry dates for search trails: '.$e->getMessage(),
                    [
                        'app'       => 'openregister',
                        'exception' => $e,
                    ]
                    );

            // Re-throw the exception so the caller knows something went wrong.
            throw $e;
        }//end try

    }//end setExpiryDate()


}//end class
