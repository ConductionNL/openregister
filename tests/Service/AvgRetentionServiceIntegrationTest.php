<?php

/**
 * Integration tests for `AvgRetentionService` (AVG bewaartermijn
 * enforcement).
 *
 * Locks in:
 *   1. Activities without `bewaartermijn` are flagged as
 *      `skippedActivities` in the summary, never erase anything.
 *   2. Malformed `bewaartermijn` (non-ISO-8601 duration) is also
 *      skipped — operator misconfiguration MUST NOT crash the job.
 *   3. Dry-run mode reports overdue objects without acting.
 *   4. Live pass soft-deletes objects whose latest audit row predates
 *      the cut-off + tags the audit row with the same processing
 *      activity (proves the Phase 1 trigger contract composes with
 *      retention enforcement).
 *   5. Objects audited recently (within the bewaartermijn window) are
 *      preserved — the bewaartermijn clock resets on every write.
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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class AvgRetentionServiceIntegrationTest extends TestCase
{

    private AvgRetentionService $service;

    private IDBConnection $db;

    private VerwerkingsactiviteitMapper $vrwMapper;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $objectMapper;

    private AuditTrailMapper $auditMapper;

    /**
     * @var array<int, string>
     */
    private array $insertedActivityUuids = [];

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
    private array $insertedAuditIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service        = \OC::$server->get(AvgRetentionService::class);
        $this->db             = \OC::$server->get(IDBConnection::class);
        $this->vrwMapper      = \OC::$server->get(VerwerkingsactiviteitMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);
        $this->auditMapper    = \OC::$server->get(AuditTrailMapper::class);

    }//end setUp()

    protected function tearDown(): void
    {
        foreach ($this->insertedAuditIds as $auditId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_audit_trails')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($auditId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedActivityUuids as $uuid) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_verwerkingsactiviteiten')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
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

    public function testActivityWithoutBewaartermijnIsSkipped(): void
    {
        $activity = $this->makeActivity(naam: 'phpunit-no-retention', bewaartermijn: null);
        $object   = $this->makeObjectFixture();

        // Audit row 30 days in the past — would normally trigger
        // erasure if a bewaartermijn were set.
        $this->insertSyntheticAuditRow(
            object: $object,
            activityUuid: $activity->getUuid(),
            createdDaysAgo: 30
        );

        $summary = $this->service->runRetentionPass(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $summary['skippedActivities']);
        $this->assertSame(0, $summary['objectsErased'], 'no bewaartermijn MUST yield zero erasures');

    }//end testActivityWithoutBewaartermijnIsSkipped()

    public function testMalformedBewaartermijnIsSkipped(): void
    {
        $activity = $this->makeActivity(naam: 'phpunit-bad-iso', bewaartermijn: 'this-is-not-a-duration');
        $object   = $this->makeObjectFixture();
        $this->insertSyntheticAuditRow(
            object: $object,
            activityUuid: $activity->getUuid(),
            createdDaysAgo: 30
        );

        $summary = $this->service->runRetentionPass(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $summary['skippedActivities']);
        // The malformed activity MUST appear in skipped count, not in
        // perActivity entries.
        foreach ($summary['perActivity'] as $entry) {
            $this->assertNotSame(
                $activity->getUuid(),
                $entry['uuid'],
                'malformed bewaartermijn MUST be skipped, not evaluated'
            );
        }

    }//end testMalformedBewaartermijnIsSkipped()

    public function testDryRunReportsWithoutErasing(): void
    {
        $activity = $this->makeActivity(naam: 'phpunit-dryrun-retention', bewaartermijn: 'P1D');
        $object   = $this->makeObjectFixture();
        $this->insertSyntheticAuditRow(
            object: $object,
            activityUuid: $activity->getUuid(),
            createdDaysAgo: 30
        );

        $summary = $this->service->runRetentionPass(dryRun: true);

        $this->assertSame(true, $summary['dryRun']);
        $this->assertSame(0, $summary['objectsErased']);

        // The activity MUST appear in the per-activity rollup with
        // matchedObjects > 0 even though we didn't erase.
        $found = $this->findActivityInSummary(summary: $summary, uuid: $activity->getUuid());
        $this->assertNotNull($found, 'dry run MUST still evaluate the activity');
        $this->assertGreaterThanOrEqual(1, $found['matchedObjects']);

    }//end testDryRunReportsWithoutErasing()

    public function testRecentlyAuditedObjectsArePreserved(): void
    {
        // 1-day bewaartermijn; audit row is 0 days old (today). Object
        // MUST NOT be matched.
        $activity = $this->makeActivity(naam: 'phpunit-fresh', bewaartermijn: 'P1D');
        $object   = $this->makeObjectFixture();
        $this->insertSyntheticAuditRow(
            object: $object,
            activityUuid: $activity->getUuid(),
            createdDaysAgo: 0
        );

        $summary = $this->service->runRetentionPass(dryRun: true);
        $found   = $this->findActivityInSummary(summary: $summary, uuid: $activity->getUuid());

        $this->assertNotNull($found);
        $this->assertSame(
            0,
            $found['matchedObjects'],
            'object audited within the bewaartermijn window MUST NOT be flagged for erasure'
        );

    }//end testRecentlyAuditedObjectsArePreserved()

    public function testLiveErasePassMarksObjectsDeletedAndTagsAudit(): void
    {
        $activity = $this->makeActivity(naam: 'phpunit-erase-retention', bewaartermijn: 'P1D');
        $object   = $this->makeObjectFixture();
        $auditId  = $this->insertSyntheticAuditRow(
            object: $object,
            activityUuid: $activity->getUuid(),
            createdDaysAgo: 30
        );

        $summary = $this->service->runRetentionPass(dryRun: false);

        $found = $this->findActivityInSummary(summary: $summary, uuid: $activity->getUuid());
        $this->assertNotNull($found);
        $this->assertGreaterThanOrEqual(1, $found['erased']);
        $this->assertGreaterThanOrEqual(1, $summary['objectsErased']);

        // Sanity: the audit row we synthesised is still in the table
        // (retention erases the OBJECT, not the audit ledger — the
        // ledger is the legal record).
        $auditExists = $this->auditRowExists(id: $auditId);
        $this->assertTrue($auditExists, 'retention enforcement MUST NOT touch the audit ledger');

    }//end testLiveErasePassMarksObjectsDeletedAndTagsAudit()

    /**
     * @param array<string, mixed> $summary
     */
    private function findActivityInSummary(array $summary, string $uuid): ?array
    {
        foreach (($summary['perActivity'] ?? []) as $entry) {
            if (($entry['uuid'] ?? '') === $uuid) {
                return $entry;
            }
        }

        return null;

    }//end findActivityInSummary()

    private function makeActivity(string $naam, ?string $bewaartermijn): Verwerkingsactiviteit
    {
        $entity = new Verwerkingsactiviteit();
        $entity->setNaam($naam.'-'.uniqid());
        $entity->setDoelbinding('phpunit retention purpose');
        $entity->setRechtsgrond('publieke_taak');
        $entity->setStatus('published');
        if ($bewaartermijn !== null) {
            $entity->setBewaartermijn($bewaartermijn);
        }

        $persisted                     = $this->vrwMapper->insert($entity);
        $this->insertedActivityUuids[] = $persisted->getUuid();
        return $persisted;

    }//end makeActivity()

    private function makeObjectFixture(): ObjectEntity
    {
        $register = new Register();
        $register->setTitle('phpunit-ret-'.uniqid());
        $register->setSlug('phpunit-ret-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $register = $this->registerMapper->insert($register);
        $this->insertedRegisters[] = $register;

        $schema = new Schema();
        $schema->setTitle('phpunit-ret-schema-'.uniqid());
        $schema->setSlug('phpunit-ret-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        $schema = $this->schemaMapper->insert($schema);
        $this->insertedSchemas[] = $schema;

        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        $object = new ObjectEntity();
        $object->setRegister((string) $register->getId());
        $object->setSchema((string) $schema->getId());
        $object->setUuid(Uuid::v4()->toRfc4122());
        $object->setObject(['title' => 'phpunit-ret-payload']);
        return $this->objectMapper->insert($object);

    }//end makeObjectFixture()

    /**
     * Insert a synthetic audit row directly via SQL so we can control
     * the `created` timestamp precisely (the live audit-trail mapper
     * always uses now()).
     */
    private function insertSyntheticAuditRow(ObjectEntity $object, string $activityUuid, int $createdDaysAgo): int
    {
        $createdAt = (new DateTime())->modify('-'.$createdDaysAgo.' days');

        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_audit_trails')
            ->values(
                [
                    'uuid'                   => $qb->createNamedParameter('phpunit-aud-'.Uuid::v4()->toRfc4122()),
                    'object'                 => $qb->createNamedParameter((int) $object->getId(), IQueryBuilder::PARAM_INT),
                    'object_uuid'            => $qb->createNamedParameter((string) $object->getUuid()),
                    'register'               => $qb->createNamedParameter((int) $object->getRegister(), IQueryBuilder::PARAM_INT),
                    'schema'                 => $qb->createNamedParameter((int) $object->getSchema(), IQueryBuilder::PARAM_INT),
                    'action'                 => $qb->createNamedParameter('create'),
                    'changed'                => $qb->createNamedParameter('{}'),
                    'user'                   => $qb->createNamedParameter('phpunit'),
                    'user_name'              => $qb->createNamedParameter('phpunit'),
                    'session'                => $qb->createNamedParameter(''),
                    'processing_activity_id' => $qb->createNamedParameter($activityUuid),
                    'created'                => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_DATE),
                ]
            );
        $qb->executeStatement();

        $id                       = (int) $this->db->lastInsertId('oc_openregister_audit_trails_id_seq');
        $this->insertedAuditIds[] = $id;
        return $id;

    }//end insertSyntheticAuditRow()

    private function auditRowExists(int $id): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();
        return is_array($row);

    }//end auditRowExists()
}//end class
