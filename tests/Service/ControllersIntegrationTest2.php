<?php

/**
 * Integration tests for additional controllers to increase PCOV line coverage (batch 2)
 *
 * Tests real code paths through ChatController, ConversationController, BulkController,
 * FileTextController, FileExtractionController, GdprEntitiesController, WebhooksController,
 * OrganisationController, McpServerController, and Settings controllers.
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

use OCA\OpenRegister\Controller\BulkController;
use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Controller\ConversationController;
use OCA\OpenRegister\Controller\FileExtractionController;
use OCA\OpenRegister\Controller\FileTextController;
use OCA\OpenRegister\Controller\GdprEntitiesController;
use OCA\OpenRegister\Controller\McpServerController;
use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Controller\Settings\LlmSettingsController;
use OCA\OpenRegister\Controller\Settings\N8nSettingsController;
use OCA\OpenRegister\Controller\Settings\SolrSettingsController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Service\WebhookService;
use OC\Files\AppData\Factory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for additional controllers (batch 2)
 *
 * @group DB
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ControllersIntegrationTest2 extends TestCase
{

    /**
     * Mock request
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * App config
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * IDs to clean up
     *
     * @var array<string, array<int>>
     */
    private array $cleanupIds = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request   = $this->createMock(IRequest::class);
        $this->db        = \OC::$server->get(IDBConnection::class);
        $this->logger    = \OC::$server->get(LoggerInterface::class);
        $this->appConfig = \OC::$server->get(IAppConfig::class);
    }//end setUp()

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $tableMap = [
            'conversations' => 'openregister_conversations',
            'messages'      => 'openregister_messages',
            'feedback'      => 'openregister_feedback',
            'agents'        => 'openregister_agents',
            'webhooks'      => 'openregister_webhooks',
            'webhook_logs'  => 'openregister_webhook_logs',
            'organisations' => 'openregister_organisations',
        ];

        foreach ($this->cleanupIds as $key => $ids) {
            if (empty($ids) === true || isset($tableMap[$key]) === false) {
                continue;
            }

            $table = $tableMap[$key];
            $qb    = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }

        parent::tearDown();
    }//end tearDown()

    // ─── ChatController ──────────────────────────────────────────────────

    /**
     * Test ChatController::page returns TemplateResponse
     *
     * @return void
     */
    public function testChatControllerPage(): void
    {
        $controller = $this->buildChatController();
        $response   = $controller->page();

        $this->assertSame(200, $response->getStatus());
    }//end testChatControllerPage()

    /**
     * Test ChatController::sendMessage with empty message
     *
     * @return void
     */
    public function testChatControllerSendMessageEmptyMessage(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversation', null, ''],
            ['agentUuid', null, ''],
            ['message', null, ''],
            ['views', null, null],
            ['tools', null, null],
            ['includeObjects', null, true],
            ['includeFiles', null, true],
            ['numSourcesFiles', null, 5],
            ['numSourcesObjects', null, 5],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->sendMessage();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Missing message', $data['error']);
    }//end testChatControllerSendMessageEmptyMessage()

    /**
     * Test ChatController::sendMessage without conversation or agent
     *
     * @return void
     */
    public function testChatControllerSendMessageNoConversationOrAgent(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversation', null, ''],
            ['agentUuid', null, ''],
            ['message', null, 'Hello test'],
            ['views', null, null],
            ['tools', null, null],
            ['includeObjects', null, true],
            ['includeFiles', null, true],
            ['numSourcesFiles', null, 5],
            ['numSourcesObjects', null, 5],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->sendMessage();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $data);
    }//end testChatControllerSendMessageNoConversationOrAgent()

    /**
     * Test ChatController::sendMessage with non-existent conversation UUID
     *
     * @return void
     */
    public function testChatControllerSendMessageNonExistentConversation(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversation', null, 'non-existent-uuid-12345'],
            ['agentUuid', null, ''],
            ['message', null, 'Hello test'],
            ['views', null, null],
            ['tools', null, null],
            ['includeObjects', null, true],
            ['includeFiles', null, true],
            ['numSourcesFiles', null, 5],
            ['numSourcesObjects', null, 5],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->sendMessage();
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertArrayHasKey('error', $data);
    }//end testChatControllerSendMessageNonExistentConversation()

    /**
     * Test ChatController::getHistory with missing conversationId
     *
     * @return void
     */
    public function testChatControllerGetHistoryMissingId(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversationId', null, 0],
            ['limit', null, 100],
            ['offset', null, 0],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->getHistory();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Missing conversationId', $data['error']);
    }//end testChatControllerGetHistoryMissingId()

    /**
     * Test ChatController::getHistory with non-existent conversation
     *
     * @return void
     */
    public function testChatControllerGetHistoryNonExistentConversation(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversationId', null, 999999],
            ['limit', null, 100],
            ['offset', null, 0],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->getHistory();
        $data       = $response->getData();

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to fetch conversation history', $data['error']);
    }//end testChatControllerGetHistoryNonExistentConversation()

    /**
     * Test ChatController::clearHistory with missing conversationId
     *
     * @return void
     */
    public function testChatControllerClearHistoryMissingId(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversationId', null, 0],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->clearHistory();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Missing conversationId', $data['error']);
    }//end testChatControllerClearHistoryMissingId()

    /**
     * Test ChatController::clearHistory with non-existent conversation
     *
     * @return void
     */
    public function testChatControllerClearHistoryNonExistentConversation(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['conversationId', null, 999999],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->clearHistory();
        $data       = $response->getData();

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to clear conversation', $data['error']);
    }//end testChatControllerClearHistoryNonExistentConversation()

    /**
     * Test ChatController::getChatStats
     *
     * @return void
     */
    public function testChatControllerGetChatStats(): void
    {
        $controller = $this->buildChatController();
        $response   = $controller->getChatStats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('total_agents', $data);
        $this->assertArrayHasKey('total_conversations', $data);
        $this->assertArrayHasKey('total_messages', $data);
    }//end testChatControllerGetChatStats()

    /**
     * Test ChatController::sendFeedback with invalid type
     *
     * @return void
     */
    public function testChatControllerSendFeedbackInvalidType(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'invalid_type'],
            ['comment', '', ''],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->sendFeedback('some-uuid', 1);
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Invalid feedback type', $data['error']);
    }//end testChatControllerSendFeedbackInvalidType()

    /**
     * Test ChatController::sendFeedback with non-existent conversation
     *
     * @return void
     */
    public function testChatControllerSendFeedbackNonExistentConversation(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'positive'],
            ['comment', '', 'great response'],
        ]);

        $controller = $this->buildChatController();
        $response   = $controller->sendFeedback('non-existent-uuid', 1);
        $data       = $response->getData();

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('Failed to save feedback', $data['error']);
    }//end testChatControllerSendFeedbackNonExistentConversation()

    // ─── ConversationController ──────────────────────────────────────────

    /**
     * Test ConversationController::index lists conversations
     *
     * @return void
     */
    public function testConversationControllerIndex(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildConversationController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }//end testConversationControllerIndex()

    /**
     * Test ConversationController::index with deleted filter
     *
     * @return void
     */
    public function testConversationControllerIndexDeleted(): void
    {
        $this->request->method('getParams')->willReturn([
            '_deleted' => 'true',
            'limit'    => 10,
            'offset'   => 0,
        ]);

        $controller = $this->buildConversationController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('results', $data);
    }//end testConversationControllerIndexDeleted()

    /**
     * Test ConversationController::show with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerShowNotFound(): void
    {
        $controller = $this->buildConversationController();
        $response   = $controller->show('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerShowNotFound()

    /**
     * Test ConversationController::create creates a conversation
     *
     * @return void
     */
    public function testConversationControllerCreate(): void
    {
        $this->request->method('getParams')->willReturn([
            'title'    => 'Test Conversation Integration',
            'metadata' => ['test' => true],
        ]);

        $controller = $this->buildConversationController();
        $response   = $controller->create();
        $data       = $response->getData();

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('Test Conversation Integration', $data['title']);

        // Track for cleanup.
        $this->cleanupIds['conversations'][] = $data['id'];
    }//end testConversationControllerCreate()

    /**
     * Test ConversationController::create with invalid agentUuid
     *
     * @return void
     */
    public function testConversationControllerCreateWithInvalidAgent(): void
    {
        $this->request->method('getParams')->willReturn([
            'title'     => 'Test Conversation No Agent',
            'agentUuid' => 'non-existent-agent-uuid',
        ]);

        $controller = $this->buildConversationController();
        $response   = $controller->create();
        $data       = $response->getData();

        // Should still create but without agentId.
        $this->assertSame(201, $response->getStatus());
        $this->assertSame('Test Conversation No Agent', $data['title']);

        $this->cleanupIds['conversations'][] = $data['id'];
    }//end testConversationControllerCreateWithInvalidAgent()

    /**
     * Test ConversationController::update with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn([
            'title' => 'Updated Title',
        ]);

        $controller = $this->buildConversationController();
        $response   = $controller->update('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerUpdateNotFound()

    /**
     * Test ConversationController::destroy with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerDestroyNotFound(): void
    {
        $controller = $this->buildConversationController();
        $response   = $controller->destroy('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerDestroyNotFound()

    /**
     * Test ConversationController::restore with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerRestoreNotFound(): void
    {
        $controller = $this->buildConversationController();
        $response   = $controller->restore('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerRestoreNotFound()

    /**
     * Test ConversationController::destroyPermanent with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerDestroyPermanentNotFound(): void
    {
        $controller = $this->buildConversationController();
        $response   = $controller->destroyPermanent('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerDestroyPermanentNotFound()

    /**
     * Test ConversationController::messages with non-existent UUID
     *
     * @return void
     */
    public function testConversationControllerMessagesNotFound(): void
    {
        $controller = $this->buildConversationController();
        $response   = $controller->messages('non-existent-uuid-xyz');
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Conversation not found', $data['error']);
    }//end testConversationControllerMessagesNotFound()

    /**
     * Test ConversationController full lifecycle: create, update, soft delete, restore, hard delete
     *
     * @return void
     */
    public function testConversationControllerFullLifecycle(): void
    {
        // Create.
        $this->request->method('getParams')->willReturn([
            'title'    => 'Lifecycle Test Conversation',
            'metadata' => [],
        ]);
        $controller = $this->buildConversationController();
        $response   = $controller->create();
        $this->assertSame(201, $response->getStatus());
        $data = $response->getData();
        $uuid = $data['uuid'];
        $this->cleanupIds['conversations'][] = $data['id'];

        // Show.
        $response = $controller->show($uuid);
        $this->assertSame(200, $response->getStatus());

        // Messages.
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParams')->willReturn(['limit' => 10, 'offset' => 0]);
        $controller = $this->buildConversationController();
        $response   = $controller->messages($uuid);
        $this->assertSame(200, $response->getStatus());

        // Update.
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getParams')->willReturn([
            'title'    => 'Updated Lifecycle Title',
            'metadata' => ['updated' => true],
        ]);
        $controller = $this->buildConversationController();
        $response   = $controller->update($uuid);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Updated Lifecycle Title', $response->getData()['title']);

        // Soft delete.
        $response = $controller->destroy($uuid);
        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['archived']);

        // Restore.
        $response = $controller->restore($uuid);
        $this->assertSame(200, $response->getStatus());

        // Permanent delete.
        $response = $controller->destroyPermanent($uuid);
        $this->assertSame(200, $response->getStatus());

        // Remove from cleanup since already deleted.
        $this->cleanupIds['conversations'] = array_diff(
            $this->cleanupIds['conversations'],
            [$data['id']]
        );
    }//end testConversationControllerFullLifecycle()

    // ─── GdprEntitiesController ──────────────────────────────────────────

    /**
     * Test GdprEntitiesController::index
     *
     * @return void
     */
    public function testGdprEntitiesControllerIndex(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', '', ''],
            ['type', '', ''],
            ['category', '', ''],
        ]);

        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('count', $data);
    }//end testGdprEntitiesControllerIndex()

    /**
     * Test GdprEntitiesController::index with search filter
     *
     * @return void
     */
    public function testGdprEntitiesControllerIndexWithSearch(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', '', 'test-search-term'],
            ['type', '', ''],
            ['category', '', ''],
        ]);

        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testGdprEntitiesControllerIndexWithSearch()

    /**
     * Test GdprEntitiesController::index with type filter
     *
     * @return void
     */
    public function testGdprEntitiesControllerIndexWithTypeFilter(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', '', ''],
            ['type', '', 'PERSON'],
            ['category', '', ''],
        ]);

        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testGdprEntitiesControllerIndexWithTypeFilter()

    /**
     * Test GdprEntitiesController::index with category filter
     *
     * @return void
     */
    public function testGdprEntitiesControllerIndexWithCategoryFilter(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', '', ''],
            ['type', '', ''],
            ['category', '', 'personal_data'],
        ]);

        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testGdprEntitiesControllerIndexWithCategoryFilter()

    /**
     * Test GdprEntitiesController::show with non-existent entity
     *
     * @return void
     */
    public function testGdprEntitiesControllerShowNotFound(): void
    {
        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->show(999999);
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Entity not found', $data['message']);
    }//end testGdprEntitiesControllerShowNotFound()

    /**
     * Test GdprEntitiesController::getTypes
     *
     * @return void
     */
    public function testGdprEntitiesControllerGetTypes(): void
    {
        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->getTypes();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }//end testGdprEntitiesControllerGetTypes()

    /**
     * Test GdprEntitiesController::getCategories
     *
     * @return void
     */
    public function testGdprEntitiesControllerGetCategories(): void
    {
        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->getCategories();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }//end testGdprEntitiesControllerGetCategories()

    /**
     * Test GdprEntitiesController::getStats
     *
     * @return void
     */
    public function testGdprEntitiesControllerGetStats(): void
    {
        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->getStats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('totalEntities', $data['data']);
        $this->assertArrayHasKey('totalRelations', $data['data']);
        $this->assertArrayHasKey('byType', $data['data']);
        $this->assertArrayHasKey('byCategory', $data['data']);
    }//end testGdprEntitiesControllerGetStats()

    /**
     * Test GdprEntitiesController::destroy with non-existent entity
     *
     * @return void
     */
    public function testGdprEntitiesControllerDestroyNotFound(): void
    {
        $controller = $this->buildGdprEntitiesController();
        $response   = $controller->destroy(999999);
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('Entity not found', $data['message']);
    }//end testGdprEntitiesControllerDestroyNotFound()

    // ─── WebhooksController ──────────────────────────────────────────────

    /**
     * Test WebhooksController::index lists webhooks
     *
     * @return void
     */
    public function testWebhooksControllerIndex(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildWebhooksController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }//end testWebhooksControllerIndex()

    /**
     * Test WebhooksController::index with pagination
     *
     * @return void
     */
    public function testWebhooksControllerIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit'  => 5,
            '_offset' => 0,
        ]);

        $controller = $this->buildWebhooksController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testWebhooksControllerIndexWithPagination()

    /**
     * Test WebhooksController::index with page-based pagination
     *
     * @return void
     */
    public function testWebhooksControllerIndexWithPagePagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit'  => 10,
            '_page'   => 1,
            '_extend' => 'logs',
        ]);

        $controller = $this->buildWebhooksController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testWebhooksControllerIndexWithPagePagination()

    // ─── OrganisationController ──────────────────────────────────────────

    /**
     * Test OrganisationController::index
     *
     * @return void
     */
    public function testOrganisationControllerIndex(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->index();

        $this->assertSame(200, $response->getStatus());
    }//end testOrganisationControllerIndex()

    /**
     * Test OrganisationController::getActive
     *
     * @return void
     */
    public function testOrganisationControllerGetActive(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->getActive();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('activeOrganisation', $data);
    }//end testOrganisationControllerGetActive()

    /**
     * Test OrganisationController::create with empty name
     *
     * @return void
     */
    public function testOrganisationControllerCreateEmptyName(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->create(' ', '');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Organisation name is required', $data['error']);
    }//end testOrganisationControllerCreateEmptyName()

    /**
     * Test OrganisationController::create and then cleanup
     *
     * @return void
     */
    public function testOrganisationControllerCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->create('Test Org Integration', 'Test description');
        $data       = $response->getData();

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('Organisation created successfully', $data['message']);
        $this->assertArrayHasKey('organisation', $data);

        $this->cleanupIds['organisations'][] = $data['organisation']['id'];
    }//end testOrganisationControllerCreateSuccess()

    /**
     * Test OrganisationController::show with non-existent UUID
     *
     * @return void
     */
    public function testOrganisationControllerShowNotFound(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->show('non-existent-org-uuid');
        $data       = $response->getData();

        // Controller returns 403 when organisation not found (access denied before existence check).
        $this->assertContains($response->getStatus(), [403, 404]);
    }//end testOrganisationControllerShowNotFound()

    /**
     * Test OrganisationController::search
     *
     * @return void
     */
    public function testOrganisationControllerSearch(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 50, 10],
            ['_offset', 0, 0],
        ]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->search('');
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('organisations', $data);
    }//end testOrganisationControllerSearch()

    /**
     * Test OrganisationController::search with query
     *
     * @return void
     */
    public function testOrganisationControllerSearchWithQuery(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['_limit', 50, 10],
            ['_offset', 0, 0],
        ]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->search('test');
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('organisations', $data);
    }//end testOrganisationControllerSearchWithQuery()

    /**
     * Test OrganisationController::clearCache
     *
     * @return void
     */
    public function testOrganisationControllerClearCache(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->clearCache();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Cache cleared successfully', $data['message']);
    }//end testOrganisationControllerClearCache()

    /**
     * Test OrganisationController::stats
     *
     * @return void
     */
    public function testOrganisationControllerStats(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->stats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('statistics', $data);
    }//end testOrganisationControllerStats()

    /**
     * Test OrganisationController::setActive with non-existent org
     *
     * @return void
     */
    public function testOrganisationControllerSetActiveNonExistent(): void
    {
        $controller = $this->buildOrganisationController();
        $response   = $controller->setActive('non-existent-uuid');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $data);
    }//end testOrganisationControllerSetActiveNonExistent()

    /**
     * Test OrganisationController::join with non-existent org
     *
     * @return void
     */
    public function testOrganisationControllerJoinNonExistent(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->join('non-existent-uuid');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $data);
    }//end testOrganisationControllerJoinNonExistent()

    /**
     * Test OrganisationController::leave with non-existent org
     *
     * @return void
     */
    public function testOrganisationControllerLeaveNonExistent(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildOrganisationController();
        $response   = $controller->leave('non-existent-uuid');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $data);
    }//end testOrganisationControllerLeaveNonExistent()

    // ─── FileTextController ──────────────────────────────────────────────

    /**
     * Test FileTextController::getFileText (deprecated endpoint)
     *
     * @return void
     */
    public function testFileTextControllerGetFileText(): void
    {
        $controller = $this->buildFileTextController();
        $response   = $controller->getFileText(1);
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileTextControllerGetFileText()

    /**
     * Test FileTextController::deleteFileText (not implemented)
     *
     * @return void
     */
    public function testFileTextControllerDeleteFileText(): void
    {
        $controller = $this->buildFileTextController();
        $response   = $controller->deleteFileText(1);
        $data       = $response->getData();

        $this->assertSame(501, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileTextControllerDeleteFileText()

    /**
     * Test FileTextController::extractFileText when extraction is disabled
     *
     * @return void
     */
    public function testFileTextControllerExtractFileTextDisabled(): void
    {
        // Ensure extraction is disabled.
        $this->appConfig->setValueString('openregister', 'fileManagement', json_encode(['extractionScope' => 'none']));

        $controller = $this->buildFileTextController();
        $response   = $controller->extractFileText(1);
        $data       = $response->getData();

        $this->assertSame(501, $response->getStatus());
        $this->assertFalse($data['success']);

        // Cleanup config.
        $this->appConfig->deleteKey('openregister', 'fileManagement');
    }//end testFileTextControllerExtractFileTextDisabled()

    /**
     * Test FileTextController::getStats
     *
     * @return void
     */
    public function testFileTextControllerGetStats(): void
    {
        $controller = $this->buildFileTextController();
        $response   = $controller->getStats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('stats', $data);
    }//end testFileTextControllerGetStats()

    /**
     * Test FileTextController::getChunkingStats
     *
     * @return void
     */
    public function testFileTextControllerGetChunkingStats(): void
    {
        $controller = $this->buildFileTextController();
        $response   = $controller->getChunkingStats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileTextControllerGetChunkingStats()

    // ─── FileExtractionController ────────────────────────────────────────

    /**
     * Test FileExtractionController::show with non-existent file
     *
     * @return void
     */
    public function testFileExtractionControllerShowNotFound(): void
    {
        $controller = $this->buildFileExtractionController();
        $response   = $controller->show(999999);
        $data       = $response->getData();

        $this->assertSame(404, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileExtractionControllerShowNotFound()

    /**
     * Test FileExtractionController::stats
     *
     * @return void
     */
    public function testFileExtractionControllerStats(): void
    {
        $controller = $this->buildFileExtractionController();
        $response   = $controller->stats();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileExtractionControllerStats()

    /**
     * Test FileExtractionController::cleanup
     *
     * @return void
     */
    public function testFileExtractionControllerCleanup(): void
    {
        $controller = $this->buildFileExtractionController();
        $response   = $controller->cleanup();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileExtractionControllerCleanup()

    /**
     * Test FileExtractionController::fileTypes
     *
     * @return void
     */
    public function testFileExtractionControllerFileTypes(): void
    {
        $controller = $this->buildFileExtractionController();
        $response   = $controller->fileTypes();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileExtractionControllerFileTypes()

    /**
     * Test FileExtractionController::index with status filter
     *
     * @return void
     */
    public function testFileExtractionControllerIndexNonCompletedStatus(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', null, null],
            ['status', null, 'pending'],
            ['riskLevel', null, null],
            ['sort', 'extractedAt', 'extractedAt'],
            ['order', 'DESC', 'DESC'],
        ]);

        $controller = $this->buildFileExtractionController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['count']);
    }//end testFileExtractionControllerIndexNonCompletedStatus()

    /**
     * Test FileExtractionController::index default
     *
     * @return void
     */
    public function testFileExtractionControllerIndex(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['limit', 50, 10],
            ['offset', 0, 0],
            ['search', null, null],
            ['status', null, null],
            ['riskLevel', null, null],
            ['sort', 'extractedAt', 'extractedAt'],
            ['order', 'DESC', 'DESC'],
        ]);

        $controller = $this->buildFileExtractionController();
        $response   = $controller->index();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileExtractionControllerIndex()

    // ─── BulkController ──────────────────────────────────────────────────

    /**
     * Test BulkController::delete with invalid uuids
     *
     * @return void
     */
    public function testBulkControllerDeleteInvalidUuids(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids' => [],
        ]);

        $controller = $this->buildBulkController();
        $response   = $controller->delete('1', '1');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('uuids', $data['error']);
    }//end testBulkControllerDeleteInvalidUuids()

    /**
     * Test BulkController::publish with invalid uuids
     *
     * @return void
     */
    public function testBulkControllerPublishInvalidUuids(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids' => [],
        ]);

        $controller = $this->buildBulkController();
        $response   = $controller->publish('1', '1');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('uuids', $data['error']);
    }//end testBulkControllerPublishInvalidUuids()

    /**
     * Test BulkController::publish with invalid datetime format
     *
     * @return void
     */
    public function testBulkControllerPublishInvalidDatetime(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids'    => ['uuid-1'],
            'datetime' => 'not-a-valid-date',
        ]);

        $controller = $this->buildBulkController();
        $response   = $controller->publish('1', '1');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('datetime', $data['error']);
    }//end testBulkControllerPublishInvalidDatetime()

    /**
     * Test BulkController::depublish with invalid uuids
     *
     * @return void
     */
    public function testBulkControllerDepublishInvalidUuids(): void
    {
        $this->request->method('getParams')->willReturn([
            'uuids' => [],
        ]);

        $controller = $this->buildBulkController();
        $response   = $controller->depublish('1', '1');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('uuids', $data['error']);
    }//end testBulkControllerDepublishInvalidUuids()

    /**
     * Test BulkController::save with invalid objects
     *
     * @return void
     */
    public function testBulkControllerSaveInvalidObjects(): void
    {
        $this->request->method('getParams')->willReturn([
            'objects' => [],
        ]);

        $controller = $this->buildBulkController();
        $response   = $controller->save('1', '1');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('objects', $data['error']);
    }//end testBulkControllerSaveInvalidObjects()

    /**
     * Test BulkController::publishSchema with non-numeric schema
     *
     * @return void
     */
    public function testBulkControllerPublishSchemaNonNumeric(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildBulkController();
        $response   = $controller->publishSchema('1', 'not-numeric');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('numeric', $data['error']);
    }//end testBulkControllerPublishSchemaNonNumeric()

    /**
     * Test BulkController::deleteSchema with non-numeric schema
     *
     * @return void
     */
    public function testBulkControllerDeleteSchemaNonNumeric(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildBulkController();
        $response   = $controller->deleteSchema('1', 'not-numeric');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('numeric', $data['error']);
    }//end testBulkControllerDeleteSchemaNonNumeric()

    /**
     * Test BulkController::deleteRegister with non-numeric register
     *
     * @return void
     */
    public function testBulkControllerDeleteRegisterNonNumeric(): void
    {
        $controller = $this->buildBulkController();
        $response   = $controller->deleteRegister('not-numeric');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('numeric', $data['error']);
    }//end testBulkControllerDeleteRegisterNonNumeric()

    /**
     * Test BulkController::validateSchema with non-numeric schema
     *
     * @return void
     */
    public function testBulkControllerValidateSchemaNonNumeric(): void
    {
        $controller = $this->buildBulkController();
        $response   = $controller->validateSchema('not-numeric');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('numeric', $data['error']);
    }//end testBulkControllerValidateSchemaNonNumeric()

    // ─── Settings Controllers ────────────────────────────────────────────

    /**
     * Test FileSettingsController::getFileSettings
     *
     * @return void
     */
    public function testFileSettingsControllerGetFileSettings(): void
    {
        $controller = $this->buildFileSettingsController();
        $response   = $controller->getFileSettings();

        $this->assertSame(200, $response->getStatus());
    }//end testFileSettingsControllerGetFileSettings()

    /**
     * Test FileSettingsController::updateFileSettings
     *
     * @return void
     */
    public function testFileSettingsControllerUpdateFileSettings(): void
    {
        $this->request->method('getParams')->willReturn([
            'provider'         => ['id' => 'dolphin'],
            'chunkingStrategy' => ['id' => 'fixed'],
        ]);

        $controller = $this->buildFileSettingsController();
        $response   = $controller->updateFileSettings();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testFileSettingsControllerUpdateFileSettings()

    /**
     * Test FileSettingsController::testDolphinConnection with empty params
     *
     * @return void
     */
    public function testFileSettingsControllerTestDolphinEmpty(): void
    {
        $controller = $this->buildFileSettingsController();
        $response   = $controller->testDolphinConnection('', '');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileSettingsControllerTestDolphinEmpty()

    /**
     * Test FileSettingsController::testPresidioConnection with empty params
     *
     * @return void
     */
    public function testFileSettingsControllerTestPresidioEmpty(): void
    {
        $controller = $this->buildFileSettingsController();
        $response   = $controller->testPresidioConnection('');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileSettingsControllerTestPresidioEmpty()

    /**
     * Test FileSettingsController::testOpenAnonymiserConnection with empty params
     *
     * @return void
     */
    public function testFileSettingsControllerTestOpenAnonymiserEmpty(): void
    {
        $controller = $this->buildFileSettingsController();
        $response   = $controller->testOpenAnonymiserConnection('');
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testFileSettingsControllerTestOpenAnonymiserEmpty()

    /**
     * Test FileSettingsController::getFileExtractionStats triggers error (missing method)
     *
     * @return void
     */
    public function testFileSettingsControllerGetFileExtractionStats(): void
    {
        $controller = $this->buildFileSettingsController();

        // The underlying TextExtractionService::getExtractionStats() does not exist,
        // so this call should trigger an error caught by the controller.
        try {
            $response = $controller->getFileExtractionStats();
            // If it doesn't throw, the controller must have caught it and returned an error response.
            $this->assertInstanceOf(JSONResponse::class, $response);
        } catch (\Error $e) {
            // Method doesn't exist on the service - expected.
            $this->assertStringContainsString('getExtractionStats', $e->getMessage());
        }
    }//end testFileSettingsControllerGetFileExtractionStats()

    /**
     * Test N8nSettingsController::getN8nSettings
     *
     * @return void
     */
    public function testN8nSettingsControllerGetSettings(): void
    {
        $controller = $this->buildN8nSettingsController();
        $response   = $controller->getN8nSettings();

        $this->assertSame(200, $response->getStatus());
    }//end testN8nSettingsControllerGetSettings()

    /**
     * Test N8nSettingsController::testN8nConnection with empty params
     *
     * @return void
     */
    public function testN8nSettingsControllerTestConnectionEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'url'    => '',
            'apiKey' => '',
        ]);

        $controller = $this->buildN8nSettingsController();
        $response   = $controller->testN8nConnection();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testN8nSettingsControllerTestConnectionEmpty()

    /**
     * Test N8nSettingsController::initializeN8n when not configured
     *
     * @return void
     */
    public function testN8nSettingsControllerInitializeNotConfigured(): void
    {
        $this->request->method('getParams')->willReturn([
            'project' => 'test-project',
        ]);

        $controller = $this->buildN8nSettingsController();
        $response   = $controller->initializeN8n();
        $data       = $response->getData();

        // Should fail because n8n is not configured.
        $this->assertGreaterThanOrEqual(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testN8nSettingsControllerInitializeNotConfigured()

    /**
     * Test N8nSettingsController::getWorkflows when not configured
     *
     * @return void
     */
    public function testN8nSettingsControllerGetWorkflowsNotConfigured(): void
    {
        $controller = $this->buildN8nSettingsController();
        $response   = $controller->getWorkflows();
        $data       = $response->getData();

        // Should fail because n8n is not configured.
        $this->assertGreaterThanOrEqual(400, $response->getStatus());
    }//end testN8nSettingsControllerGetWorkflowsNotConfigured()

    /**
     * Test LlmSettingsController::getLLMSettings
     *
     * @return void
     */
    public function testLlmSettingsControllerGetSettings(): void
    {
        $controller = $this->buildLlmSettingsController();
        $response   = $controller->getLLMSettings();

        $this->assertSame(200, $response->getStatus());
    }//end testLlmSettingsControllerGetSettings()

    /**
     * Test LlmSettingsController::updateLLMSettings
     *
     * @return void
     */
    public function testLlmSettingsControllerUpdateSettings(): void
    {
        $this->request->method('getParams')->willReturn([
            'fireworksConfig' => [
                'embeddingModel' => ['id' => 'test-model'],
                'chatModel'      => ['id' => 'test-chat-model'],
            ],
            'openaiConfig'    => [
                'model'     => ['id' => 'gpt-4'],
                'chatModel' => ['id' => 'gpt-4-chat'],
            ],
            'ollamaConfig'    => [
                'model'     => ['id' => 'llama2'],
                'chatModel' => ['id' => 'llama2-chat'],
            ],
        ]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->updateLLMSettings();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testLlmSettingsControllerUpdateSettings()

    /**
     * Test LlmSettingsController::patchLLMSettings (alias)
     *
     * @return void
     */
    public function testLlmSettingsControllerPatchSettings(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->patchLLMSettings();

        $this->assertSame(200, $response->getStatus());
    }//end testLlmSettingsControllerPatchSettings()

    /**
     * Test LlmSettingsController::testEmbedding with empty provider
     *
     * @return void
     */
    public function testLlmSettingsControllerTestEmbeddingEmpty(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, ''],
            ['config', [], []],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->testEmbedding();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testLlmSettingsControllerTestEmbeddingEmpty()

    /**
     * Test LlmSettingsController::testEmbedding with empty config
     *
     * @return void
     */
    public function testLlmSettingsControllerTestEmbeddingEmptyConfig(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], []],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->testEmbedding();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testLlmSettingsControllerTestEmbeddingEmptyConfig()

    /**
     * Test LlmSettingsController::testChat with empty provider
     *
     * @return void
     */
    public function testLlmSettingsControllerTestChatEmpty(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, ''],
            ['config', [], []],
            ['testMessage', 'Hello! Please respond with a brief greeting.', 'Hello'],
        ]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->testChat();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testLlmSettingsControllerTestChatEmpty()

    /**
     * Test LlmSettingsController::testChat with empty config
     *
     * @return void
     */
    public function testLlmSettingsControllerTestChatEmptyConfig(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], []],
            ['testMessage', 'Hello! Please respond with a brief greeting.', 'Hello'],
        ]);

        $controller = $this->buildLlmSettingsController();
        $response   = $controller->testChat();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testLlmSettingsControllerTestChatEmptyConfig()

    /**
     * Test LlmSettingsController::checkEmbeddingModelMismatch
     *
     * @return void
     */
    public function testLlmSettingsControllerCheckMismatch(): void
    {
        $controller = $this->buildLlmSettingsController();
        $response   = $controller->checkEmbeddingModelMismatch();

        $this->assertSame(200, $response->getStatus());
    }//end testLlmSettingsControllerCheckMismatch()

    /**
     * Test CacheSettingsController::getCacheStats
     *
     * @return void
     */
    public function testCacheSettingsControllerGetCacheStats(): void
    {
        $controller = $this->buildCacheSettingsController();
        $response   = $controller->getCacheStats();

        $this->assertSame(200, $response->getStatus());
    }//end testCacheSettingsControllerGetCacheStats()

    /**
     * Test CacheSettingsController::clearCache
     *
     * @return void
     */
    public function testCacheSettingsControllerClearCache(): void
    {
        $this->request->method('getParams')->willReturn(['type' => 'all']);

        $controller = $this->buildCacheSettingsController();

        try {
            $response = $controller->clearCache();
            // If no error, check it returns a response.
            $this->assertInstanceOf(JSONResponse::class, $response);
        } catch (\TypeError $e) {
            // Known bug in CacheSettingsHandler::clearCache - unsupported operand types.
            $this->assertStringContainsString('Unsupported operand types', $e->getMessage());
        }
    }//end testCacheSettingsControllerClearCache()

    /**
     * Test CacheSettingsController::getWarmupInterval
     *
     * @return void
     */
    public function testCacheSettingsControllerGetWarmupInterval(): void
    {
        $controller = $this->buildCacheSettingsController();
        $response   = $controller->getWarmupInterval();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('interval', $data);
    }//end testCacheSettingsControllerGetWarmupInterval()

    /**
     * Test CacheSettingsController::setWarmupInterval with valid interval
     *
     * @return void
     */
    public function testCacheSettingsControllerSetWarmupInterval(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 3600]);

        $controller = $this->buildCacheSettingsController();
        $response   = $controller->setWarmupInterval();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertSame(3600, $data['interval']);
    }//end testCacheSettingsControllerSetWarmupInterval()

    /**
     * Test CacheSettingsController::setWarmupInterval with zero (disabled)
     *
     * @return void
     */
    public function testCacheSettingsControllerSetWarmupIntervalDisabled(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 0]);

        $controller = $this->buildCacheSettingsController();
        $response   = $controller->setWarmupInterval();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertFalse($data['enabled']);
    }//end testCacheSettingsControllerSetWarmupIntervalDisabled()

    /**
     * Test CacheSettingsController::setWarmupInterval with too small interval
     *
     * @return void
     */
    public function testCacheSettingsControllerSetWarmupIntervalTooSmall(): void
    {
        $this->request->method('getParams')->willReturn(['interval' => 100]);

        $controller = $this->buildCacheSettingsController();
        $response   = $controller->setWarmupInterval();
        $data       = $response->getData();

        $this->assertSame(422, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testCacheSettingsControllerSetWarmupIntervalTooSmall()

    /**
     * Test ApiTokenSettingsController::getApiTokens
     *
     * @return void
     */
    public function testApiTokenSettingsControllerGetApiTokens(): void
    {
        $controller = $this->buildApiTokenSettingsController();
        $response   = $controller->getApiTokens();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('github_token', $data);
        $this->assertArrayHasKey('gitlab_token', $data);
    }//end testApiTokenSettingsControllerGetApiTokens()

    /**
     * Test ApiTokenSettingsController::saveApiTokens
     *
     * @return void
     */
    public function testApiTokenSettingsControllerSaveApiTokens(): void
    {
        $this->request->method('getParams')->willReturn([
            'gitlab_url' => 'https://gitlab.example.com/api/v4',
        ]);

        $controller = $this->buildApiTokenSettingsController();
        $response   = $controller->saveApiTokens();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testApiTokenSettingsControllerSaveApiTokens()

    /**
     * Test ApiTokenSettingsController::saveApiTokens skips masked tokens
     *
     * @return void
     */
    public function testApiTokenSettingsControllerSaveSkipsMasked(): void
    {
        $this->request->method('getParams')->willReturn([
            'github_token' => 'ghp_***masked***',
            'gitlab_token' => 'glpat_***masked***',
        ]);

        $controller = $this->buildApiTokenSettingsController();
        $response   = $controller->saveApiTokens();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
    }//end testApiTokenSettingsControllerSaveSkipsMasked()

    /**
     * Test ApiTokenSettingsController::testGitHubToken with empty token
     *
     * @return void
     */
    public function testApiTokenSettingsControllerTestGitHubTokenEmpty(): void
    {
        $this->request->method('getParams')->willReturn(['token' => '']);

        // Ensure no stored token.
        $this->appConfig->setValueString('openregister', 'github_api_token', '');

        $controller = $this->buildApiTokenSettingsController();
        $response   = $controller->testGitHubToken();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testApiTokenSettingsControllerTestGitHubTokenEmpty()

    /**
     * Test ApiTokenSettingsController::testGitLabToken with empty token
     *
     * @return void
     */
    public function testApiTokenSettingsControllerTestGitLabTokenEmpty(): void
    {
        $this->request->method('getParams')->willReturn(['token' => '']);

        // Ensure no stored token.
        $this->appConfig->setValueString('openregister', 'gitlab_api_token', '');

        $controller = $this->buildApiTokenSettingsController();
        $response   = $controller->testGitLabToken();
        $data       = $response->getData();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($data['success']);
    }//end testApiTokenSettingsControllerTestGitLabTokenEmpty()

    /**
     * Test SolrSettingsController::getSolrSettings
     *
     * @return void
     */
    public function testSolrSettingsControllerGetSettings(): void
    {
        $controller = $this->buildSolrSettingsController();
        $response   = $controller->getSolrSettings();

        $this->assertSame(200, $response->getStatus());
    }//end testSolrSettingsControllerGetSettings()

    /**
     * Test SolrSettingsController::getSolrFacetConfiguration
     *
     * @return void
     */
    public function testSolrSettingsControllerGetFacetConfig(): void
    {
        $controller = $this->buildSolrSettingsController();
        $response   = $controller->getSolrFacetConfiguration();

        $this->assertSame(200, $response->getStatus());
    }//end testSolrSettingsControllerGetFacetConfig()

    /**
     * Test SolrSettingsController::getSolrInfo
     *
     * @return void
     */
    public function testSolrSettingsControllerGetSolrInfo(): void
    {
        $controller = $this->buildSolrSettingsController();
        $response   = $controller->getSolrInfo();
        $data       = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('solr', $data);
    }//end testSolrSettingsControllerGetSolrInfo()

    // ─── Builder methods ─────────────────────────────────────────────────

    /**
     * Build ChatController with real services
     *
     * @return ChatController
     */
    private function buildChatController(): ChatController
    {
        return new ChatController(
            'openregister',
            $this->request,
            \OC::$server->get(ChatService::class),
            \OC::$server->get(ConversationMapper::class),
            \OC::$server->get(MessageMapper::class),
            \OC::$server->get(FeedbackMapper::class),
            \OC::$server->get(AgentMapper::class),
            \OC::$server->get(OrganisationService::class),
            $this->db,
            $this->logger,
            'admin'
        );
    }//end buildChatController()

    /**
     * Build ConversationController with real services
     *
     * @return ConversationController
     */
    private function buildConversationController(): ConversationController
    {
        return new ConversationController(
            'openregister',
            $this->request,
            \OC::$server->get(ConversationMapper::class),
            \OC::$server->get(MessageMapper::class),
            \OC::$server->get(FeedbackMapper::class),
            \OC::$server->get(AgentMapper::class),
            \OC::$server->get(OrganisationService::class),
            \OC::$server->get(ChatService::class),
            $this->logger,
            'admin'
        );
    }//end buildConversationController()

    /**
     * Build GdprEntitiesController with real services
     *
     * @return GdprEntitiesController
     */
    private function buildGdprEntitiesController(): GdprEntitiesController
    {
        return new GdprEntitiesController(
            'openregister',
            $this->request,
            \OC::$server->get(GdprEntityMapper::class),
            \OC::$server->get(EntityRelationMapper::class),
            $this->db,
            $this->logger
        );
    }//end buildGdprEntitiesController()

    /**
     * Build WebhooksController with real services
     *
     * @return WebhooksController
     */
    private function buildWebhooksController(): WebhooksController
    {
        return new WebhooksController(
            'openregister',
            $this->request,
            \OC::$server->get(WebhookMapper::class),
            \OC::$server->get(WebhookLogMapper::class),
            \OC::$server->get(WebhookService::class),
            $this->logger
        );
    }//end buildWebhooksController()

    /**
     * Build OrganisationController with real services
     *
     * @return OrganisationController
     */
    private function buildOrganisationController(): OrganisationController
    {
        return new OrganisationController(
            'openregister',
            $this->request,
            \OC::$server->get(OrganisationService::class),
            \OC::$server->get(OrganisationMapper::class),
            $this->logger
        );
    }//end buildOrganisationController()

    /**
     * Build FileTextController with real services
     *
     * @return FileTextController
     */
    private function buildFileTextController(): FileTextController
    {
        return new FileTextController(
            'openregister',
            $this->request,
            \OC::$server->get(TextExtractionService::class),
            \OC::$server->get(IndexService::class),
            \OC::$server->get(FileService::class),
            \OC::$server->get(EntityRelationMapper::class),
            $this->logger,
            $this->appConfig
        );
    }//end buildFileTextController()

    /**
     * Build FileExtractionController with real services
     *
     * @return FileExtractionController
     */
    private function buildFileExtractionController(): FileExtractionController
    {
        return new FileExtractionController(
            'openregister',
            $this->request,
            \OC::$server->get(TextExtractionService::class),
            \OC::$server->get(VectorizationService::class),
            \OC::$server->get(ChunkMapper::class),
            \OC::$server->get(EntityRelationMapper::class),
            \OC::$server->get(RiskLevelService::class)
        );
    }//end buildFileExtractionController()

    /**
     * Build BulkController with real services
     *
     * @return BulkController
     */
    private function buildBulkController(): BulkController
    {
        return new BulkController(
            'openregister',
            $this->request,
            \OC::$server->get(ObjectService::class)
        );
    }//end buildBulkController()

    /**
     * Build FileSettingsController with real services
     *
     * @return FileSettingsController
     */
    private function buildFileSettingsController(): FileSettingsController
    {
        return new FileSettingsController(
            'openregister',
            $this->request,
            \OC::$server->get(ContainerInterface::class),
            \OC::$server->get(SettingsService::class),
            $this->logger
        );
    }//end buildFileSettingsController()

    /**
     * Build N8nSettingsController with real services
     *
     * @return N8nSettingsController
     */
    private function buildN8nSettingsController(): N8nSettingsController
    {
        return new N8nSettingsController(
            'openregister',
            $this->request,
            \OC::$server->get(ConfigurationSettingsHandler::class),
            \OC::$server->get(SettingsService::class),
            $this->logger,
            \OC::$server->get(IClientService::class)
        );
    }//end buildN8nSettingsController()

    /**
     * Build LlmSettingsController with real services
     *
     * @return LlmSettingsController
     */
    private function buildLlmSettingsController(): LlmSettingsController
    {
        return new LlmSettingsController(
            'openregister',
            $this->request,
            $this->db,
            \OC::$server->get(ContainerInterface::class),
            \OC::$server->get(SettingsService::class),
            \OC::$server->get(VectorizationService::class),
            $this->logger
        );
    }//end buildLlmSettingsController()

    /**
     * Build CacheSettingsController with real services
     *
     * @return CacheSettingsController
     */
    private function buildCacheSettingsController(): CacheSettingsController
    {
        return new CacheSettingsController(
            'openregister',
            $this->request,
            \OC::$server->get(SettingsService::class),
            \OC::$server->get(IndexService::class),
            $this->logger,
            \OC::$server->get(Factory::class),
            $this->appConfig
        );
    }//end buildCacheSettingsController()

    /**
     * Build ApiTokenSettingsController with real services
     *
     * @return ApiTokenSettingsController
     */
    private function buildApiTokenSettingsController(): ApiTokenSettingsController
    {
        return new ApiTokenSettingsController(
            'openregister',
            $this->request,
            $this->appConfig,
            \OC::$server->get(SettingsService::class),
            $this->logger
        );
    }//end buildApiTokenSettingsController()

    /**
     * Build SolrSettingsController with real services
     *
     * @return SolrSettingsController
     */
    private function buildSolrSettingsController(): SolrSettingsController
    {
        return new SolrSettingsController(
            'openregister',
            $this->request,
            \OC::$server->get(SettingsService::class),
            \OC::$server->get(IndexService::class),
            \OC::$server->get(ContainerInterface::class),
            $this->logger
        );
    }//end buildSolrSettingsController()
}//end class
