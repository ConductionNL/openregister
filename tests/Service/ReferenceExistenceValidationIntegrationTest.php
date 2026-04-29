<?php

/**
 * Integration tests for reference-existence validation on save.
 *
 * The mechanism: when a schema property has both `$ref` (pointing at
 * another schema) AND `validateReference: true`, the save pipeline
 * MUST verify the referenced UUID corresponds to a real object in the
 * target schema before persisting. Missing references reject with
 * `ValidationException`; valid references pass through; null / empty
 * references are accepted (the property is optional).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ReferenceExistenceValidationIntegrationTest extends TestCase
{
    private SaveObject $saveHandler;
    private ObjectService $objectService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $targetSchema = null;
    private ?Schema $referrerSchema = null;
    /** @var string[] */
    private array $createdObjectUuids = [];
    /** @var string[] */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saveHandler    = \OC::$server->get(SaveObject::class);
        $this->objectService  = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Throwable $e) {
                // best effort
            }
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->referrerSchema, $this->targetSchema] as $schema) {
            if ($schema === null) {
                continue;
            }
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        foreach ($this->createdTables as $table) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"$table\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }

    public function testValidUuidReferencePassesValidation(): void
    {
        // Set up: persist a target object, then save a referrer that
        // points at it. The save should succeed because the reference
        // resolves to a real persisted object.
        $target = $this->saveTarget(['title' => 'Existing target']);

        $referrer = $this->saveReferrer(['title' => 'Referrer', 'targetUuid' => $target->getUuid()]);

        $this->assertSame($target->getUuid(), ($referrer->getObject() ?? [])['targetUuid'] ?? null);
    }

    public function testNonExistentUuidReferenceIsRejected(): void
    {
        // Reference points at a UUID that was never persisted. Save MUST
        // throw a ValidationException with HTTP 422 semantics — pre-fix
        // this would have silently saved the dangling reference.
        $this->expectException(ValidationException::class);

        $this->saveReferrer([
            'title'      => 'Referrer with bad UUID',
            'targetUuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
    }

    public function testNullReferenceIsAccepted(): void
    {
        // Optional references (no value) MUST NOT trigger validation.
        // The property is empty, there's nothing to validate. The save
        // path may default-fill the field with null/empty — both are
        // acceptable; what matters is that no ValidationException fires
        // and the save completes.
        $referrer = $this->saveReferrer([
            'title' => 'Referrer with no target',
            // no targetUuid key at all
        ]);

        $data = $referrer->getObject() ?? [];
        if (array_key_exists('targetUuid', $data) === true) {
            $this->assertEmpty($data['targetUuid'], 'absent reference must not be auto-populated');
        }
        $this->assertNotNull($referrer->getUuid());
    }

    public function testEmptyStringReferenceIsAccepted(): void
    {
        // Defence-in-depth: explicit empty string for an optional ref
        // MUST NOT crash with a "uuid not found" error — the save path
        // treats empty strings as "no value".
        $referrer = $this->saveReferrer([
            'title'      => 'Referrer with empty target',
            'targetUuid' => '',
        ]);

        // The empty string may or may not appear in stored data; what
        // matters is that the save did not fail with a validation error.
        $this->assertNotNull($referrer->getUuid());
    }

    public function testUpdateWithUnchangedReferenceSkipsValidation(): void
    {
        // Once an object is saved with a valid reference, updating
        // unrelated fields MUST NOT re-validate the reference (and so
        // MUST NOT fail even if the target was deleted between saves).
        // This protects against a class of cascading-failure bugs.
        $target   = $this->saveTarget(['title' => 'Will be deleted']);
        $referrer = $this->saveReferrer(['title' => 'V1', 'targetUuid' => $target->getUuid()]);

        // Delete the target out from under the reference.
        try {
            $this->objectService->deleteObject($target->getUuid(), false, false);
        } catch (\Throwable $e) {
            // best effort
        }

        // Update an unrelated field on the referrer — MUST succeed
        // because the reference is unchanged.
        $updated = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->referrerSchema,
            ['title' => 'V2 (updated)', 'targetUuid' => $target->getUuid()],
            $referrer->getUuid(),
            null,
            false,
            false
        );

        $this->assertSame('V2 (updated)', ($updated->getObject() ?? [])['title']);
    }

    private function saveTarget(array $data): ObjectEntity
    {
        $object = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->targetSchema,
            $data,
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $object->getUuid();
        return $object;
    }

    private function saveReferrer(array $data): ObjectEntity
    {
        $object = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->referrerSchema,
            $data,
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $object->getUuid();
        return $object;
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-refexist-' . uniqid());
        $register->setDescription('Reference existence validation tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-refexist-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        // Target schema — the schema that referrers point AT.
        $target = new Schema();
        $target->setTitle('phpunit-refexist-target-' . uniqid());
        $target->setDescription('Target of reference validation');
        $target->setUuid(Uuid::v4()->toRfc4122());
        $target->setSlug('phpunit-refexist-target-' . uniqid());
        $target->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $this->targetSchema = $this->schemaMapper->insert($target);

        // Referrer schema — has a property pointing at target with
        // validateReference enabled. The save pipeline will check that
        // the UUID stored in `targetUuid` resolves to a real target object.
        $referrer = new Schema();
        $referrer->setTitle('phpunit-refexist-referrer-' . uniqid());
        $referrer->setDescription('Referrer schema with validateReference: true');
        $referrer->setUuid(Uuid::v4()->toRfc4122());
        $referrer->setSlug('phpunit-refexist-referrer-' . uniqid());
        $referrer->setProperties([
            'title'      => ['type' => 'string', 'title' => 'Title'],
            'targetUuid' => [
                'type'              => 'string',
                'title'             => 'Target reference',
                '$ref'              => '#/components/schemas/' . $target->getSlug(),
                'validateReference' => true,
            ],
        ]);
        $this->referrerSchema = $this->schemaMapper->insert($referrer);

        $this->testRegister->setSchemas([$this->targetSchema->getId(), $this->referrerSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->targetSchema);
        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->referrerSchema);
        $this->createdTables[] = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->targetSchema);
        $this->createdTables[] = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->referrerSchema);
    }
}
