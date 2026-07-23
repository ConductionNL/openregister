<?php

/**
 * Audit Handler
 *
 * Handles audit trail and logging operations for objects.
 * Tracks all changes and access to objects for compliance and debugging.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
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
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper
     * @param LoggerInterface  $logger           PSR-3 logger
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
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
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md
     * @spec openspec/specs/audit-trail-immutable/spec.md
     */
    public function getLogs(string $uuid, array $filters=[]): array
    {
        $this->logger->debug(
            message: '[AuditHandler] Getting logs for object',
            context: [
                'file'    => __FILE__,
                'line'    => __LINE__,
                'uuid'    => $uuid,
                'filters' => $filters,
            ]
        );

        try {
            // Prepare filters for audit trail mapper.
            $auditFilters = $this->prepareFilters(uuid: $uuid, filters: $filters);

            // Fetch logs from mapper.
            $logs = $this->auditTrailMapper->findAll(filters: $auditFilters);

            $this->logger->info(
                message: '[AuditHandler] Logs retrieved successfully',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'uuid'      => $uuid,
                    'log_count' => count($logs),
                ]
            );

            return $logs;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[AuditHandler] Failed to get logs',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'uuid'  => $uuid,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try
    }//end getLogs()

    /**
     * Prepare filters for audit trail query
     *
     * @param string $uuid    Object UUID
     * @param array  $filters Raw filters
     *
     * @return array Prepared filters for audit trail query.
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md
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
     *
     * @spec openspec/specs/audit-trail-immutable/spec.md
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
