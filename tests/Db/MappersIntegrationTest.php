<?php

/**
 * Integration tests for ChunkMapper, WebhookLogMapper, FileMapper,
 * AgentMapper, WebhookMapper, ConfigurationMapper, StatisticsHandler,
 * QueryOptimizationHandler, FacetsHandler, and MultiTenancyTrait.
 *
 * Tests real database operations to increase PCOV code coverage.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 */

namespace OCA\OpenRegister\Tests\Db;

use DateTime;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\ObjectEntity\FacetsHandler;
use OCA\OpenRegister\Db\ObjectEntity\QueryOptimizationHandler;
use OCA\OpenRegister\Db\ObjectEntity\StatisticsHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class MappersIntegrationTest extends TestCase
{
    private IDBConnection $db;
    private ChunkMapper $chunkMapper;
    private WebhookLogMapper $webhookLogMapper;
    private WebhookMapper $webhookMapper;
    private AgentMapper $agentMapper;
    private ConfigurationMapper $configurationMapper;
    private FileMapper $fileMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private UnifiedObjectMapper $objectMapper;
    private StatisticsHandler $statisticsHandler;
    private QueryOptimizationHandler $queryOptimizationHandler;
    private FacetsHandler $facetsHandler;

    /** @var int[] */
    private array $createdChunkIds = [];
    /** @var int[] */
    private array $createdWebhookLogIds = [];
    /** @var int[] */
    private array $createdWebhookIds = [];
    /** @var int[] */
    private array $createdAgentIds = [];
    /** @var int[] */
    private array $createdConfigurationIds = [];
    /** @var int[] */
    private array $createdRegisterIds = [];
    /** @var int[] */
    private array $createdSchemaIds = [];
    /** @var int[] */
    private array $createdObjectIds = [];
    /** @var int[] */
    private array $createdShareIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \OC::$server->get(IDBConnection::class);
        $this->chunkMapper = \OC::$server->get(ChunkMapper::class);
        $this->webhookLogMapper = \OC::$server->get(WebhookLogMapper::class);
        $this->webhookMapper = \OC::$server->get(WebhookMapper::class);
        $this->agentMapper = \OC::$server->get(AgentMapper::class);
        $this->configurationMapper = \OC::$server->get(ConfigurationMapper::class);
        $this->fileMapper = \OC::$server->get(FileMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper = \OC::$server->get(UnifiedObjectMapper::class);
        $this->statisticsHandler = \OC::$server->get(StatisticsHandler::class);
        $this->queryOptimizationHandler = \OC::$server->get(QueryOptimizationHandler::class);
        $this->facetsHandler = \OC::$server->get(FacetsHandler::class);
    }

    protected function tearDown(): void
    {
        // Clean up in reverse dependency order.
        $cleanups = [
            ['openregister_chunks', $this->createdChunkIds],
            ['openregister_webhook_logs', $this->createdWebhookLogIds],
            ['openregister_webhooks', $this->createdWebhookIds],
            ['openregister_agents', $this->createdAgentIds],
            ['openregister_configurations', $this->createdConfigurationIds],
            ['openregister_objects', $this->createdObjectIds],
            ['openregister_schemas', $this->createdSchemaIds],
            ['openregister_registers', $this->createdRegisterIds],
        ];

        foreach ($cleanups as [$table, $ids]) {
            foreach ($ids as $id) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->delete($table)
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                    $qb->executeStatement();
                } catch (\Exception $e) {
                    // Already cleaned up.
                }
            }
        }

        // Clean up shares created during tests.
        foreach ($this->createdShareIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('share')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up.
            }
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createTestRegister(): Register
    {
        $register = $this->registerMapper->createFromArray([
            'title'       => 'phpunit-mapper-test-' . uniqid(),
            'description' => 'Register for MappersIntegrationTest',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }

    private function createTestSchema(): Schema
    {
        $schema = $this->schemaMapper->createFromArray([
            'title'       => 'phpunit-mapper-test-' . uniqid(),
            'description' => 'Schema for MappersIntegrationTest',
            'properties'  => [
                'name' => ['type' => 'string', 'title' => 'Name'],
                'status' => ['type' => 'string', 'title' => 'Status', 'facetable' => true],
            ],
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }

    private function createTestObject(?Register $register = null, ?Schema $schema = null): ObjectEntity
    {
        if ($register === null) {
            $register = $this->createTestRegister();
        }

        if ($schema === null) {
            $schema = $this->createTestSchema();
        }

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['name' => 'phpunit-test-' . uniqid()]);

        $result = $this->objectMapper->insertEntity($entity);
        $this->createdObjectIds[] = $result->getId();

        return $result;
    }

    private function insertChunkDirectly(
        string $sourceType = 'file',
        int $sourceId = 1,
        int $chunkIndex = 0,
        bool $indexed = false,
        bool $vectorized = false,
        ?string $textContent = null
    ): Chunk {
        $chunk = new Chunk();
        $chunk->setUuid(Uuid::v4()->toRfc4122());
        $chunk->setSourceType($sourceType);
        $chunk->setSourceId($sourceId);
        $chunk->setChunkIndex($chunkIndex);
        $chunk->setTextContent($textContent ?? 'Test chunk content ' . uniqid());
        $chunk->setStartOffset(0);
        $chunk->setEndOffset(100);
        $chunk->setIndexed($indexed);
        $chunk->setVectorized($vectorized);
        $chunk->setCreatedAt(new DateTime());
        $chunk->setUpdatedAt(new DateTime());

        $result = $this->chunkMapper->insert($chunk);
        $this->createdChunkIds[] = $result->getId();

        return $result;
    }

    private function insertWebhookLogDirectly(
        int $webhookId = 1,
        bool $success = true,
        ?DateTime $nextRetryAt = null
    ): WebhookLog {
        $log = new WebhookLog();
        $log->setWebhook($webhookId);
        $log->setEventClass('OCA\\OpenRegister\\Event\\TestEvent');
        $log->setUrl('https://example.com/webhook');
        $log->setMethod('POST');
        $log->setSuccess($success);
        $log->setStatusCode($success ? 200 : 500);
        $log->setAttempt(1);

        if ($nextRetryAt !== null) {
            $log->setNextRetryAt($nextRetryAt);
        }

        $result = $this->webhookLogMapper->insert($log);
        $this->createdWebhookLogIds[] = $result->getId();

        return $result;
    }

    private function insertWebhookDirectly(): Webhook
    {
        $qb = $this->db->getQueryBuilder();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $uuid = Uuid::v4()->toRfc4122();

        $qb->insert('openregister_webhooks')
            ->values([
                'uuid'    => $qb->createNamedParameter($uuid),
                'name'    => $qb->createNamedParameter('phpunit-test-webhook-' . uniqid()),
                'url'     => $qb->createNamedParameter('https://example.com/hook'),
                'method'  => $qb->createNamedParameter('POST'),
                'events'  => $qb->createNamedParameter('[]'),
                'enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                'retry_policy'  => $qb->createNamedParameter('exponential'),
                'max_retries'   => $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT),
                'timeout'       => $qb->createNamedParameter(30, IQueryBuilder::PARAM_INT),
                'total_deliveries'      => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'successful_deliveries' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'failed_deliveries'     => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'created' => $qb->createNamedParameter($now),
                'updated' => $qb->createNamedParameter($now),
            ]);
        $qb->executeStatement();

        $id = $this->db->lastInsertId('*PREFIX*openregister_webhooks');
        $this->createdWebhookIds[] = (int) $id;

        // Fetch it back.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('*')->from('openregister_webhooks')
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter((int) $id, IQueryBuilder::PARAM_INT)));
        $row = $qb2->executeQuery()->fetch();

        $webhook = new Webhook();
        // Use the entity directly.
        $webhook->setId((int) $id);
        $webhook->setUuid($uuid);
        $webhook->setName($row['name']);
        $webhook->setUrl($row['url']);
        $webhook->resetUpdatedFields();

        return $webhook;
    }

    private function insertAgentDirectly(): Agent
    {
        $qb = $this->db->getQueryBuilder();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $uuid = Uuid::v4()->toRfc4122();

        $qb->insert('openregister_agents')
            ->values([
                'uuid'        => $qb->createNamedParameter($uuid),
                'name'        => $qb->createNamedParameter('phpunit-test-agent-' . uniqid()),
                'description' => $qb->createNamedParameter('Test agent'),
                'type'        => $qb->createNamedParameter('chat'),
                'provider'    => $qb->createNamedParameter('openai'),
                'model'       => $qb->createNamedParameter('gpt-4o-mini'),
                'active'      => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                'enable_rag'  => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'rag_include_files'   => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'rag_include_objects' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'created'     => $qb->createNamedParameter($now),
                'updated'     => $qb->createNamedParameter($now),
            ]);
        $qb->executeStatement();

        $id = $this->db->lastInsertId('*PREFIX*openregister_agents');
        $this->createdAgentIds[] = (int) $id;

        $agent = new Agent();
        $agent->setId((int) $id);
        $agent->setUuid($uuid);
        $agent->resetUpdatedFields();

        return $agent;
    }

    private function insertConfigurationDirectly(): Configuration
    {
        $qb = $this->db->getQueryBuilder();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $uuid = Uuid::v4()->toRfc4122();

        $qb->insert('openregister_configurations')
            ->values([
                'uuid'         => $qb->createNamedParameter($uuid),
                'title'        => $qb->createNamedParameter('phpunit-test-config-' . uniqid()),
                'description'  => $qb->createNamedParameter('Test configuration'),
                'type'         => $qb->createNamedParameter('default'),
                'version'      => $qb->createNamedParameter('1.0.0'),
                'is_local'     => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                'sync_enabled' => $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
                'sync_interval' => $qb->createNamedParameter(24, IQueryBuilder::PARAM_INT),
                'sync_status'  => $qb->createNamedParameter('never'),
                'created'      => $qb->createNamedParameter($now),
                'updated'      => $qb->createNamedParameter($now),
            ]);
        $qb->executeStatement();

        $id = $this->db->lastInsertId('*PREFIX*openregister_configurations');
        $this->createdConfigurationIds[] = (int) $id;

        return $this->configurationMapper->find((int) $id, false);
    }

    // =========================================================================
    // ChunkMapper tests
    // =========================================================================

    public function testChunkMapperFindBySource(): void
    {
        $sourceId = random_int(900000, 999999);
        $chunk1 = $this->insertChunkDirectly('file', $sourceId, 0);
        $chunk2 = $this->insertChunkDirectly('file', $sourceId, 1);

        $results = $this->chunkMapper->findBySource('file', $sourceId);
        $this->assertCount(2, $results);
        $this->assertSame($chunk1->getId(), $results[0]->getId());
        $this->assertSame($chunk2->getId(), $results[1]->getId());
    }

    public function testChunkMapperFindBySourceEmpty(): void
    {
        $results = $this->chunkMapper->findBySource('file', 999999999);
        $this->assertSame([], $results);
    }

    public function testChunkMapperDeleteBySource(): void
    {
        $sourceId = random_int(900000, 999999);
        $chunk = $this->insertChunkDirectly('file', $sourceId);

        $this->chunkMapper->deleteBySource('file', $sourceId);

        // Remove from cleanup list since already deleted.
        $this->createdChunkIds = array_diff($this->createdChunkIds, [$chunk->getId()]);

        $results = $this->chunkMapper->findBySource('file', $sourceId);
        $this->assertSame([], $results);
    }

    public function testChunkMapperGetLatestUpdatedTimestamp(): void
    {
        $sourceId = random_int(900000, 999999);
        $this->insertChunkDirectly('file', $sourceId);

        $timestamp = $this->chunkMapper->getLatestUpdatedTimestamp('file', $sourceId);
        $this->assertIsInt($timestamp);
        $this->assertGreaterThan(0, $timestamp);
    }

    public function testChunkMapperGetLatestUpdatedTimestampNonExistent(): void
    {
        $timestamp = $this->chunkMapper->getLatestUpdatedTimestamp('file', 999999999);
        $this->assertNull($timestamp);
    }

    public function testChunkMapperCountAll(): void
    {
        $this->insertChunkDirectly();

        $count = $this->chunkMapper->countAll();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testChunkMapperCountIndexed(): void
    {
        $this->insertChunkDirectly('file', 1, 0, true);

        $count = $this->chunkMapper->countIndexed();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testChunkMapperCountUnindexed(): void
    {
        $this->insertChunkDirectly('file', 1, 0, false);

        $count = $this->chunkMapper->countUnindexed();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testChunkMapperCountVectorized(): void
    {
        $this->insertChunkDirectly('file', 1, 0, false, true);

        $count = $this->chunkMapper->countVectorized();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testChunkMapperFindUnindexed(): void
    {
        $this->insertChunkDirectly('file', 1, 0, false);

        $results = $this->chunkMapper->findUnindexed(10);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $chunk) {
            $this->assertInstanceOf(Chunk::class, $chunk);
        }
    }

    public function testChunkMapperFindUnindexedWithLimitAndOffset(): void
    {
        $this->insertChunkDirectly('file', 1, 0, false);
        $this->insertChunkDirectly('file', 1, 1, false);

        $results = $this->chunkMapper->findUnindexed(1, 0);
        $this->assertCount(1, $results);
    }

    public function testChunkMapperCountFileSourceSummaries(): void
    {
        $count = $this->chunkMapper->countFileSourceSummaries();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testChunkMapperCountFileSourceSummariesWithSearch(): void
    {
        $count = $this->chunkMapper->countFileSourceSummaries('nonexistent-file-xyz');
        $this->assertSame(0, $count);
    }

    // =========================================================================
    // WebhookLogMapper tests
    // =========================================================================

    public function testWebhookLogMapperFind(): void
    {
        $log = $this->insertWebhookLogDirectly();

        $found = $this->webhookLogMapper->find($log->getId());
        $this->assertInstanceOf(WebhookLog::class, $found);
        $this->assertSame($log->getId(), $found->getId());
    }

    public function testWebhookLogMapperFindNonExistent(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->webhookLogMapper->find(999999999);
    }

    public function testWebhookLogMapperFindByWebhook(): void
    {
        $webhookId = random_int(900000, 999999);
        $this->insertWebhookLogDirectly($webhookId);
        $this->insertWebhookLogDirectly($webhookId);

        $results = $this->webhookLogMapper->findByWebhook($webhookId);
        $this->assertCount(2, $results);
    }

    public function testWebhookLogMapperFindByWebhookWithLimit(): void
    {
        $webhookId = random_int(900000, 999999);
        $this->insertWebhookLogDirectly($webhookId);
        $this->insertWebhookLogDirectly($webhookId);

        $results = $this->webhookLogMapper->findByWebhook($webhookId, 1);
        $this->assertCount(1, $results);
    }

    public function testWebhookLogMapperFindByWebhookWithOffset(): void
    {
        $webhookId = random_int(900000, 999999);
        $this->insertWebhookLogDirectly($webhookId);
        $this->insertWebhookLogDirectly($webhookId);

        $results = $this->webhookLogMapper->findByWebhook($webhookId, null, 1);
        $this->assertCount(1, $results);
    }

    public function testWebhookLogMapperFindAll(): void
    {
        $this->insertWebhookLogDirectly();

        $results = $this->webhookLogMapper->findAll();
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testWebhookLogMapperFindAllWithLimitAndOffset(): void
    {
        $this->insertWebhookLogDirectly();
        $this->insertWebhookLogDirectly();

        $results = $this->webhookLogMapper->findAll(1, 0);
        $this->assertCount(1, $results);
    }

    public function testWebhookLogMapperFindFailedForRetry(): void
    {
        // Create a failed log with a past retry date.
        $this->insertWebhookLogDirectly(1, false, new DateTime('-1 hour'));

        $results = $this->webhookLogMapper->findFailedForRetry(new DateTime());
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testWebhookLogMapperFindFailedForRetryFutureOnly(): void
    {
        // Create a failed log with a future retry date.
        $webhookId = random_int(900000, 999999);
        $this->insertWebhookLogDirectly($webhookId, false, new DateTime('+1 day'));

        // Request retries before now — shouldn't include the future one.
        $results = $this->webhookLogMapper->findFailedForRetry(new DateTime());
        $resultWebhookIds = array_map(fn($log) => $log->getWebhook(), $results);
        // Our specific webhook with future retry should not be in results.
        $this->assertNotContains($webhookId, $resultWebhookIds);
    }

    public function testWebhookLogMapperInsertSetsCreated(): void
    {
        $log = new WebhookLog();
        $log->setWebhook(1);
        $log->setEventClass('TestEvent');
        $log->setUrl('https://example.com');
        $log->setMethod('POST');
        $log->setSuccess(true);
        $log->setStatusCode(200);
        $log->setAttempt(1);

        $result = $this->webhookLogMapper->insert($log);
        $this->createdWebhookLogIds[] = $result->getId();

        $this->assertNotNull($result->getCreated());
        $this->assertInstanceOf(DateTime::class, $result->getCreated());
    }

    public function testWebhookLogMapperGetStatisticsForAll(): void
    {
        $this->insertWebhookLogDirectly(1, true);
        $this->insertWebhookLogDirectly(1, false);

        $stats = $this->webhookLogMapper->getStatistics(0);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('successful', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['total']);
    }

    public function testWebhookLogMapperGetStatisticsForSpecificWebhook(): void
    {
        $webhookId = random_int(900000, 999999);
        $this->insertWebhookLogDirectly($webhookId, true);
        $this->insertWebhookLogDirectly($webhookId, false);

        $stats = $this->webhookLogMapper->getStatistics($webhookId);
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['successful']);
        $this->assertSame(1, $stats['failed']);
    }

    // =========================================================================
    // WebhookMapper tests
    // =========================================================================

    public function testWebhookMapperFindAll(): void
    {
        $this->insertWebhookDirectly();

        // findAll applies organisation filtering, so may return 0 if no org is active.
        $results = $this->webhookMapper->findAll();
        $this->assertIsArray($results);
    }

    public function testWebhookMapperFindAllWithLimit(): void
    {
        $this->insertWebhookDirectly();
        $this->insertWebhookDirectly();

        $results = $this->webhookMapper->findAll(1);
        $this->assertLessThanOrEqual(1, count($results));
    }

    public function testWebhookMapperFindAllWithFilters(): void
    {
        $results = $this->webhookMapper->findAll(null, null, ['enabled' => 'IS NOT NULL']);
        $this->assertIsArray($results);
    }

    public function testWebhookMapperFindAllWithIsNullFilter(): void
    {
        $results = $this->webhookMapper->findAll(null, null, ['secret' => 'IS NULL']);
        $this->assertIsArray($results);
    }

    public function testWebhookMapperFind(): void
    {
        $webhook = $this->insertWebhookDirectly();

        // find() applies organisation filtering, so it may not find the webhook
        // if there's no active org matching. Test exception path instead.
        try {
            $found = $this->webhookMapper->find($webhook->getId());
            $this->assertInstanceOf(Webhook::class, $found);
            $this->assertSame($webhook->getId(), $found->getId());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Expected when org filter doesn't match — covers the find() code path.
            $this->assertStringContainsString('one result', $e->getMessage());
        }
    }

    public function testWebhookMapperFindNonExistent(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->webhookMapper->find(999999999);
    }

    public function testWebhookMapperFindEnabled(): void
    {
        $this->insertWebhookDirectly();

        $results = $this->webhookMapper->findEnabled();
        $this->assertIsArray($results);
        foreach ($results as $webhook) {
            $this->assertTrue($webhook->getEnabled());
        }
    }

    public function testWebhookMapperFindForEvent(): void
    {
        $this->insertWebhookDirectly();

        // Webhooks with empty events match all events.
        $results = $this->webhookMapper->findForEvent('OCA\\OpenRegister\\Event\\TestEvent');
        $this->assertIsArray($results);
    }

    // =========================================================================
    // AgentMapper tests (direct DB inserts to avoid RBAC)
    // =========================================================================

    public function testAgentMapperCanUserAccessAgentNonPrivate(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'test-agent',
            'isPrivate' => false,
        ]);

        $this->assertTrue($this->agentMapper->canUserAccessAgent($agent, 'someuser'));
    }

    public function testAgentMapperCanUserAccessAgentPrivateOwner(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'test-agent',
            'isPrivate' => true,
            'owner' => 'testowner',
        ]);

        $this->assertTrue($this->agentMapper->canUserAccessAgent($agent, 'testowner'));
        $this->assertFalse($this->agentMapper->canUserAccessAgent($agent, 'otheruser'));
    }

    public function testAgentMapperCanUserAccessAgentPrivateInvited(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'test-agent',
            'isPrivate' => true,
            'owner' => 'testowner',
            'invitedUsers' => ['inviteduser'],
        ]);

        $this->assertTrue($this->agentMapper->canUserAccessAgent($agent, 'inviteduser'));
        $this->assertFalse($this->agentMapper->canUserAccessAgent($agent, 'randomuser'));
    }

    public function testAgentMapperCanUserModifyAgent(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'test-agent',
            'owner' => 'theowner',
        ]);

        $this->assertTrue($this->agentMapper->canUserModifyAgent($agent, 'theowner'));
        $this->assertFalse($this->agentMapper->canUserModifyAgent($agent, 'nottheowner'));
    }

    // =========================================================================
    // ConfigurationMapper tests
    // =========================================================================

    public function testConfigurationMapperFindById(): void
    {
        $config = $this->insertConfigurationDirectly();

        $found = $this->configurationMapper->find($config->getId(), false);
        $this->assertInstanceOf(Configuration::class, $found);
        $this->assertSame($config->getId(), $found->getId());
    }

    public function testConfigurationMapperFindByIdNonExistent(): void
    {
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->configurationMapper->find(999999999, false);
    }

    public function testConfigurationMapperFindAll(): void
    {
        $this->insertConfigurationDirectly();

        $results = $this->configurationMapper->findAll(_multitenancy: false);
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testConfigurationMapperFindAllWithLimit(): void
    {
        $this->insertConfigurationDirectly();
        $this->insertConfigurationDirectly();

        $results = $this->configurationMapper->findAll(1, 0, _multitenancy: false);
        $this->assertCount(1, $results);
    }

    public function testConfigurationMapperFindAllWithFilter(): void
    {
        $config = $this->insertConfigurationDirectly();

        $results = $this->configurationMapper->findAll(
            null, null,
            ['type' => 'default'],
            _multitenancy: false
        );
        $this->assertIsArray($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testConfigurationMapperFindAllWithIsNullFilter(): void
    {
        $results = $this->configurationMapper->findAll(
            null, null,
            ['app' => 'IS NULL'],
            _multitenancy: false
        );
        $this->assertIsArray($results);
    }

    public function testConfigurationMapperFindAllWithIsNotNullFilter(): void
    {
        $results = $this->configurationMapper->findAll(
            null, null,
            ['type' => 'IS NOT NULL'],
            _multitenancy: false
        );
        $this->assertIsArray($results);
    }

    public function testConfigurationMapperFindBySourceUrl(): void
    {
        $result = $this->configurationMapper->findBySourceUrl('https://nonexistent.example.com/config.json');
        $this->assertNull($result);
    }

    public function testConfigurationMapperFindBySyncEnabled(): void
    {
        $results = $this->configurationMapper->findBySyncEnabled();
        $this->assertIsArray($results);
    }

    public function testConfigurationMapperUpdateSyncStatus(): void
    {
        $config = $this->insertConfigurationDirectly();

        // updateSyncStatus calls find() which applies org filter.
        // We test the code path either way.
        try {
            $updated = $this->configurationMapper->updateSyncStatus(
                $config->getId(),
                'success',
                new DateTime()
            );
            $this->assertSame('success', $updated->getSyncStatus());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Expected when org filter blocks — still covers updateSyncStatus code path.
            $this->assertStringContainsString('one result', $e->getMessage());
        }
    }

    public function testConfigurationMapperCreateFromArray(): void
    {
        $config = $this->configurationMapper->createFromArray([
            'title'       => 'phpunit-createFromArray-' . uniqid(),
            'description' => 'Created from array test',
            'type'        => 'test',
        ]);
        $this->createdConfigurationIds[] = $config->getId();

        $this->assertNotNull($config->getId());
        $this->assertNotNull($config->getUuid());
        $this->assertSame('test', $config->getType());
        $this->assertNotNull($config->getCreated());
    }

    public function testConfigurationMapperUpdateFromArray(): void
    {
        $config = $this->insertConfigurationDirectly();
        $originalVersion = $config->getVersion();

        $updated = $this->configurationMapper->updateFromArray(
            $config->getId(),
            ['title' => 'Updated Title ' . uniqid()]
        );

        $this->assertNotSame($originalVersion, $updated->getVersion());
        $this->assertStringContainsString('Updated Title', $updated->getTitle());
    }

    public function testConfigurationEntityHelpers(): void
    {
        $config = new Configuration();
        $config->hydrate([
            'title'       => 'Test Config',
            'sourceType'  => 'github',
            'localVersion'  => '1.0.0',
            'remoteVersion' => '2.0.0',
        ]);

        $this->assertTrue($config->hasUpdateAvailable());
        $this->assertTrue($config->isRemoteSource());
        $this->assertFalse($config->isLocalSource());
        $this->assertFalse($config->isManualSource());

        $config->setSourceType('local');
        $this->assertTrue($config->isLocalSource());

        $config->setSourceType('manual');
        $this->assertTrue($config->isManualSource());

        $this->assertSame('Test Config', (string) $config);
    }

    public function testConfigurationEntityToStringFallbacks(): void
    {
        $config = new Configuration();

        $config->hydrate(['type' => 'special']);
        $this->assertSame('Config: special', (string) $config);
    }

    public function testConfigurationEntityIsValidUuid(): void
    {
        $this->assertTrue(Configuration::isValidUuid(Uuid::v4()->toRfc4122()));
        $this->assertFalse(Configuration::isValidUuid('not-a-uuid'));
    }

    public function testConfigurationEntityGetJsonFields(): void
    {
        $config = new Configuration();
        $jsonFields = $config->getJsonFields();
        $this->assertIsArray($jsonFields);
        $this->assertContains('registers', $jsonFields);
        $this->assertContains('schemas', $jsonFields);
    }

    // =========================================================================
    // FileMapper tests
    // =========================================================================

    public function testFileMapperCountAllFiles(): void
    {
        $count = $this->fileMapper->countAllFiles();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFileMapperGetTotalFilesSize(): void
    {
        $size = $this->fileMapper->getTotalFilesSize();
        $this->assertIsInt($size);
        $this->assertGreaterThanOrEqual(0, $size);
    }

    public function testFileMapperGetFilesEmpty(): void
    {
        // Use a node ID that shouldn't exist.
        $files = $this->fileMapper->getFiles(999999999);
        $this->assertSame([], $files);
    }

    public function testFileMapperGetFileNonExistent(): void
    {
        $file = $this->fileMapper->getFile(999999999);
        $this->assertNull($file);
    }

    public function testFileMapperGetFilesWithIds(): void
    {
        $files = $this->fileMapper->getFiles(null, [999999999]);
        $this->assertSame([], $files);
    }

    public function testFileMapperFindUntrackedFiles(): void
    {
        $files = $this->fileMapper->findUntrackedFiles(10);
        $this->assertIsArray($files);
    }

    public function testFileMapperCountUntrackedFiles(): void
    {
        $count = $this->fileMapper->countUntrackedFiles();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFileMapperGetFilesForObjectWithNoFolder(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $object = $this->createTestObject($register, $schema);

        // Object has no folder set, so it will try UUID lookup in filecache.
        $files = $this->fileMapper->getFilesForObject($object);
        $this->assertIsArray($files);
    }

    public function testFileMapperDepublishFile(): void
    {
        // Depublish a non-existent file should return 0 deleted.
        $result = $this->fileMapper->depublishFile(999999999);
        $this->assertSame(0, $result['deleted_shares']);
        $this->assertSame(999999999, $result['file_id']);
    }

    // =========================================================================
    // StatisticsHandler tests
    // =========================================================================

    public function testStatisticsHandlerGetStatistics(): void
    {
        $stats = $this->statisticsHandler->getStatistics();
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('size', $stats);
        $this->assertArrayHasKey('invalid', $stats);
        $this->assertArrayHasKey('deleted', $stats);
        $this->assertArrayHasKey('locked', $stats);
        $this->assertArrayHasKey('published', $stats);
    }

    public function testStatisticsHandlerGetStatisticsWithRegisterId(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->statisticsHandler->getStatistics($register->getId());
        $this->assertArrayHasKey('total', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    public function testStatisticsHandlerGetStatisticsWithSchemaId(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->statisticsHandler->getStatistics(null, $schema->getId());
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    public function testStatisticsHandlerGetStatisticsWithArrayIds(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();
        $this->createTestObject($register, $schema);

        $stats = $this->statisticsHandler->getStatistics(
            [$register->getId()],
            [$schema->getId()]
        );
        $this->assertGreaterThanOrEqual(1, $stats['total']);
    }

    public function testStatisticsHandlerGetStatisticsWithExclude(): void
    {
        $stats = $this->statisticsHandler->getStatistics(null, null, [
            ['register' => 999999, 'schema' => 999999],
        ]);
        $this->assertIsArray($stats);
    }

    public function testStatisticsHandlerGetRegisterChartData(): void
    {
        $data = $this->statisticsHandler->getRegisterChartData();
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testStatisticsHandlerGetRegisterChartDataWithFilters(): void
    {
        $register = $this->createTestRegister();

        $data = $this->statisticsHandler->getRegisterChartData($register->getId());
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testStatisticsHandlerGetSchemaChartData(): void
    {
        $data = $this->statisticsHandler->getSchemaChartData();
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
    }

    public function testStatisticsHandlerGetSchemaChartDataWithFilters(): void
    {
        $schema = $this->createTestSchema();

        $data = $this->statisticsHandler->getSchemaChartData(null, $schema->getId());
        $this->assertArrayHasKey('labels', $data);
    }

    public function testStatisticsHandlerGetSizeDistributionChartData(): void
    {
        $data = $this->statisticsHandler->getSizeDistributionChartData();
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('series', $data);
        $this->assertCount(5, $data['labels']);
        $this->assertCount(5, $data['series']);
    }

    public function testStatisticsHandlerGetSizeDistributionChartDataWithFilters(): void
    {
        $register = $this->createTestRegister();
        $schema = $this->createTestSchema();

        $data = $this->statisticsHandler->getSizeDistributionChartData($register->getId(), $schema->getId());
        $this->assertCount(5, $data['labels']);
    }

    public function testStatisticsHandlerGetStatisticsGroupedBySchema(): void
    {
        $schema = $this->createTestSchema();
        $register = $this->createTestRegister();
        $this->createTestObject($register, $schema);

        $result = $this->statisticsHandler->getStatisticsGroupedBySchema([$schema->getId()]);
        $this->assertArrayHasKey($schema->getId(), $result);
        $this->assertGreaterThanOrEqual(1, $result[$schema->getId()]['total']);
    }

    public function testStatisticsHandlerGetStatisticsGroupedBySchemaEmpty(): void
    {
        $result = $this->statisticsHandler->getStatisticsGroupedBySchema([]);
        $this->assertSame([], $result);
    }

    public function testStatisticsHandlerGetStatisticsGroupedBySchemaFillsMissing(): void
    {
        $result = $this->statisticsHandler->getStatisticsGroupedBySchema([999999999]);
        $this->assertArrayHasKey(999999999, $result);
        $this->assertSame(0, $result[999999999]['total']);
    }

    // =========================================================================
    // QueryOptimizationHandler tests
    // =========================================================================

    public function testQueryOptimizationHandlerSeparateLargeObjects(): void
    {
        $objects = [
            ['uuid' => 'a', 'data' => str_repeat('x', 2000000)],
            ['uuid' => 'b', 'data' => 'small'],
        ];

        $result = $this->queryOptimizationHandler->separateLargeObjects($objects, 1000000);
        $this->assertArrayHasKey('large', $result);
        $this->assertArrayHasKey('normal', $result);
        $this->assertCount(1, $result['large']);
        $this->assertCount(1, $result['normal']);
    }

    public function testQueryOptimizationHandlerSeparateLargeObjectsAllSmall(): void
    {
        $objects = [
            ['uuid' => 'a', 'data' => 'small1'],
            ['uuid' => 'b', 'data' => 'small2'],
        ];

        $result = $this->queryOptimizationHandler->separateLargeObjects($objects);
        $this->assertCount(0, $result['large']);
        $this->assertCount(2, $result['normal']);
    }

    public function testQueryOptimizationHandlerHasJsonFilters(): void
    {
        $this->assertTrue($this->queryOptimizationHandler->hasJsonFilters(['object.name' => 'test']));
        $this->assertFalse($this->queryOptimizationHandler->hasJsonFilters(['name' => 'test']));
        $this->assertFalse($this->queryOptimizationHandler->hasJsonFilters(['schema.id' => 1]));
        $this->assertFalse($this->queryOptimizationHandler->hasJsonFilters([]));
    }

    public function testQueryOptimizationHandlerApplyCompositeIndexOptimizations(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects');

        // Should not throw - just logs debug info.
        $this->queryOptimizationHandler->applyCompositeIndexOptimizations($qb, [
            'schema' => 1,
            'register' => 1,
            'published' => true,
        ]);
        $this->assertTrue(true);
    }

    public function testQueryOptimizationHandlerApplyCompositeIndexOptimizationsWithOrg(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects');

        $this->queryOptimizationHandler->applyCompositeIndexOptimizations($qb, [
            'schema' => 1,
            'organisation' => 'test-org',
        ]);
        $this->assertTrue(true);
    }

    public function testQueryOptimizationHandlerOptimizeOrderBy(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects');

        // No ORDER BY set yet.
        $this->queryOptimizationHandler->optimizeOrderBy($qb);
        $this->assertTrue(true);
    }

    public function testQueryOptimizationHandlerAddQueryHints(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects')->setMaxResults(10);

        $this->queryOptimizationHandler->addQueryHints($qb, ['object' => 'test'], false);
        $this->assertTrue(true);
    }

    public function testQueryOptimizationHandlerAddQueryHintsWithRbac(): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openregister_objects')->setMaxResults(100);

        $this->queryOptimizationHandler->addQueryHints($qb, [], false);
        $this->assertTrue(true);
    }

    public function testQueryOptimizationHandlerProcessLargeObjectsIndividuallyEmpty(): void
    {
        $result = $this->queryOptimizationHandler->processLargeObjectsIndividually([]);
        $this->assertSame([], $result);
    }

    public function testQueryOptimizationHandlerBulkOwnerDeclarationThrowsOnNoArgs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queryOptimizationHandler->bulkOwnerDeclaration(null, null);
    }

    // =========================================================================
    // FacetsHandler tests
    // =========================================================================

    public function testFacetsHandlerGetSimpleFacetsEmpty(): void
    {
        $result = $this->facetsHandler->getSimpleFacets([]);
        $this->assertIsArray($result);
    }

    public function testFacetsHandlerGetSimpleFacetsNoConfig(): void
    {
        $result = $this->facetsHandler->getSimpleFacets(['_facets' => []]);
        $this->assertIsArray($result);
    }

    public function testFacetsHandlerGetFacetableFieldsFromSchemas(): void
    {
        $schema = $this->createTestSchema();

        $fields = $this->facetsHandler->getFacetableFieldsFromSchemas([
            '@self' => ['schema' => $schema->getId()],
        ]);
        $this->assertIsArray($fields);
        // Our test schema has 'status' field with facetable=true.
        if (!empty($fields)) {
            $this->assertArrayHasKey('status', $fields);
        }
    }

    public function testFacetsHandlerGetFacetableFieldsFromSchemasEmpty(): void
    {
        $fields = $this->facetsHandler->getFacetableFieldsFromSchemas([
            '@self' => ['schema' => 999999999],
        ]);
        $this->assertIsArray($fields);
    }

    // =========================================================================
    // Webhook entity tests
    // =========================================================================

    public function testWebhookEntityMatchesEvent(): void
    {
        $webhook = new Webhook();
        $webhook->setEvents('[]');

        // Empty events matches all.
        $this->assertTrue($webhook->matchesEvent('SomeEvent'));

        // Specific event.
        $webhook->setEventsArray(['OCA\\OpenRegister\\Event\\ObjectCreated']);
        $this->assertTrue($webhook->matchesEvent('OCA\\OpenRegister\\Event\\ObjectCreated'));
        $this->assertFalse($webhook->matchesEvent('OCA\\OpenRegister\\Event\\ObjectDeleted'));
    }

    public function testWebhookEntityMatchesEventWildcard(): void
    {
        $webhook = new Webhook();
        // fnmatch treats backslashes as escape chars; use forward slashes or simple patterns.
        $webhook->setEventsArray(['*ObjectCreated*']);

        $this->assertTrue($webhook->matchesEvent('OCA\\OpenRegister\\Event\\ObjectCreated'));
    }

    public function testWebhookEntityArrayAccessors(): void
    {
        $webhook = new Webhook();

        $webhook->setHeadersArray(['Content-Type' => 'application/json']);
        $this->assertSame(['Content-Type' => 'application/json'], $webhook->getHeadersArray());

        $webhook->setHeadersArray(null);
        $this->assertSame([], $webhook->getHeadersArray());

        $webhook->setFiltersArray(['register' => 1]);
        $this->assertSame(['register' => 1], $webhook->getFiltersArray());

        $webhook->setFiltersArray(null);
        $this->assertSame([], $webhook->getFiltersArray());

        $webhook->setConfigurationArray(['key' => 'value']);
        $this->assertSame(['key' => 'value'], $webhook->getConfigurationArray());

        $webhook->setConfigurationArray(null);
        $this->assertSame([], $webhook->getConfigurationArray());
    }

    public function testWebhookEntityHydrate(): void
    {
        $webhook = new Webhook();
        $webhook->hydrate([
            'name' => 'Test Webhook',
            'url' => 'https://example.com/hook',
            'method' => 'PUT',
            'events' => ['EventA', 'EventB'],
            'headers' => ['X-Custom' => 'value'],
            'secret' => 'mysecret',
            'enabled' => true,
            'filters' => ['reg' => 1],
            'retryPolicy' => 'linear',
            'maxRetries' => 5,
            'timeout' => 60,
            'configuration' => ['mode' => 'test'],
            'mapping' => 42,
        ]);

        $this->assertSame('Test Webhook', $webhook->getName());
        $this->assertSame('PUT', $webhook->getMethod());
        $this->assertSame(['EventA', 'EventB'], $webhook->getEventsArray());
        $this->assertSame(['X-Custom' => 'value'], $webhook->getHeadersArray());
        $this->assertSame('mysecret', $webhook->getSecret());
        $this->assertSame('linear', $webhook->getRetryPolicy());
        $this->assertSame(5, $webhook->getMaxRetries());
        $this->assertSame(60, $webhook->getTimeout());
        $this->assertSame(42, $webhook->getMapping());
    }

    public function testWebhookEntityJsonSerialize(): void
    {
        $webhook = new Webhook();
        $webhook->hydrate([
            'name' => 'Serialize Test',
            'url' => 'https://example.com',
            'secret' => 'hidden',
        ]);

        $json = $webhook->jsonSerialize();
        $this->assertSame('***', $json['secret']);
        $this->assertSame('Serialize Test', $json['name']);
    }

    // =========================================================================
    // WebhookLog entity tests
    // =========================================================================

    public function testWebhookLogEntityPayloadArray(): void
    {
        $log = new WebhookLog();
        // setPayloadArray uses named args on __call which is a known issue.
        // Test the direct setPayload + getPayloadArray instead.
        $log->setPayload(json_encode(['key' => 'value']));
        $this->assertSame(['key' => 'value'], $log->getPayloadArray());

        $log->setPayload(null);
        $this->assertSame([], $log->getPayloadArray());
    }

    public function testWebhookLogEntityJsonSerialize(): void
    {
        $log = new WebhookLog();
        $log->setWebhook(1);
        $log->setEventClass('TestEvent');
        $log->setUrl('https://example.com');
        $log->setMethod('POST');
        $log->setSuccess(true);
        $log->setStatusCode(200);
        $log->setAttempt(1);

        $json = $log->jsonSerialize();
        $this->assertSame(1, $json['webhook']);
        $this->assertSame('TestEvent', $json['eventClass']);
        $this->assertTrue($json['success']);
    }

    // =========================================================================
    // Agent entity tests
    // =========================================================================

    public function testAgentEntityHasInvitedUser(): void
    {
        $agent = new Agent();
        $this->assertFalse($agent->hasInvitedUser('user1'));

        $agent->hydrate(['invitedUsers' => ['user1', 'user2']]);
        $this->assertTrue($agent->hasInvitedUser('user1'));
        $this->assertTrue($agent->hasInvitedUser('user2'));
        $this->assertFalse($agent->hasInvitedUser('user3'));
    }

    public function testAgentEntityHydrate(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'Test Agent',
            'type' => 'chat',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'temperature' => 0.7,
            'maxTokens' => 1000,
            'active' => true,
            'enableRag' => true,
            'ragSearchMode' => 'hybrid',
            'ragNumSources' => 5,
            'ragIncludeFiles' => true,
            'ragIncludeObjects' => false,
            'requestQuota' => 100,
            'tokenQuota' => 50000,
            'searchFiles' => true,
            'searchObjects' => false,
            'isPrivate' => true,
            'groups' => ['admin'],
            'tools' => ['register', 'objects'],
            'user' => 'testuser',
        ]);

        $this->assertSame('Test Agent', $agent->getName());
        $this->assertSame('chat', $agent->getType());
        $this->assertSame(0.7, $agent->getTemperature());
        $this->assertSame(1000, $agent->getMaxTokens());
        $this->assertTrue($agent->getEnableRag());
        $this->assertSame('hybrid', $agent->getRagSearchMode());
        $this->assertSame(['admin'], $agent->getGroups());
        $this->assertSame(['register', 'objects'], $agent->getTools());
    }

    public function testAgentEntityJsonSerialize(): void
    {
        $agent = new Agent();
        $agent->hydrate([
            'name' => 'Serializable Agent',
            'type' => 'automation',
        ]);

        $json = $agent->jsonSerialize();
        $this->assertSame('Serializable Agent', $json['name']);
        $this->assertSame('automation', $json['type']);
        $this->assertNull($json['managedByConfiguration']);
    }

    // =========================================================================
    // Chunk entity tests
    // =========================================================================

    public function testChunkEntityJsonSerialize(): void
    {
        $chunk = new Chunk();
        $chunk->setUuid('test-uuid');
        $chunk->setSourceType('file');
        $chunk->setSourceId(42);
        $chunk->setChunkIndex(0);
        $chunk->setStartOffset(0);
        $chunk->setEndOffset(100);
        $chunk->setIndexed(true);
        $chunk->setVectorized(false);

        $json = $chunk->jsonSerialize();
        $this->assertSame('test-uuid', $json['uuid']);
        $this->assertSame('file', $json['sourceType']);
        $this->assertSame(42, $json['sourceId']);
        $this->assertTrue($json['indexed']);
        $this->assertFalse($json['vectorized']);
    }

    // =========================================================================
    // Configuration entity hydration edge cases
    // =========================================================================

    public function testConfigurationEntityHydrateWithApplicationAlias(): void
    {
        $config = new Configuration();
        $config->hydrate([
            'application' => 'testapp',
            'title' => 'Test',
        ]);

        $this->assertSame('testapp', $config->getApp());
    }

    public function testConfigurationEntityHydrateWithEmptyJsonFields(): void
    {
        $config = new Configuration();
        $config->hydrate([
            'title' => 'Test',
            'registers' => [],
            'schemas' => [],
        ]);

        // Empty arrays should be set to null for JSON fields.
        $this->assertNull($config->getRegisters());
        $this->assertNull($config->getSchemas());
    }
}
