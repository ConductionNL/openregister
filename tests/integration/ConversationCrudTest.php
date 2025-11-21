<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;

/**
 * Integration test for Conversation CRUD operations
 *
 * @group DB
 */
class ConversationCrudTest extends TestCase
{
    private ConversationMapper $conversationMapper;
    private AgentMapper $agentMapper;
    private IDBConnection $db;
    private Agent $testAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->conversationMapper = new ConversationMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->agentMapper = new AgentMapper($this->db);

        // Create a test agent.
        $this->testAgent = new Agent();
        $this->testAgent->setUuid('test-agent-' . uniqid());
        $this->testAgent->setName('Test Agent');
        $this->testAgent->setType('chat');
        $this->testAgent->setOwner('test-user');
        $this->testAgent->setOrganisation(1);
        $this->testAgent = $this->agentMapper->insert($this->testAgent);
    }

    protected function tearDown(): void
    {
        // Clean up test data.
        try {
            if (isset($this->testAgent)) {
                $this->agentMapper->delete($this->testAgent);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors.
        }

        parent::tearDown();
    }

    public function testCreateConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-' . uniqid());
        $conversation->setTitle('Test Conversation');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());
        $conversation->setMetadata(['test' => 'data']);

        $created = $this->conversationMapper->insert($conversation);

        $this->assertNotNull($created->getId());
        $this->assertEquals('Test Conversation', $created->getTitle());
        $this->assertEquals('test-user', $created->getUserId());
        $this->assertEquals(1, $created->getOrganisation());
        $this->assertEquals($this->testAgent->getId(), $created->getAgentId());
        $this->assertNull($created->getDeletedAt());

        // Cleanup.
        $this->conversationMapper->delete($created);
    }

    public function testReadConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-read-' . uniqid());
        $conversation->setTitle('Test Read');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Read by ID.
        $found = $this->conversationMapper->find($created->getId());
        $this->assertEquals($created->getUuid(), $found->getUuid());
        $this->assertEquals('Test Read', $found->getTitle());

        // Read by UUID.
        $foundByUuid = $this->conversationMapper->findByUuid($created->getUuid());
        $this->assertEquals($created->getId(), $foundByUuid->getId());

        // Cleanup.
        $this->conversationMapper->delete($created);
    }

    public function testUpdateConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-update-' . uniqid());
        $conversation->setTitle('Original Title');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Update.
        $created->setTitle('Updated Title');
        $created->setMetadata(['updated' => true]);
        $updated = $this->conversationMapper->update($created);

        $this->assertEquals('Updated Title', $updated->getTitle());
        $this->assertEquals(['updated' => true], $updated->getMetadata());

        // Cleanup.
        $this->conversationMapper->delete($updated);
    }

    public function testDeleteConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-delete-' . uniqid());
        $conversation->setTitle('To Delete');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);
        $id = $created->getId();

        // Delete.
        $this->conversationMapper->delete($created);

        // Verify deleted.
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->conversationMapper->find($id);
    }

    public function testFindByUserAndOrganisation(): void
    {
        $conv1 = new Conversation();
        $conv1->setUuid('test-conv-find-1-' . uniqid());
        $conv1->setTitle('Conversation 1');
        $conv1->setUserId('test-user');
        $conv1->setOrganisation(1);
        $conv1->setAgentId($this->testAgent->getId());

        $conv2 = new Conversation();
        $conv2->setUuid('test-conv-find-2-' . uniqid());
        $conv2->setTitle('Conversation 2');
        $conv2->setUserId('test-user');
        $conv2->setOrganisation(1);
        $conv2->setAgentId($this->testAgent->getId());

        $created1 = $this->conversationMapper->insert($conv1);
        $created2 = $this->conversationMapper->insert($conv2);

        // Find conversations.
        $found = $this->conversationMapper->findByUser('test-user', 1);

        $this->assertGreaterThanOrEqual(2, count($found));

        $uuids = array_map(fn($c) => $c->getUuid(), $found);
        $this->assertContains($created1->getUuid(), $uuids);
        $this->assertContains($created2->getUuid(), $uuids);

        // Cleanup.
        $this->conversationMapper->delete($created1);
        $this->conversationMapper->delete($created2);
    }

    public function testCountByUserAndOrganisation(): void
    {
        $initialCount = $this->conversationMapper->countByUser('test-user-count', 1);

        $conversation = new Conversation();
        $conversation->setUuid('test-conv-count-' . uniqid());
        $conversation->setTitle('Count Test');
        $conversation->setUserId('test-user-count');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        $newCount = $this->conversationMapper->countByUser('test-user-count', 1);
        $this->assertEquals($initialCount + 1, $newCount);

        // Cleanup.
        $this->conversationMapper->delete($created);
    }
}

