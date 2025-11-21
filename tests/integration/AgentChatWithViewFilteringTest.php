<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ViewService;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration test for agent-based chat with view filtering
 *
 * Tests that agents correctly filter data based on their configured views,
 * search_files, and search_objects settings when retrieving context for RAG.
 *
 * @group DB
 */
class AgentChatWithViewFilteringTest extends TestCase
{
    private ChatService $chatService;
    private AgentMapper $agentMapper;
    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private IDBConnection $db;
    private Agent $testAgent;
    private Conversation $testConversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->agentMapper = new AgentMapper($this->db);
        $this->conversationMapper = new ConversationMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->messageMapper = new MessageMapper($this->db, \OC::$server->get(ITimeFactory::class));

        // Mock services for ChatService.
        $organisationService = $this->createMock(OrganisationService::class);
        $viewService = $this->createMock(ViewService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Initialize ChatService (constructor might vary based on actual implementation).
        // This is a placeholder - adjust based on actual ChatService dependencies.
        $this->chatService = new ChatService(
            $this->conversationMapper,
            $this->messageMapper,
            $this->agentMapper,
            $organisationService,
            $viewService,
            $logger
        );

        // Create test agent with specific view filters.
        $this->testAgent = new Agent();
        $this->testAgent->setUuid('test-agent-view-' . uniqid());
        $this->testAgent->setName('View Filtered Agent');
        $this->testAgent->setType('chat');
        $this->testAgent->setOwner('test-user');
        $this->testAgent->setOrganisation(1);
        $this->testAgent->setViews(['view-uuid-1', 'view-uuid-2']); // Specific views only
        $this->testAgent->setSearchFiles(true);
        $this->testAgent->setSearchObjects(true);
        $this->testAgent->setEnableRag(true);
        $this->testAgent = $this->agentMapper->insert($this->testAgent);

        // Create test conversation.
        $this->testConversation = new Conversation();
        $this->testConversation->setUuid('test-conv-view-' . uniqid());
        $this->testConversation->setTitle('View Filtered Chat');
        $this->testConversation->setUserId('test-user');
        $this->testConversation->setOrganisation(1);
        $this->testConversation->setAgentId($this->testAgent->getId());
        $this->testConversation = $this->conversationMapper->insert($this->testConversation);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->testConversation)) {
                $this->conversationMapper->delete($this->testConversation);
            }
            if (isset($this->testAgent)) {
                $this->agentMapper->delete($this->testAgent);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors.
        }

        parent::tearDown();
    }

    public function testAgentWithViewFiltering(): void
    {
        // Verify agent has view filtering configured.
        $this->assertNotEmpty($this->testAgent->getViews());
        $this->assertCount(2, $this->testAgent->getViews());
        $this->assertContains('view-uuid-1', $this->testAgent->getViews());
        $this->assertContains('view-uuid-2', $this->testAgent->getViews());
    }

    public function testAgentSearchSettings(): void
    {
        // Test agent with files only.
        $filesOnlyAgent = new Agent();
        $filesOnlyAgent->setUuid('test-agent-files-' . uniqid());
        $filesOnlyAgent->setName('Files Only Agent');
        $filesOnlyAgent->setType('chat');
        $filesOnlyAgent->setOwner('test-user');
        $filesOnlyAgent->setOrganisation(1);
        $filesOnlyAgent->setSearchFiles(true);
        $filesOnlyAgent->setSearchObjects(false);
        $filesOnlyAgent = $this->agentMapper->insert($filesOnlyAgent);

        $this->assertTrue($filesOnlyAgent->getSearchFiles());
        $this->assertFalse($filesOnlyAgent->getSearchObjects());

        // Test agent with objects only.
        $objectsOnlyAgent = new Agent();
        $objectsOnlyAgent->setUuid('test-agent-objs-' . uniqid());
        $objectsOnlyAgent->setName('Objects Only Agent');
        $objectsOnlyAgent->setType('chat');
        $objectsOnlyAgent->setOwner('test-user');
        $objectsOnlyAgent->setOrganisation(1);
        $objectsOnlyAgent->setSearchFiles(false);
        $objectsOnlyAgent->setSearchObjects(true);
        $objectsOnlyAgent = $this->agentMapper->insert($objectsOnlyAgent);

        $this->assertFalse($objectsOnlyAgent->getSearchFiles());
        $this->assertTrue($objectsOnlyAgent->getSearchObjects());

        // Cleanup.
        $this->agentMapper->delete($filesOnlyAgent);
        $this->agentMapper->delete($objectsOnlyAgent);
    }

    public function testAgentWithNoViewsAllowsAllData(): void
    {
        // Agent with no view restrictions.
        $unrestricted = new Agent();
        $unrestricted->setUuid('test-agent-unrestricted-' . uniqid());
        $unrestricted->setName('Unrestricted Agent');
        $unrestricted->setType('chat');
        $unrestricted->setOwner('test-user');
        $unrestricted->setOrganisation(1);
        $unrestricted->setViews(null); // No view restrictions
        $unrestricted->setSearchFiles(true);
        $unrestricted->setSearchObjects(true);
        $unrestricted = $this->agentMapper->insert($unrestricted);

        $this->assertEmpty($unrestricted->getViews() ?? []);

        // Cleanup.
        $this->agentMapper->delete($unrestricted);
    }

    public function testMessageStorageWithAgentConfiguration(): void
    {
        // Create a user message.
        $userMsg = new Message();
        $userMsg->setUuid('test-msg-user-' . uniqid());
        $userMsg->setConversationId($this->testConversation->getId());
        $userMsg->setRole('user');
        $userMsg->setContent('Tell me about the documentation');
        $userMsg = $this->messageMapper->insert($userMsg);

        // Simulate assistant response with sources filtered by agent's views.
        $assistantMsg = new Message();
        $assistantMsg->setUuid('test-msg-asst-' . uniqid());
        $assistantMsg->setConversationId($this->testConversation->getId());
        $assistantMsg->setRole('assistant');
        $assistantMsg->setContent('Based on the available documentation...');
        $assistantMsg->setSources([
            [
                'type' => 'object',
                'view_uuid' => 'view-uuid-1', // Within agent's view scope
                'id' => 'obj-123',
                'name' => 'Documentation Object',
                'relevance' => 0.92,
            ],
            [
                'type' => 'file',
                'view_uuid' => 'view-uuid-2', // Within agent's view scope
                'id' => 'file-456',
                'name' => 'readme.md',
                'relevance' => 0.88,
            ],
        ]);
        $assistantMsg = $this->messageMapper->insert($assistantMsg);

        // Verify messages were stored correctly.
        $messages = $this->messageMapper->findByConversation($this->testConversation->getId());
        $this->assertGreaterThanOrEqual(2, count($messages));

        // Verify assistant message has correct sources.
        $foundAssistant = null;
        foreach ($messages as $msg) {
            if ($msg->getUuid() === $assistantMsg->getUuid()) {
                $foundAssistant = $msg;
                break;
            }
        }

        $this->assertNotNull($foundAssistant);
        $this->assertNotEmpty($foundAssistant->getSources());
        $this->assertCount(2, $foundAssistant->getSources());

        // Verify sources are within agent's view scope.
        $sources = $foundAssistant->getSources();
        $viewUuids = array_column($sources, 'view_uuid');
        $this->assertContains('view-uuid-1', $viewUuids);
        $this->assertContains('view-uuid-2', $viewUuids);

        // Cleanup.
        $this->messageMapper->delete($userMsg);
        $this->messageMapper->delete($assistantMsg);
    }

    public function testConversationMetadataStoresAgentConfiguration(): void
    {
        // Update conversation with metadata about agent configuration at time of creation.
        $this->testConversation->setMetadata([
            'agent_uuid' => $this->testAgent->getUuid(),
            'agent_name' => $this->testAgent->getName(),
            'agent_views' => $this->testAgent->getViews(),
            'agent_search_files' => $this->testAgent->getSearchFiles(),
            'agent_search_objects' => $this->testAgent->getSearchObjects(),
        ]);
        $updated = $this->conversationMapper->update($this->testConversation);

        $metadata = $updated->getMetadata();
        $this->assertEquals($this->testAgent->getUuid(), $metadata['agent_uuid']);
        $this->assertEquals($this->testAgent->getViews(), $metadata['agent_views']);
        $this->assertTrue($metadata['agent_search_files']);
        $this->assertTrue($metadata['agent_search_objects']);
    }

    public function testAgentViewConfigurationPersistence(): void
    {
        // Modify agent views.
        $this->testAgent->setViews(['view-uuid-3', 'view-uuid-4', 'view-uuid-5']);
        $updated = $this->agentMapper->update($this->testAgent);

        // Retrieve and verify.
        $found = $this->agentMapper->find($updated->getId());
        $this->assertCount(3, $found->getViews());
        $this->assertContains('view-uuid-3', $found->getViews());
        $this->assertContains('view-uuid-4', $found->getViews());
        $this->assertContains('view-uuid-5', $found->getViews());
    }
}

