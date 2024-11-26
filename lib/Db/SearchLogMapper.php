<?php

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use DateTime;
use Symfony\Component\Uid\Uuid;

/**
 * Class SearchLogMapper
 * 
 * Handles database operations for search logs
 * 
 * @package OCA\OpenRegister\Db
 */
class SearchLogMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_search_logs');
    }

    /**
     * Get search term statistics for a date range
     *
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @param int|null $limit Maximum number of terms to return
     * @return array Array of terms and their usage counts
     */
    public function getTermStats(DateTime $from, DateTime $to, ?int $limit = 10): array
    {
        $qb = $this->db->getQueryBuilder();
        
        // We need to unnest the terms array and count occurrences
        $qb->select('t.term', $qb->createFunction('COUNT(*) as count'))
           ->from('openregister_search_logs', 'l')
           ->join('l', 'json_table', 't', 
                  'json_array_elements_text(l.terms::json) as term')
           ->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
           ->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
           ->groupBy('t.term')
           ->orderBy('count', 'DESC')
           ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $stats = $result->fetchAll();
        $result->closeCursor();

        return $stats;
    }

    /**
     * Get daily search statistics
     *
     * @param DateTime $from Start date
     * @param DateTime $to End date
     * @return array Daily search counts and average result counts
     */
    public function getDailyStats(DateTime $from, DateTime $to): array
    {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select(
                $qb->createFunction('DATE(created) as date'),
                $qb->createFunction('COUNT(*) as search_count'),
                $qb->createFunction('AVG(result_count) as avg_results')
            )
           ->from('openregister_search_logs')
           ->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
           ->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
           ->groupBy('date')
           ->orderBy('date', 'ASC');

        $result = $qb->executeQuery();
        $stats = $result->fetchAll();
        $result->closeCursor();

        return $stats;
    }

    /**
     * Create a new search log entry
     *
     * @param int $schema Schema ID
     * @param int $register Register ID
     * @param array $filters Applied filters
     * @param string|null $search Search term
     * @param int $resultCount Number of results found
     * @return SearchLog The created search log entry
     */
    public function createSearchLog(
        int $schema,
        int $register,
        array $filters,
        ?string $search,
        int $resultCount
    ): SearchLog {
        // Create new search log entry
        $searchLog = new SearchLog();
        $searchLog->setUuid(Uuid::v4());
        $searchLog->setSchema($schema);
        $searchLog->setRegister($register);
        $searchLog->setFilters($filters);
        
        // Extract search terms
        $terms = [];
        if ($search) {
            $terms = array_filter(
                array_map('trim', 
                explode(' ', $search)
            ));
        }
        $searchLog->setTerms($terms);
        
        $searchLog->setResultCount($resultCount);
        
        // Get current user
        $user = \OC::$server->getUserSession()->getUser();
        $searchLog->setUser($user->getUID());
        $searchLog->setUserName($user->getDisplayName());
        
        // Set request info
        $searchLog->setSession(session_id());
        $searchLog->setRequest(\OC::$server->getRequest()->getId());
        $searchLog->setIpAddress(\OC::$server->getRequest()->getRemoteAddress());
        $searchLog->setCreated(new \DateTime());

        // Save and return the search log
        return $this->insert($searchLog);
    }
} 