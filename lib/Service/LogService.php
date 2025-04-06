<?php
/**
 * @file LogService.php
 * @description Service for handling audit trail logs in the OpenRegister app
 * @package OCA\OpenRegister\Service
 * @author Ruben Linde <ruben@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version 1.0.0
 * @link https://github.com/OpenCatalogi/OpenRegister
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Class LogService
 * Service for handling audit trail logs
 */
class LogService {
    /**
     * Constructor for LogService
     *
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper      $schemaMapper       The schema mapper
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {}

    /**
     * Get logs for an object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     * @param array  $config   Configuration array containing:
     *                         - limit: (int) Maximum number of items per page
     *                         - offset: (int|null) Number of items to skip
     *                         - page: (int|null) Current page number
     *                         - filters: (array) Filter parameters
     *                         - sort: (array) Sort parameters ['field' => 'ASC|DESC']
     *                         - search: (string|null) Search term
     *
     * @return array Array of log entries
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     */
    public function getLogs(string $register, string $schema, string $id, array $config = []): array {
        // Get the object to ensure it exists and belongs to the correct register/schema
        $object = $this->objectEntityMapper->find($id);
        
        if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
            throw new \InvalidArgumentException('Object does not belong to specified register/schema');
        }

        // Add object ID to filters
        $filters = $config['filters'] ?? [];
        $filters['object'] = $object->getId();

        // Get logs from audit trail mapper
        return $this->auditTrailMapper->findAll(
            limit: $config['limit'] ?? 20,
            offset: $config['offset'] ?? 0,
            filters: $filters,
            sort: $config['sort'] ?? ['created' => 'DESC'],
            search: $config['search'] ?? null
        );
    }

    /**
     * Count logs for an object
     *
     * @param string $register The register identifier
     * @param string $schema   The schema identifier
     * @param string $id       The object ID
     *
     * @return int Number of logs
     * @throws \InvalidArgumentException If object does not belong to specified register/schema
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     */
    public function count(string $register, string $schema, string $id): int {
        // Get the object to ensure it exists and belongs to the correct register/schema
        $object = $this->objectEntityMapper->find($id);
        
        if ($object->getRegister() !== $register || $object->getSchema() !== $schema) {
            throw new \InvalidArgumentException('Object does not belong to specified register/schema');
        }

        // Get logs using findAll with a filter for the object
        $logs = $this->auditTrailMapper->findAll(
            filters: ['object' => $object->getId()]
        );

        return count($logs);
    }
} 