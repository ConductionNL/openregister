<?php

/**
 * Audit Handler
 *
 * Handles audit trail and logging operations for objects.
 * Tracks all changes and access to objects for compliance and debugging.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use Psr\Log\LoggerInterface;

/**
 * AuditHandler
 *
 * Responsible for managing audit trails and logs for objects.
 *
 * RESPONSIBILITIES:
 * - Retrieve audit logs for objects
 * - Filter logs by various criteria
 * - Validate object ownership before showing logs
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class AuditHandler
{
    /**
     * Constructor
     *
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper
     * @param LoggerInterface    $logger             PSR-3 logger
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Get audit logs for an object
     *
     * Retrieves all audit trail entries for a specific object with optional filters.
     *
     * @param string $uuid    Object UUID
     * @param array  $filters Optional filters for logs
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of audit log entries
     *
     * @throws \Exception If retrieval fails
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     */
    public function getLogs(string $uuid, array $filters=[]): array
    {
        $this->logger->debug(
            message: '[AuditHandler] Getting logs for object',
            context: [
                'uuid'    => $uuid,
                'filters' => $filters,
            ]
        );

        try {
            // Prepare filters for audit trail mapper.
            $auditFilters = $this->prepareFilters($uuid, $filters);

            // Fetch logs from mapper.
            $logs = $this->auditTrailMapper->findAll(filters: $auditFilters);

            $this->logger->info(
                message: '[AuditHandler] Logs retrieved successfully',
                context: [
                    'uuid'      => $uuid,
                    'log_count' => count($logs),
                ]
            );

            return $logs;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[AuditHandler] Failed to get logs',
                context: [
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

    }//end getLogs()

    /**
     * Validate object ownership
     *
     * Checks if object belongs to specified register and schema.
     *
     * @param object|array $object            Object to validate
     * @param string       $requestedRegister Requested register ID or slug
     * @param string       $requestedSchema   Requested schema ID or slug
     *
     * @return bool True if object belongs to register/schema
     */
    public function validateObjectOwnership(object|array $object, string $requestedRegister, string $requestedSchema): bool
    {
        try {
            // Get object's register and schema.
            if (is_array($object) === true) {
                $objectRegister = $object['register'] ?? null;
                $objectSchema   = $object['schema'] ?? null;
            } else {
                $objectRegister = $object->getRegister();
                $objectSchema   = $object->getSchema();
            }

            // Normalize and compare register.
            $objectRegisterNorm    = strtolower((string) $objectRegister);
            $requestedRegisterNorm = strtolower($requestedRegister);
            $registerMatch         = ($objectRegisterNorm === $requestedRegisterNorm);

            // Normalize schema (handle array/object/string).
            $objectSchemaId   = $this->extractSchemaId($objectSchema);
            $objectSchemaSlug = $this->extractSchemaSlug($objectSchema);

            $requestedSchemaNorm  = strtolower($requestedSchema);
            $objectSchemaIdNorm   = strtolower((string) $objectSchemaId);
            $objectSchemaSlugNorm = $objectSchemaSlug !== null ? strtolower($objectSchemaSlug) : null;

            // Check schema match (by ID or slug).
            $schemaMatch = (
                $requestedSchemaNorm === $objectSchemaIdNorm ||
                ($objectSchemaSlugNorm && $requestedSchemaNorm === $objectSchemaSlugNorm)
            );

            return $registerMatch && $schemaMatch;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[AuditHandler] Failed to validate object ownership',
                context: ['error' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end validateObjectOwnership()

    /**
     * Prepare filters for audit trail query
     *
     * @param string $uuid    Object UUID
     * @param array  $filters Raw filters
     *
     * @return (mixed|string)[] Prepared filters
     *
     * @psalm-return array{object_uuid: string, action?: mixed, user?: mixed, date_from?: mixed, date_to?: mixed, order_by: 'created_at'|mixed, order: 'DESC'|mixed}
     */
    private function prepareFilters(string $uuid, array $filters): array
    {
        // Start with object UUID filter.
        $auditFilters = ['object_uuid' => $uuid];

        // Add additional filters if provided.
        if (empty($filters['action']) === false) {
            $auditFilters['action'] = $filters['action'];
        }

        if (empty($filters['user']) === false) {
            $auditFilters['user'] = $filters['user'];
        }

        if (empty($filters['date_from']) === false) {
            $auditFilters['date_from'] = $filters['date_from'];
        }

        if (empty($filters['date_to']) === false) {
            $auditFilters['date_to'] = $filters['date_to'];
        }

        // Add ordering.
        $auditFilters['order_by'] = $filters['order_by'] ?? 'created_at';
        $auditFilters['order']    = $filters['order'] ?? 'DESC';

        return $auditFilters;

    }//end prepareFilters()

    /**
     * Extract schema ID from schema data
     *
     * @param mixed $schema Schema data (array, object, or string)
     *
     * @return string Schema ID
     */
    private function extractSchemaId(mixed $schema): string
    {
        if (is_array($schema) === true && isset($schema['id']) === true) {
            return (string) $schema['id'];
        }

        if (is_object($schema) === true && isset($schema->id) === true) {
            return (string) $schema->id;
        }

        return (string) $schema;

    }//end extractSchemaId()

    /**
     * Extract schema slug from schema data
     *
     * @param mixed $schema Schema data (array, object, or string)
     *
     * @return null|string Schema slug
     */
    private function extractSchemaSlug(mixed $schema): string|null
    {
        if (is_array($schema) === true && isset($schema['slug']) === true) {
            return strtolower($schema['slug']);
        }

        if (is_object($schema) === true && isset($schema->slug) === true) {
            return strtolower($schema->slug);
        }

        return null;

    }//end extractSchemaSlug()
}//end class
