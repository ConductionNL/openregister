<?php

/**
 * Integration tests for `AvgComplianceService` — the compliance
 * auditor surfacing schemas where PII has been detected but no
 * processing-activity annotation exists.
 *
 * Locks in:
 *   1. A schema with `x-openregister-processing-activity` annotation
 *      and PII does NOT appear as an issue.
 *   2. A schema with PII but no annotation DOES appear as an issue
 *      (the load-bearing case).
 *   3. Register-level annotation satisfies the check (schema can
 *      inherit).
 *   4. `runAllChecks()` returns the aggregate envelope shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AvgComplianceService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class AvgComplianceServiceIntegrationTest extends TestCase
{

    private AvgComplianceService $service;

    private IDBConnection $db;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    /**
     * @var array<int, Schema>
     */
    private array $insertedSchemas = [];

    /**
     * @var array<int, Register>
     */
    private array $insertedRegisters = [];

    /**
     * @var array<int, int>
     */
    private array $insertedGdprIds = [];

    /**
     * @var array<int, int>
     */
    private array $insertedRelationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service        = \OC::$server->get(AvgComplianceService::class);
        $this->db             = \OC::$server->get(IDBConnection::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);

    }//end setUp()

    protected function tearDown(): void
    {
        foreach ($this->insertedRelationIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entity_relations')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedGdprIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entities')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedSchemas as $schema) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where(
                        $qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
                    );
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedRegisters as $register) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where(
                        $qb->expr()->eq('id', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
                    );
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testAnnotatedSchemaWithPiiIsNotFlagged(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(annotation: 'phpunit-good-activity');
        $this->insertPii(registerId: (string) $register->getId(), schemaId: (string) $schema->getId());

        $issues = $this->service->findUnannotatedSchemasWithPii();

        $matched = $this->findIssue(issues: $issues, schemaId: (string) $schema->getId());
        $this->assertNull(
            $matched,
            'annotated schema with PII MUST NOT appear in compliance issues'
        );

    }//end testAnnotatedSchemaWithPiiIsNotFlagged()

    public function testUnannotatedSchemaWithPiiIsFlagged(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(annotation: null);
        $this->insertPii(registerId: (string) $register->getId(), schemaId: (string) $schema->getId());

        $issues  = $this->service->findUnannotatedSchemasWithPii();
        $matched = $this->findIssue(issues: $issues, schemaId: (string) $schema->getId());

        $this->assertNotNull($matched, 'unannotated schema with PII MUST appear in compliance issues');
        $this->assertGreaterThanOrEqual(1, $matched['piiCount']);
        $this->assertSame(false, $matched['schemaHasAnnotation']);
        $this->assertSame(false, $matched['registerHasAnnotation']);

    }//end testUnannotatedSchemaWithPiiIsFlagged()

    public function testRegisterLevelAnnotationSatisfiesCheck(): void
    {
        // Register has the annotation, schema doesn't — schema should
        // NOT appear as an issue (it inherits from register).
        $register = $this->makeRegister(annotation: 'phpunit-register-activity');
        $schema   = $this->makeSchema(annotation: null);
        $this->insertPii(registerId: (string) $register->getId(), schemaId: (string) $schema->getId());

        $issues  = $this->service->findUnannotatedSchemasWithPii();
        $matched = $this->findIssue(issues: $issues, schemaId: (string) $schema->getId());

        $this->assertNull(
            $matched,
            'register-level annotation MUST satisfy the check for its enclosed schemas'
        );

    }//end testRegisterLevelAnnotationSatisfiesCheck()

    public function testRunAllChecksReturnsAggregateEnvelope(): void
    {
        $envelope = $this->service->runAllChecks();
        $this->assertArrayHasKey('generated', $envelope);
        $this->assertArrayHasKey('issues', $envelope);
        $this->assertArrayHasKey('totals', $envelope);
        $this->assertArrayHasKey('unannotatedSchemasWithPii', $envelope['issues']);
        $this->assertArrayHasKey('unannotatedSchemasWithPii', $envelope['totals']);
        $this->assertSame(
            count($envelope['issues']['unannotatedSchemasWithPii']),
            $envelope['totals']['unannotatedSchemasWithPii'],
            'totals MUST match the issues array length'
        );

    }//end testRunAllChecksReturnsAggregateEnvelope()

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    private function findIssue(array $issues, string $schemaId): ?array
    {
        foreach ($issues as $issue) {
            if (($issue['schemaId'] ?? '') === $schemaId) {
                return $issue;
            }
        }

        return null;

    }//end findIssue()

    private function makeRegister(?string $annotation=null): Register
    {
        $register = new Register();
        $register->setTitle('phpunit-comp-'.uniqid());
        $register->setSlug('phpunit-comp-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);

        if ($annotation !== null) {
            $config                                                          = $register->getConfiguration();
            $config[AvgComplianceService::ANNOTATION_KEY] = $annotation;
            $register->setConfiguration($config);
        }

        $persisted                 = $this->registerMapper->insert($register);
        $this->insertedRegisters[] = $persisted;
        return $persisted;

    }//end makeRegister()

    private function makeSchema(?string $annotation): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-comp-schema-'.uniqid());
        $schema->setSlug('phpunit-comp-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);

        if ($annotation !== null) {
            $config = $schema->getConfiguration() ?? [];
            $config[AvgComplianceService::ANNOTATION_KEY] = $annotation;
            $schema->setConfiguration($config);
        }

        $persisted               = $this->schemaMapper->insert($schema);
        $this->insertedSchemas[] = $persisted;
        return $persisted;

    }//end makeSchema()

    private function insertPii(string $registerId, string $schemaId): void
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        // GdprEntity row.
        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_entities')
            ->values(
                [
                    'uuid'        => $qb->createNamedParameter('phpunit-pii-'.Uuid::v4()->toRfc4122()),
                    'type'        => $qb->createNamedParameter('email'),
                    'value'       => $qb->createNamedParameter('phpunit-pii-'.uniqid().'@example.com'),
                    'category'    => $qb->createNamedParameter('person'),
                    'detected_at' => $qb->createNamedParameter($now),
                    'updated_at'  => $qb->createNamedParameter($now),
                ]
            );
        $qb->executeStatement();
        $entityId                  = (int) $this->db->lastInsertId('oc_openregister_entities_id_seq');
        $this->insertedGdprIds[]   = $entityId;

        // entity_relations row pinning the PII to (register, schema).
        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_entity_relations')
            ->values(
                [
                    'entity_id'        => $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
                    'chunk_id'         => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'object_id'        => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'object_uuid'      => $qb->createNamedParameter('phpunit-obj-'.Uuid::v4()->toRfc4122()),
                    'register_id'      => $qb->createNamedParameter($registerId),
                    'schema_id'        => $qb->createNamedParameter($schemaId),
                    'confidence'       => $qb->createNamedParameter('0.99'),
                    'detection_method' => $qb->createNamedParameter('phpunit'),
                    'created_at'       => $qb->createNamedParameter($now),
                ]
            );
        $qb->executeStatement();
        $relationId                    = (int) $this->db->lastInsertId('oc_openregister_entity_relations_id_seq');
        $this->insertedRelationIds[]   = $relationId;

    }//end insertPii()
}//end class
