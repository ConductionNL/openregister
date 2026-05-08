<?php

/**
 * Streaming bulk-upsert primitive integration test.
 *
 * Closes 2c on `reference-existence-validation`. Proves
 * `SaveObject::saveObjectsStreaming()` keeps reference checks O(1)
 * per row by engaging the request-scoped reference-validation cache:
 * with N rows referencing the SAME target UUID, the cache should
 * register exactly 1 miss (the first row) and N-1 hits.
 *
 * Also asserts that the returned `BatchOperationStatus` correctly
 * aggregates per-row outcomes for the caller.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reference-existence-validation/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BatchOperationStatus;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class StreamingBulkUpsertIntegrationTest extends TestCase
{

    private SaveObject $saveHandler;

    private ObjectService $objectService;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;

    private ?Schema $targetSchema = null;

    private ?Schema $referrerSchema = null;

    /**
     * @var string[]
     */
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
        $this->saveHandler->clearReferenceValidationCache();
    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ([$this->referrerSchema, $this->targetSchema] as $schema) {
            if ($schema === null) {
                continue;
            }

            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where(
                        $qb->expr()->eq(
                            'id',
                            $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)
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

        parent::tearDown();
    }//end tearDown()

    public function testStreamingPrimitiveAggregatesPerRowOutcomes(): void
    {
        $rows = [
            ['title' => 'Stream A'],
            ['title' => 'Stream B'],
            ['title' => 'Stream C'],
        ];

        $status = $this->saveHandler->saveObjectsStreaming(
            register: $this->testRegister,
            schema: $this->targetSchema,
            rows: $rows
        );

        $this->assertInstanceOf(BatchOperationStatus::class, $status);
        $this->assertSame(3, $status->getProcessedCount());
        $this->assertSame(3, $status->getCreatedCount(), 'all 3 rows MUST be reported as created');
        $this->assertSame(0, $status->getFailedCount());
        $this->assertNotNull($status->getDurationSeconds(), 'duration MUST be captured');
        $this->assertGreaterThan(0.0, $status->getDurationSeconds());
    }//end testStreamingPrimitiveAggregatesPerRowOutcomes()

    public function testReferenceCacheKeepsBatchedReferenceChecksOOne(): void
    {
        // Persist a single target. The streaming batch then writes
        // many referrer rows pointing AT that target. The first
        // row's reference check resolves the target via DB lookup
        // (cache miss, primes the cache); every subsequent row
        // referencing the same target reuses the cached verdict
        // (cache hit).
        $target = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->targetSchema,
            ['title' => 'Single target'],
            null,
            null,
            false,
            false
        );

        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [
                'title'      => 'Referrer #'.$i,
                'targetUuid' => $target->getUuid(),
            ];
        }

        $this->saveHandler->clearReferenceValidationCache();
        $status = $this->saveHandler->saveObjectsStreaming(
            register: $this->testRegister,
            schema: $this->referrerSchema,
            rows: $rows
        );

        $this->assertSame(10, $status->getProcessedCount());
        $this->assertSame(10, $status->getCreatedCount());
        $this->assertSame(
            1,
            $status->getReferenceCacheMisses(),
            '10 rows referencing the same target MUST cause exactly 1 cache miss'
        );
        $this->assertSame(
            9,
            $status->getReferenceCacheHits(),
            'and 9 cache hits (rows 2–10 reuse the cached verdict)'
        );
    }//end testReferenceCacheKeepsBatchedReferenceChecksOOne()

    public function testFailedRowsDoNotAbortTheBatch(): void
    {
        // Mix valid rows with rows that reference a non-existent
        // target UUID. The valid rows MUST be persisted; the broken
        // rows MUST be reported on the failed list.
        $target = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->targetSchema,
            ['title' => 'Valid target'],
            null,
            null,
            false,
            false
        );

        $rows = [
            ['title' => 'Good 1', 'targetUuid' => $target->getUuid()],
            ['title' => 'Bad 1',  'targetUuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            ['title' => 'Good 2', 'targetUuid' => $target->getUuid()],
            ['title' => 'Bad 2',  'targetUuid' => 'ffffffff-1111-2222-3333-444444444444'],
        ];

        $this->saveHandler->clearReferenceValidationCache();
        $status = $this->saveHandler->saveObjectsStreaming(
            register: $this->testRegister,
            schema: $this->referrerSchema,
            rows: $rows
        );

        $this->assertSame(4, $status->getProcessedCount());
        $this->assertSame(2, $status->getCreatedCount(), 'good rows MUST go through');
        $this->assertSame(2, $status->getFailedCount(), 'bad rows MUST be captured on the failed list');
    }//end testFailedRowsDoNotAbortTheBatch()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-streaming-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-streaming-'.uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $target = new Schema();
        $target->setTitle('phpunit-streaming-target-'.uniqid());
        $target->setUuid(Uuid::v4()->toRfc4122());
        $target->setSlug('phpunit-streaming-target-'.uniqid());
        $target->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        $this->targetSchema = $this->schemaMapper->insert($target);

        $referrer = new Schema();
        $referrer->setTitle('phpunit-streaming-referrer-'.uniqid());
        $referrer->setUuid(Uuid::v4()->toRfc4122());
        $referrer->setSlug('phpunit-streaming-referrer-'.uniqid());
        $referrer->setProperties(
            [
                'title'      => ['type' => 'string', 'title' => 'Title'],
                'targetUuid' => [
                    'type'              => 'string',
                    'title'             => 'Target reference',
                    '$ref'              => '#/components/schemas/'.$target->getSlug(),
                    'validateReference' => true,
                ],
            ]
        );
        $this->referrerSchema = $this->schemaMapper->insert($referrer);

        $this->testRegister->setSchemas([$this->targetSchema->getId(), $this->referrerSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $register = $this->testRegister;
        $this->objectMapper->ensureTableForRegisterSchema($register, $this->targetSchema);
        $this->objectMapper->ensureTableForRegisterSchema($register, $this->referrerSchema);
        $targetTable           = $this->objectMapper->getTableNameForRegisterSchema($register, $this->targetSchema);
        $referrerTable         = $this->objectMapper->getTableNameForRegisterSchema($register, $this->referrerSchema);
        $this->createdTables[] = 'oc_'.$targetTable;
        $this->createdTables[] = 'oc_'.$referrerTable;
    }//end createTestFixture()
}//end class
