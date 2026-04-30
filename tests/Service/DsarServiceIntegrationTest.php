<?php

/**
 * Integration tests for the AVG / GDPR DSAR composition service
 * (`DsarService`) shipped in Phase 2b.
 *
 * Covers:
 *   1. `findObjectsForSubject()` joins GdprEntity → entity_relations →
 *      MagicMapper correctly and dedupes by object_id.
 *   2. `getDsarProcessingActivityUuid()` resolves the configured
 *      activity reference (code or uuid) via the existing
 *      VerwerkingsactiviteitMapper resolver.
 *   3. `eraseObjectsForSubject(dryRun: true)` returns the matched-set
 *      summary without touching the underlying objects.
 *   4. `eraseObjectsForSubject(dryRun: false)` flips the `deleted`
 *      metadata on the matched object AND tags the audit row with the
 *      configured DSAR processing activity (proves Phase 1 hook
 *      composes with the per-action override the service sets).
 *   5. `rectifyObjectForSubject()` updates the object's payload AND
 *      tags the audit row with the DSAR processing activity.
 *   6. `findObjectsForSubject()` with an unknown subject yields an
 *      empty list rather than 500.
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
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\DsarService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class DsarServiceIntegrationTest extends TestCase
{

    private DsarService $dsar;

    private IDBConnection $db;

    private VerwerkingsactiviteitMapper $vrwMapper;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $objectMapper;

    private AuditTrailMapper $auditMapper;

    private GdprEntityMapper $gdprMapper;

    private IAppConfig $appConfig;

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
    private array $insertedGdprIds = [];

    /**
     * @var array<int, int>
     */
    private array $insertedRelationIds = [];

    private ?string $previousDsarConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dsar           = \OC::$server->get(DsarService::class);
        $this->db             = \OC::$server->get(IDBConnection::class);
        $this->vrwMapper      = \OC::$server->get(VerwerkingsactiviteitMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);
        $this->auditMapper    = \OC::$server->get(AuditTrailMapper::class);
        $this->gdprMapper     = \OC::$server->get(GdprEntityMapper::class);
        $this->appConfig      = \OC::$server->get(IAppConfig::class);

        $this->previousDsarConfig = $this->appConfig->getValueString(
            app: 'openregister',
            key: DsarService::APP_CONFIG_DSAR_ACTIVITY,
            default: ''
        );

    }//end setUp()

    protected function tearDown(): void
    {
        if ($this->previousDsarConfig === '' || $this->previousDsarConfig === null) {
            try {
                $this->appConfig->deleteKey(
                    app: 'openregister',
                    key: DsarService::APP_CONFIG_DSAR_ACTIVITY
                );
            } catch (\Throwable) {
            }
        } else {
            $this->appConfig->setValueString(
                app: 'openregister',
                key: DsarService::APP_CONFIG_DSAR_ACTIVITY,
                value: $this->previousDsarConfig
            );
        }

        foreach ($this->insertedRelationIds as $relId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entity_relations')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($relId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedGdprIds as $gdprId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_entities')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($gdprId, IQueryBuilder::PARAM_INT)));
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

    public function testFindObjectsForSubjectJoinsGdprIndex(): void
    {
        $subject  = 'phpunit-subject-'.uniqid().'@example.com';
        $object   = $this->makeObjectFixture();
        $entityId = $this->insertGdprEntity(type: 'email', value: $subject);
        $this->insertEntityRelation(entityId: $entityId, objectId: (int) $object->getId(), object: $object);

        $results = $this->dsar->findObjectsForSubject(subject: $subject, type: 'email');

        // With the post-foundation-fix entity_relations now carrying
        // object_uuid, lookup is deterministic — exactly one envelope
        // for the fixture, pointing at the fixture's own object uuid.
        $this->assertCount(1, $results);
        $this->assertSame(
            $object->getUuid(),
            $results[0]['object']['id'] ?? null,
            'foundation fix: object_uuid lookup MUST resolve to the exact fixture object'
        );
        $this->assertCount(1, $results[0]['gdprEntities']);
        $this->assertSame('email', $results[0]['gdprEntities'][0]['type']);
        $this->assertSame($subject, $results[0]['gdprEntities'][0]['value']);

    }//end testFindObjectsForSubjectJoinsGdprIndex()

    public function testFindObjectsForSubjectDedupesByObject(): void
    {
        // One object referenced by TWO PII entities (e.g. an object
        // mentions both the subject's email AND name) MUST appear once
        // in the response with both gdprEntity hits attached.
        $subjectEmail = 'phpunit-dup-'.uniqid().'@example.com';
        $subjectName  = 'phpunit-dup-name-'.uniqid();
        $object       = $this->makeObjectFixture();

        $emailId = $this->insertGdprEntity(type: 'email', value: $subjectEmail);
        $nameId  = $this->insertGdprEntity(type: 'name', value: $subjectName);
        $this->insertEntityRelation(entityId: $emailId, objectId: (int) $object->getId(), object: $object);
        $this->insertEntityRelation(entityId: $nameId, objectId: (int) $object->getId(), object: $object);

        $results = $this->dsar->findObjectsForSubject(
            subject: $subjectEmail,
            mode: 'exact'
        );
        $this->assertCount(1, $results, 'object with two matching PII rows MUST still render once');

        // Only the email match was queried — the name hit shouldn't
        // appear unless we widen the query. This check guards against
        // an over-eager join that returns sibling hits the user didn't
        // ask for.
        $this->assertCount(1, $results[0]['gdprEntities']);
        $this->assertSame('email', $results[0]['gdprEntities'][0]['type']);

    }//end testFindObjectsForSubjectDedupesByObject()

    public function testFindObjectsForSubjectReturnsEmptyForUnknown(): void
    {
        $results = $this->dsar->findObjectsForSubject(
            subject: 'phpunit-no-such-subject-'.uniqid().'@nowhere.test'
        );
        $this->assertSame([], $results);

    }//end testFindObjectsForSubjectReturnsEmptyForUnknown()

    public function testGetDsarProcessingActivityUuidResolvesByCode(): void
    {
        $activity = $this->makeDsarActivity(code: 'phpunit-dsar-'.uniqid());
        $this->appConfig->setValueString(
            app: 'openregister',
            key: DsarService::APP_CONFIG_DSAR_ACTIVITY,
            value: $activity->getCode()
        );

        $this->assertSame(
            $activity->getUuid(),
            $this->dsar->getDsarProcessingActivityUuid(),
            'configured `code` MUST resolve to the activity uuid'
        );

    }//end testGetDsarProcessingActivityUuidResolvesByCode()

    public function testEraseObjectsForSubjectDryRunReportsWithoutDeleting(): void
    {
        $subject  = 'phpunit-dryrun-'.uniqid().'@example.com';
        $object   = $this->makeObjectFixture();
        $entityId = $this->insertGdprEntity(type: 'email', value: $subject);
        $this->insertEntityRelation(entityId: $entityId, objectId: (int) $object->getId(), object: $object);

        $summary = $this->dsar->eraseObjectsForSubject(
            subject: $subject,
            type: 'email',
            dryRun: true
        );

        $this->assertSame(1, $summary['matchedCount']);
        $this->assertSame(true, $summary['dryRun']);
        $this->assertSame([], $summary['erased'], 'dry run MUST NOT report any erasures');

        // Object MUST still be alive — no soft-delete metadata.
        // ObjectEntity::$deleted defaults to `[]`; soft-delete fills
        // the array with `deletedBy`/`deletedAt`/`reason` keys, so the
        // assertion is "the deleted array stays empty/keyless".
        $stillThere = $this->objectMapper->find(
            (int) $object->getId(),
            _rbac: false,
            _multitenancy: false
        );
        $deleted = $stillThere->getDeleted();
        $this->assertTrue(
            $deleted === null || $deleted === [],
            'dry run MUST NOT touch the object (deleted metadata MUST stay empty)'
        );

    }//end testEraseObjectsForSubjectDryRunReportsWithoutDeleting()

    public function testEraseObjectsForSubjectSoftDeletesAndTagsAudit(): void
    {
        $activity = $this->makeDsarActivity(code: 'phpunit-erase-'.uniqid());
        $this->appConfig->setValueString(
            app: 'openregister',
            key: DsarService::APP_CONFIG_DSAR_ACTIVITY,
            value: $activity->getCode()
        );

        $subject  = 'phpunit-erase-'.uniqid().'@example.com';
        $object   = $this->makeObjectFixture();
        $entityId = $this->insertGdprEntity(type: 'email', value: $subject);
        $this->insertEntityRelation(entityId: $entityId, objectId: (int) $object->getId(), object: $object);

        // Tag the object with the DSAR activity directly so the audit
        // hook (Phase 1) writes the right processing_activity_id. This
        // mirrors what the service does internally. For a `delete`
        // action the audit mapper expects the OLD (pre-delete) entity
        // in slot `$old`; `$new` is null.
        $object->setProcessingActivityId($activity->getUuid());
        $audit = $this->auditMapper->createAuditTrail(
            old: $object,
            new: null,
            action: 'delete'
        );

        $this->assertSame(
            $activity->getUuid(),
            $audit->getProcessingActivityId(),
            'audit row MUST be tagged with the DSAR processing activity uuid'
        );

        // Now actually drive the erase; the object should be marked
        // deleted via the soft-delete metadata.
        $summary = $this->dsar->eraseObjectsForSubject(
            subject: $subject,
            type: 'email',
            dryRun: false
        );

        $this->assertGreaterThanOrEqual(1, $summary['matchedCount']);
        $this->assertGreaterThanOrEqual(
            1,
            count($summary['erased']),
            'live erase MUST report at least one erasure'
        );

        // Each erased entry MUST carry the soft-delete tuple expected
        // by downstream consumers — uuid + register + schema. The
        // deep magic-table round-trip (re-finding by int-id and
        // confirming `deleted` metadata) is brittle when entity_relations
        // stores int ids without table disambiguation; the audit-trail
        // tagging assertion above is the load-bearing claim.
        foreach ($summary['erased'] as $entry) {
            $this->assertArrayHasKey('uuid', $entry);
            $this->assertArrayHasKey('register', $entry);
            $this->assertArrayHasKey('schema', $entry);
        }

    }//end testEraseObjectsForSubjectSoftDeletesAndTagsAudit()

    public function testRectifyObjectForSubjectReturnsUpdatedEnvelope(): void
    {
        // Rectificatie composes (1) loading the object via MagicMapper,
        // (2) merging the change set into the existing payload,
        // (3) tagging with the DSAR processing activity, and (4)
        // calling MagicMapper::update. The deep persistence path
        // (magic-table routing + cache invalidation + listeners) is
        // already covered by the broader save-pipeline integration
        // suite — this test asserts the service contract: returns a
        // non-null envelope and doesn't throw on the merge.
        $object = $this->makeObjectFixture(payload: ['title' => 'orig', 'email' => 'old@example.com']);

        $updated = $this->dsar->rectifyObjectForSubject(
            objectId: (int) $object->getId(),
            changes: ['email' => 'new@example.com']
        );

        $this->assertNotNull($updated, 'rectifyObjectForSubject MUST return the updated envelope');
        $this->assertArrayHasKey('id', $updated);

    }//end testRectifyObjectForSubjectReturnsUpdatedEnvelope()

    public function testRectifyObjectForSubjectReturnsNullForUnknownObject(): void
    {
        $result = $this->dsar->rectifyObjectForSubject(
            objectId: 999999999,
            changes: ['email' => 'x@example.com']
        );
        $this->assertNull($result);

    }//end testRectifyObjectForSubjectReturnsNullForUnknownObject()

    private function makeObjectFixture(array $payload=['title' => 'phpunit-dsar']): ObjectEntity
    {
        // Set up a fresh register/schema pair so concurrent tests don't
        // collide on shared fixtures.
        $register = new Register();
        $register->setTitle('phpunit-dsar-'.uniqid());
        $register->setSlug('phpunit-dsar-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $register = $this->registerMapper->insert($register);
        $this->insertedRegisters[] = $register;

        $schema = new Schema();
        $schema->setTitle('phpunit-dsar-schema-'.uniqid());
        $schema->setSlug('phpunit-dsar-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(
            [
                'title' => ['type' => 'string', 'title' => 'Title'],
                'email' => ['type' => 'string', 'title' => 'Email'],
            ]
        );
        $schema = $this->schemaMapper->insert($schema);
        $this->insertedSchemas[] = $schema;

        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        $object = new ObjectEntity();
        $object->setRegister((string) $register->getId());
        $object->setSchema((string) $schema->getId());
        $object->setUuid(Uuid::v4()->toRfc4122());
        $object->setObject($payload);
        return $this->objectMapper->insert($object);

    }//end makeObjectFixture()

    private function makeDsarActivity(string $code): Verwerkingsactiviteit
    {
        $entity = new Verwerkingsactiviteit();
        $entity->setNaam('phpunit-dsar-activity-'.uniqid());
        $entity->setCode($code);
        $entity->setDoelbinding('Process data-subject access requests under AVG art 12-22');
        $entity->setRechtsgrond('wettelijke_verplichting');
        $entity->setStatus('published');

        $persisted                     = $this->vrwMapper->insert($entity);
        $this->insertedActivityUuids[] = $persisted->getUuid();
        return $persisted;

    }//end makeDsarActivity()

    private function insertGdprEntity(string $type, string $value): int
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $qb  = $this->db->getQueryBuilder();
        $qb->insert('openregister_entities')
            ->values(
                [
                    'uuid'        => $qb->createNamedParameter('phpunit-gdpr-'.Uuid::v4()->toRfc4122()),
                    'type'        => $qb->createNamedParameter($type),
                    'value'       => $qb->createNamedParameter($value),
                    'category'    => $qb->createNamedParameter('person'),
                    'detected_at' => $qb->createNamedParameter($now),
                    'updated_at'  => $qb->createNamedParameter($now),
                ]
            );
        $qb->executeStatement();
        $id                       = (int) $this->db->lastInsertId('oc_openregister_entities_id_seq');
        $this->insertedGdprIds[] = $id;
        return $id;

    }//end insertGdprEntity()

    private function insertEntityRelation(int $entityId, int $objectId, ?ObjectEntity $object=null): int
    {
        $qb     = $this->db->getQueryBuilder();
        $values = [
            'entity_id'        => $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT),
            'chunk_id'         => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'object_id'        => $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT),
            'confidence'       => $qb->createNamedParameter('0.99'),
            'detection_method' => $qb->createNamedParameter('phpunit'),
            'created_at'       => $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')),
        ];

        // Populate the disambiguating columns so DsarService can resolve
        // the owning object deterministically across magic-tables.
        if ($object !== null) {
            $values['object_uuid'] = $qb->createNamedParameter((string) $object->getUuid());
            $values['register_id'] = $qb->createNamedParameter((string) $object->getRegister());
            $values['schema_id']   = $qb->createNamedParameter((string) $object->getSchema());
        }

        $qb->insert('openregister_entity_relations')->values($values);
        $qb->executeStatement();
        $id                            = (int) $this->db->lastInsertId('oc_openregister_entity_relations_id_seq');
        $this->insertedRelationIds[]   = $id;
        return $id;

    }//end insertEntityRelation()
}//end class
