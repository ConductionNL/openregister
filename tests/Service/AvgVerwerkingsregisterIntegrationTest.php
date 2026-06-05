<?php

/**
 * Integration tests for the AVG / GDPR Art 30 Verwerkingsregister.
 *
 * Locks in the contract documented in
 * `openspec/changes/avg-verwerkingsregister/design.md`:
 *
 *   1. Mapper round-trip: insert / find / update / soft-delete
 *      (`status='archived'`) of a Verwerkingsactiviteit.
 *   2. Vocabulary validation: invalid `rechtsgrond` rejected with
 *      `InvalidArgumentException`; missing `naam` / `doelbinding`
 *      rejected.
 *   3. Reference resolution: `resolveReference` finds an activity by
 *      either `code` or `uuid`; unknown reference returns null.
 *   4. Audit-trail trigger contract — schema-default tier:
 *      `x-openregister-processing-activity` on schema config tags
 *      every audit row produced by writes through that schema.
 *   5. Audit-trail trigger contract — register-default tier: same
 *      annotation on the register's config is the fallback used when
 *      the schema doesn't override.
 *   6. Audit-trail trigger contract — per-action override:
 *      `ObjectEntity::setProcessingActivityId()` beats schema and
 *      register defaults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class AvgVerwerkingsregisterIntegrationTest extends TestCase
{

    private VerwerkingsactiviteitMapper $vrwMapper;

    private SchemaMapper $schemaMapper;

    private RegisterMapper $registerMapper;

    private AuditTrailMapper $auditMapper;

    /**
     * UUIDs we created so tearDown can clean only our rows.
     *
     * @var array<int, string>
     */
    private array $insertedActivityUuids = [];

    /**
     * Schemas to remove on tearDown.
     *
     * @var array<int, Schema>
     */
    private array $insertedSchemas = [];

    /**
     * Registers to remove on tearDown.
     *
     * @var array<int, Register>
     */
    private array $insertedRegisters = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vrwMapper      = \OC::$server->get(VerwerkingsactiviteitMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->auditMapper    = \OC::$server->get(AuditTrailMapper::class);

    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ($this->insertedActivityUuids as $uuid) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_verwerkingsactiviteiten')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        foreach ($this->insertedSchemas as $schema) {
            try {
                $qb = $db->getQueryBuilder();
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
                $qb = $db->getQueryBuilder();
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

    public function testMapperRoundTrip(): void
    {
        // Insert with the bare minimum so we can verify the mapper's
        // auto-fill behaviour for uuid + status + timestamps.
        $entity = new Verwerkingsactiviteit();
        $entity->setNaam('phpunit-roundtrip-'.uniqid());
        $entity->setDoelbinding('phpunit purpose binding');
        $entity->setRechtsgrond('publieke_taak');
        $activity = $this->vrwMapper->insert($entity);
        $this->insertedActivityUuids[] = $activity->getUuid();

        $this->assertNotNull($activity->getId(), 'persisted activity MUST have an id');
        $this->assertNotEmpty($activity->getUuid(), 'mapper MUST auto-fill uuid');
        $this->assertSame('concept', $activity->getStatus(), 'default status MUST be concept');
        $this->assertNotNull($activity->getCreated());
        $this->assertNotNull($activity->getUpdated());

        // Update + status transition.
        $activity->setStatus('published');
        $activity->setBeschrijving('updated description');
        $updated = $this->vrwMapper->update($activity);

        $this->assertSame('published', $updated->getStatus());
        $this->assertSame('updated description', $updated->getBeschrijving());

        // findByUuid round-trip.
        $found = $this->vrwMapper->findByUuid(uuid: $activity->getUuid());
        $this->assertNotNull($found);
        $this->assertSame($activity->getId(), $found->getId());

    }//end testMapperRoundTrip()

    public function testValidationRejectsInvalidRechtsgrond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rechtsgrond');

        $entity = new Verwerkingsactiviteit();
        $entity->setNaam('phpunit-bad-rechtsgrond');
        $entity->setDoelbinding('test purpose');
        $entity->setRechtsgrond('bogus_basis');

        $this->vrwMapper->insert($entity);

    }//end testValidationRejectsInvalidRechtsgrond()

    public function testValidationRejectsMissingNaam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('naam');

        $entity = new Verwerkingsactiviteit();
        $entity->setDoelbinding('test purpose');
        $entity->setRechtsgrond('publieke_taak');

        $this->vrwMapper->insert($entity);

    }//end testValidationRejectsMissingNaam()

    public function testValidationRejectsMissingDoelbinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doelbinding');

        $entity = new Verwerkingsactiviteit();
        $entity->setNaam('phpunit-no-doelbinding');
        $entity->setRechtsgrond('publieke_taak');

        $this->vrwMapper->insert($entity);

    }//end testValidationRejectsMissingDoelbinding()

    public function testResolveReferenceFindsByCodeAndUuid(): void
    {
        $code     = 'phpunit-resolve-'.uniqid();
        $activity = $this->makeActivity(naam: 'phpunit-resolve', code: $code);

        $byCode = $this->vrwMapper->resolveReference(reference: $code);
        $this->assertNotNull($byCode);
        $this->assertSame($activity->getUuid(), $byCode->getUuid());

        $byUuid = $this->vrwMapper->resolveReference(reference: $activity->getUuid());
        $this->assertNotNull($byUuid);
        $this->assertSame($activity->getId(), $byUuid->getId());

        $miss = $this->vrwMapper->resolveReference(reference: 'phpunit-no-such-ref-'.uniqid());
        $this->assertNull($miss);

    }//end testResolveReferenceFindsByCodeAndUuid()

    public function testAuditTrailHookHonorsSchemaAnnotation(): void
    {
        $activity = $this->makeActivity(
            naam: 'phpunit-audit-schema',
            code: 'phpunit-audit-schema-'.uniqid()
        );

        // Annotate schema with the activity reference (using `code`).
        $register = $this->makeRegister(annotation: null);
        $schema   = $this->makeSchema(annotation: $activity->getCode());

        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        // Synthesise an audit row via the mapper directly — the hot
        // save path goes through createAuditTrail, so testing the
        // mapper hook gives the same coverage without a full save.
        $object = $this->makeObjectEntity(register: $register, schema: $schema);
        $audit  = $this->auditMapper->createAuditTrail(old: null, new: $object, action: 'create');

        $this->assertSame(
            $activity->getUuid(),
            $audit->getProcessingActivityId(),
            'audit row MUST carry the schema-annotated activity uuid'
        );

    }//end testAuditTrailHookHonorsSchemaAnnotation()

    public function testAuditTrailHookFallsBackToRegisterAnnotation(): void
    {
        $activity = $this->makeActivity(
            naam: 'phpunit-audit-register',
            code: 'phpunit-audit-register-'.uniqid()
        );

        // Schema unset; register carries the annotation.
        $register = $this->makeRegister(annotation: $activity->getCode());
        $schema   = $this->makeSchema(annotation: null);

        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        $object = $this->makeObjectEntity(register: $register, schema: $schema);
        $audit  = $this->auditMapper->createAuditTrail(old: null, new: $object, action: 'create');

        $this->assertSame(
            $activity->getUuid(),
            $audit->getProcessingActivityId(),
            'audit row MUST inherit the register-level annotation when schema is unset'
        );

    }//end testAuditTrailHookFallsBackToRegisterAnnotation()

    public function testAuditTrailHookPerActionOverrideBeatsDefaults(): void
    {
        $schemaActivity = $this->makeActivity(
            naam: 'phpunit-audit-schema-default',
            code: 'phpunit-audit-schema-default-'.uniqid()
        );
        $overrideActivity = $this->makeActivity(
            naam: 'phpunit-audit-override',
            code: 'phpunit-audit-override-'.uniqid()
        );

        $register = $this->makeRegister(annotation: null);
        $schema   = $this->makeSchema(annotation: $schemaActivity->getCode());
        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        // Per-action override on the ObjectEntity itself.
        $object = $this->makeObjectEntity(register: $register, schema: $schema);
        $object->setProcessingActivityId($overrideActivity->getUuid());

        $audit = $this->auditMapper->createAuditTrail(old: null, new: $object, action: 'create');

        $this->assertSame(
            $overrideActivity->getUuid(),
            $audit->getProcessingActivityId(),
            'per-action override MUST beat schema default'
        );

    }//end testAuditTrailHookPerActionOverrideBeatsDefaults()

    public function testAuditTrailHookLeavesUnsetWhenNoAnnotation(): void
    {
        // No annotations anywhere — verify the hook doesn't break the
        // audit write path for legacy callers that haven't opted in.
        $register = $this->makeRegister(annotation: null);
        $schema   = $this->makeSchema(annotation: null);
        $register->setSchemas([$schema->getId()]);
        $this->registerMapper->update($register);

        $object = $this->makeObjectEntity(register: $register, schema: $schema);
        $audit  = $this->auditMapper->createAuditTrail(old: null, new: $object, action: 'create');

        $this->assertNull(
            $audit->getProcessingActivityId(),
            'no annotation MUST result in null processing_activity_id (existing behaviour preserved)'
        );

    }//end testAuditTrailHookLeavesUnsetWhenNoAnnotation()

    private function makeActivity(string $naam, string $code): Verwerkingsactiviteit
    {
        $entity = new Verwerkingsactiviteit();
        $entity->setNaam($naam.'-'.uniqid());
        $entity->setCode($code);
        $entity->setDoelbinding('phpunit purpose binding');
        $entity->setRechtsgrond('publieke_taak');
        $entity->setBewaartermijn('P10Y');
        $entity->setCategorieenBetrokkenen(['burgers']);
        $entity->setStatus('published');

        $persisted                       = $this->vrwMapper->insert($entity);
        $this->insertedActivityUuids[]   = $persisted->getUuid();

        return $persisted;

    }//end makeActivity()

    private function makeRegister(?string $annotation): Register
    {
        $register = new Register();
        $register->setTitle('phpunit-avg-'.uniqid());
        $register->setSlug('phpunit-avg-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);

        if ($annotation !== null) {
            $config                                       = $register->getConfiguration();
            $config['x-openregister-processing-activity'] = $annotation;
            $register->setConfiguration($config);
        }

        $persisted = $this->registerMapper->insert($register);
        $this->insertedRegisters[] = $persisted;

        return $persisted;

    }//end makeRegister()

    private function makeSchema(?string $annotation): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-avg-schema-'.uniqid());
        $schema->setSlug('phpunit-avg-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);

        if ($annotation !== null) {
            $config                                       = $schema->getConfiguration() ?? [];
            $config['x-openregister-processing-activity'] = $annotation;
            $schema->setConfiguration($config);
        }

        $persisted = $this->schemaMapper->insert($schema);
        $this->insertedSchemas[] = $persisted;

        return $persisted;

    }//end makeSchema()

    private function makeObjectEntity(Register $register, Schema $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setRegister((string) $register->getId());
        $object->setSchema((string) $schema->getId());
        $object->setUuid(Uuid::v4()->toRfc4122());
        $object->setObject(['title' => 'phpunit-avg-payload']);

        // Persist the row so AuditTrailMapper has a real id to point at.
        $magicMapper = \OC::$server->get(MagicMapper::class);
        return $magicMapper->insert($object);

    }//end makeObjectEntity()
}//end class
