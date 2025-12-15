<?php
/**
 * OpenRegister ObjectEntity Query Builder Handler
 *
 * Handles query builder utilities and database configuration helpers.
 * Extracted from ObjectEntityMapper as part of SOLID refactoring.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use Exception;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * QueryBuilderHandler
 *
 * Provides query builder access and database configuration utilities.
 * Handles MySQL packet size queries and query builder instantiation.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
 */
class QueryBuilderHandler
{

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger Logger.
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->db     = $db;
        $this->logger = $logger;
    }//end __construct()


    /**
     * Get query builder instance
     *
     * Returns a fresh query builder for constructing database queries.
     *
     * @return IQueryBuilder Query builder instance
     */
    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->db->getQueryBuilder();
    }//end getQueryBuilder()


    /**
     * Get the max_allowed_packet value from database
     *
     * Queries MySQL/MariaDB for the max_allowed_packet configuration value.
     * This determines the maximum size of a single SQL packet/query.
     * Falls back to 16MB default if query fails.
     *
     * @return int The max_allowed_packet value in bytes
     */
    public function getMaxAllowedPacketSize(): int
    {
        try {
            $stmt   = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
            $result = $stmt->fetch();

            if (($result !== null) === true && ($result['Value'] ?? null) !== null) {
                $packetSize = (int) $result['Value'];
                
                $this->logger->debug(
                    message: '[QueryBuilderHandler] Retrieved max_allowed_packet',
                    context: [
                            'size'       => $packetSize,
                            'sizeMB'     => round($packetSize / 1048576, 2),
                        ]
                );

                return $packetSize;
            }
        } catch (Exception $e) {
            $this->logger->debug(
                message: '[QueryBuilderHandler] Failed to get max_allowed_packet, using fallback',
                context: [
                        'exception' => $e->getMessage(),
                    ]
            );
        }//end try

        // Default fallback value (16MB).
        return 16777216;
    }//end getMaxAllowedPacketSize()
}//end class
