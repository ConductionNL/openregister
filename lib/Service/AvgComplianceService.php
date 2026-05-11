<?php

/**
 * AVG / GDPR compliance auditor.
 *
 * Surfaces "compliance smells" the operator should know about but
 * which don't directly break a write path. Phase 1 ships a single
 * check: "schemas where PII has been detected but the schema lacks a
 * processing-activity annotation". More checks can land here as new
 * compliance scenarios surface (DPIA-required-but-missing,
 * bewaartermijn-required-but-missing, etc.).
 *
 * Each check is exposed as its own method so the controller / UI can
 * call them individually OR via the aggregate `runAllChecks()` for a
 * compliance dashboard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * AVG compliance checks. Phase 1: unregistered-PII detection.
 */
class AvgComplianceService
{

    /**
     * Annotation key that satisfies the "processing-activity attached"
     * check. Schemas (or registers) carrying this key in their
     * configuration column are considered annotated.
     *
     * @var string
     */
    public const ANNOTATION_KEY = 'x-openregister-processing-activity';

    /**
     * Constructor.
     *
     * @param IDBConnection   $db             DB for the GdprEntity join.
     * @param SchemaMapper    $schemaMapper   Schema lookup.
     * @param RegisterMapper  $registerMapper Register lookup (annotation
     *                                        fallback).
     * @param LoggerInterface $logger         Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Find schemas where PII has been detected but no
     * `x-openregister-processing-activity` annotation exists on the
     * schema OR on its enclosing register.
     *
     * Returns a list of envelopes:
     *
     *   [
     *     {
     *       "registerId":   "<register slug or uuid stored on relation>",
     *       "schemaId":     "<schema slug or uuid>",
     *       "schemaTitle":  "<resolved title or ''>",
     *       "piiCount":     <distinct PII rows attributed to this pair>,
     *       "registerHasAnnotation": bool,
     *       "schemaHasAnnotation":   bool,
     *     },
     *     ...
     *   ]
     *
     * Each row is "actionable": the operator either annotates the
     * schema with a processing-activity reference, or removes the
     * personal data, or accepts the gap with a documented rationale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUnannotatedSchemasWithPii(): array
    {
        try {
            $rows = $this->aggregatePiiBySchema();
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AVG compliance] Failed to aggregate PII by schema',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }

        if ($rows === []) {
            return [];
        }

        $issues = [];
        foreach ($rows as $row) {
            $registerId = (string) ($row['register_id'] ?? '');
            $schemaId   = (string) ($row['schema_id'] ?? '');
            if ($registerId === '' || $schemaId === '') {
                // Legacy entity_relations row predating the
                // disambiguation migration — can't be definitively
                // attributed to a (register, schema) pair so we skip.
                continue;
            }

            $registerHas = $this->registerHasAnnotation(registerId: $registerId);
            $schemaHas   = $this->schemaHasAnnotation(schemaId: $schemaId);

            if ($schemaHas === true || $registerHas === true) {
                continue;
            }

            $issues[] = [
                'registerId'            => $registerId,
                'schemaId'              => $schemaId,
                'schemaTitle'           => $this->resolveSchemaTitle(schemaId: $schemaId),
                'piiCount'              => (int) ($row['pii_count'] ?? 0),
                'registerHasAnnotation' => $registerHas,
                'schemaHasAnnotation'   => $schemaHas,
            ];
        }//end foreach

        return $issues;

    }//end findUnannotatedSchemasWithPii()

    /**
     * Run every check in sequence and return the aggregate envelope.
     *
     * @return array<string, mixed>
     */
    public function runAllChecks(): array
    {
        $unannotated = $this->findUnannotatedSchemasWithPii();
        return [
            'generated' => date('c'),
            'issues'    => [
                'unannotatedSchemasWithPii' => $unannotated,
            ],
            'totals'    => [
                'unannotatedSchemasWithPii' => count($unannotated),
            ],
        ];

    }//end runAllChecks()

    /**
     * Aggregate `oc_openregister_entities` × `oc_openregister_entity_relations`
     * by (register_id, schema_id), returning the count of distinct PII
     * entities per pair.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aggregatePiiBySchema(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['r.register_id', 'r.schema_id'])
            ->selectAlias($qb->func()->count('e.id'), 'pii_count')
            ->from('openregister_entity_relations', 'r')
            ->innerJoin(
                'r',
                'openregister_entities',
                'e',
                $qb->expr()->eq('r.entity_id', 'e.id')
            )
            ->where($qb->expr()->isNotNull('r.object_id'))
            ->groupBy('r.register_id', 'r.schema_id');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();
        return $rows;

    }//end aggregatePiiBySchema()

    /**
     * Whether the register's configuration contains the annotation key.
     *
     * @param string $registerId Register identifier (slug or uuid).
     *
     * @return bool
     */
    private function registerHasAnnotation(string $registerId): bool
    {
        try {
            $register = $this->registerMapper->find(
                $registerId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }

        $config = $register->getConfiguration();
        if (is_array($config) === false) {
            return false;
        }

        $value = $config[self::ANNOTATION_KEY] ?? null;
        return is_string($value) === true && $value !== '';

    }//end registerHasAnnotation()

    /**
     * Whether the schema's configuration contains the annotation key.
     *
     * @param string $schemaId Schema identifier (slug or uuid).
     *
     * @return bool
     */
    private function schemaHasAnnotation(string $schemaId): bool
    {
        try {
            $schema = $this->schemaMapper->find(
                $schemaId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }

        $config = $schema->getConfiguration();
        if (is_array($config) === false) {
            return false;
        }

        $value = $config[self::ANNOTATION_KEY] ?? null;
        return is_string($value) === true && $value !== '';

    }//end schemaHasAnnotation()

    /**
     * Best-effort title lookup for an issue row. Returns empty string on miss.
     *
     * @param string $schemaId Schema identifier.
     *
     * @return string
     */
    private function resolveSchemaTitle(string $schemaId): string
    {
        try {
            $schema = $this->schemaMapper->find(
                $schemaId,
                _rbac: false,
                _multitenancy: false
            );
            return (string) ($schema->getTitle() ?? '');
        } catch (\Throwable $e) {
            return '';
        }

    }//end resolveSchemaTitle()
}//end class
