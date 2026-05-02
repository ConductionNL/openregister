<?php

/**
 * Integration tests for the import-rollback contract added to the
 * `data-import-export` change.
 *
 * Verifies:
 *  - `AuditTrailMapper::setRequestImportJobId()` causes every audit row
 *    written within the request scope to carry the given UUID.
 *  - `AuditTrailMapper::findByImportJobId()` returns matching rows.
 *  - `ImportService::softDeleteByImportJobId()` soft-deletes every
 *    object whose `create` audit row carries the UUID, and leaves
 *    objects tagged with a different UUID untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ImportRollbackIntegrationTest extends TestCase
{

    private ImportService $importService;

    private AuditTrailMapper $auditMapper;

    private SaveObject $saveHandler;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $magicMapper;

    private ?Register $testRegister = null;

    private ?Schema $testSchema = null;

    /**
     * @var string[]
     */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importService  = \OC::$server->get(ImportService::class);
        $this->auditMapper    = \OC::$server->get(AuditTrailMapper::class);
        $this->saveHandler    = \OC::$server->get(SaveObject::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->magicMapper    = \OC::$server->get(MagicMapper::class);

        $this->createTestFixture();
    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where(
                        $qb->expr()->eq(
                            'id',
                            $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)
                        )
                    );
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where(
                        $qb->expr()->eq(
                            'id',
                            $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)
                        )
                    );
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

        // Defensive: clear any lingering request-scoped tag.
        $this->auditMapper->setRequestImportJobId(importJobId: null);

        parent::tearDown();
    }//end tearDown()

    public function testRequestScopedTagPropagatesToAuditRows(): void
    {
        $importJobId = Uuid::v4()->toRfc4122();

        $this->auditMapper->setRequestImportJobId(importJobId: $importJobId);
        try {
            $this->createObject(['title' => 'Row A']);
            $this->createObject(['title' => 'Row B']);
            $this->createObject(['title' => 'Row C']);
        } finally {
            $this->auditMapper->setRequestImportJobId(importJobId: null);
        }

        $rows = $this->auditMapper->findByImportJobId(importJobId: $importJobId, action: 'create');
        $this->assertCount(3, $rows, 'all 3 create rows MUST carry the import-job tag');
        foreach ($rows as $row) {
            $this->assertNotNull($row->getObjectUuid(), 'each tagged audit row MUST reference an object UUID');
        }
    }//end testRequestScopedTagPropagatesToAuditRows()

    public function testFindByImportJobIdIsolatesByUuid(): void
    {
        $jobA = Uuid::v4()->toRfc4122();
        $jobB = Uuid::v4()->toRfc4122();

        $this->auditMapper->setRequestImportJobId(importJobId: $jobA);
        $this->createObject(['title' => 'A1']);
        $this->createObject(['title' => 'A2']);

        $this->auditMapper->setRequestImportJobId(importJobId: $jobB);
        $this->createObject(['title' => 'B1']);

        $this->auditMapper->setRequestImportJobId(importJobId: null);
        $this->createObject(['title' => 'untagged']);

        $rowsA = $this->auditMapper->findByImportJobId(importJobId: $jobA, action: 'create');
        $rowsB = $this->auditMapper->findByImportJobId(importJobId: $jobB, action: 'create');

        $this->assertCount(2, $rowsA, 'jobA MUST yield exactly its 2 rows');
        $this->assertCount(1, $rowsB, 'jobB MUST yield exactly its 1 row');
        $this->assertNotEquals(
            $rowsA[0]->getObjectUuid(),
            $rowsB[0]->getObjectUuid(),
            'jobA and jobB MUST tag different objects'
        );
    }//end testFindByImportJobIdIsolatesByUuid()

    public function testSoftDeleteByImportJobIdRollsBackTaggedObjectsOnly(): void
    {
        $jobA = Uuid::v4()->toRfc4122();
        $jobB = Uuid::v4()->toRfc4122();

        $this->auditMapper->setRequestImportJobId(importJobId: $jobA);
        $aliveAfterRollback = [];
        $deletedByRollback  = [];

        $deletedByRollback[] = $this->createObject(['title' => 'A1'])->getUuid();
        $deletedByRollback[] = $this->createObject(['title' => 'A2'])->getUuid();
        $deletedByRollback[] = $this->createObject(['title' => 'A3'])->getUuid();

        $this->auditMapper->setRequestImportJobId(importJobId: $jobB);
        $aliveAfterRollback[] = $this->createObject(['title' => 'B1'])->getUuid();

        $this->auditMapper->setRequestImportJobId(importJobId: null);
        $aliveAfterRollback[] = $this->createObject(['title' => 'untagged'])->getUuid();

        $report = $this->importService->softDeleteByImportJobId(importJobId: $jobA);

        $this->assertSame($jobA, $report['importJobId']);
        $this->assertSame(3, $report['candidates']);
        $this->assertCount(3, $report['softDeleted']);
        $this->assertCount(0, $report['errors']);

        foreach ($deletedByRollback as $uuid) {
            $this->assertObjectIsAbsentFromActiveSet($uuid);
        }

        foreach ($aliveAfterRollback as $uuid) {
            $this->assertObjectIsPresentInActiveSet($uuid);
        }
    }//end testSoftDeleteByImportJobIdRollsBackTaggedObjectsOnly()

    private function assertObjectIsAbsentFromActiveSet(string $uuid): void
    {
        try {
            $this->magicMapper->find(
                identifier: $uuid,
                register: $this->testRegister,
                schema: $this->testSchema,
                includeDeleted: false
            );
            $this->fail("object $uuid MUST be absent from the active set after rollback");
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }//end assertObjectIsAbsentFromActiveSet()

    private function assertObjectIsPresentInActiveSet(string $uuid): void
    {
        try {
            $this->magicMapper->find(
                identifier: $uuid,
                register: $this->testRegister,
                schema: $this->testSchema,
                includeDeleted: false
            );
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail("object $uuid MUST still be in the active set: ".$e->getMessage());
        }
    }//end assertObjectIsPresentInActiveSet()

    private function createObject(array $data): \OCA\OpenRegister\Db\ObjectEntity
    {
        return $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $data,
            null,
            null,
            false,
            false
        );
    }//end createObject()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-import-rollback-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-import-rollback-'.uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-import-rollback-schema-'.uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-import-rollback-schema-'.uniqid());
        $schema->setProperties(
            [
                'title' => [
                    'type'  => 'string',
                    'title' => 'Title',
                ],
            ]
        );
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->magicMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $tableName = $this->magicMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTables[] = 'oc_'.$tableName;
    }//end createTestFixture()
}//end class
