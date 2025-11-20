<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;

/**
 * Integration test for Message operations within conversations
 *
 * @group DB
 */
class MessageOperationsTest extends TestCase
{
    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private AgentMapper $agentMapper;
    private IDBConnection $db;
    private Conversation $testConversation;
    private Agent $testAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->conversationMapper = new ConversationMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->messageMapper = new MessageMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->agentMapper = new AgentMapper($this->db);

        // Create test agent.
        $this->testAgent = new Agent();
        $this->testAgent->setUuid('test-agent-msg-' . uniqid());
        $this->testAgent->setName('Test Agent');
        $this->testAgent->setType('chat');
        $this->testAgent->setOwner('test-user');
        $this->testAgent->setOrganisation(1);
        $this->testAgent = $this->agentMapper->insert($this->testAgent);

        // Create test conversation.
        $this->testConversation = new Conversation();
        $this->testConversation->setUuid('test-conv-msg-' . uniqid());
        $this->testConversation->setTitle('Test Messages');
        $this->testConversation->setUserId('test-user');
        $this->testConversation->setOrganisation(1);
        $this->testConversation->setAgentId($this->testAgent->getId());
        $this->testConversation = $this->conversationMapper->insert($this->testConversation);
    }

    protected function tearDown(): void
    {
        // Clean up test data.
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

    public function testCreateMessage(): void
    {
        $message = new Message();
        $message->setUuid('test-msg-' . uniqid());
        $message->setConversationId($this->testConversation->getId());
        $message->setRole('user');
        $message->setContent('Hello, how can you help?');
        $message->setSources([]);

        $created = $this->messageMapper->insert($message);

        $this->assertNotNull($created->getId());
        $this->assertEquals('user', $created->getRole());
        $this->assertEquals('Hello, how can you help?', $created->getContent());
        $this->assertEquals($this->testConversation->getId(), $created->getConversationId());

        // Cleanup.
        $this->messageMapper->delete($created);
    }

    public function testCreateAssistantMessageWithSources(): void
    {
        $sources = [
            [
                'type' => 'file',
                'id' => 'file-123',
                'name' => 'documentation.pdf',
                'relevance' => 0.95,
            ],
            [
                'type' => 'object',
                'id' => 'obj-456',
                'name' => 'User Guide',
                'relevance' => 0.87,
            ],
        ];

        $message = new Message();
        $message->setUuid('test-msg-asst-' . uniqid());
        $message->setConversationId($this->testConversation->getId());
        $message->setRole('assistant');
        $message->setContent('Based on the documentation, here is how...');
        $message->setSources($sources);

        $created = $this->messageMapper->insert($message);

        $this->assertEquals('assistant', $created->getRole());
        $this->assertEquals($sources, $created->getSources());
        $this->assertCount(2, $created->getSources());

        // Cleanup.
        $this->messageMapper->delete($created);
    }

    public function testFindMessagesByConversation(): void
    {
        // Create multiple messages.
        $msg1 = new Message();
        $msg1->setUuid('test-msg-find-1-' . uniqid());
        $msg1->setConversationId($this->testConversation->getId());
        $msg1->setRole('user');
        $msg1->setContent('First message');

        $msg2 = new Message();
        $msg2->setUuid('test-msg-find-2-' . uniqid());
        $msg2->setConversationId($this->testConversation->getId());
        $msg2->setRole('assistant');
        $msg2->setContent('Response to first message');

        $msg3 = new Message();
        $msg3->setUuid('test-msg-find-3-' . uniqid());
        $msg3->setConversationId($this->testConversation->getId());
        $msg3->setRole('user');
        $msg3->setContent('Follow-up question');

        $created1 = $this->messageMapper->insert($msg1);
        $created2 = $this->messageMapper->insert($msg2);
        $created3 = $this->messageMapper->insert($msg3);

        // Find all messages.
        $messages = $this->messageMapper->findByConversation($this->testConversation->getId());

        $this->assertGreaterThanOrEqual(3, count($messages));

        $uuids = array_map(fn($m) => $m->getUuid(), $messages);
        $this->assertContains($created1->getUuid(), $uuids);
        $this->assertContains($created2->getUuid(), $uuids);
        $this->assertContains($created3->getUuid(), $uuids);

        // Verify order (should be chronological).
        $contents = array_map(fn($m) => $m->getContent(), $messages);
        $firstIdx = array_search('First message', $contents);
        $responseIdx = array_search('Response to first message', $contents);
        $followUpIdx = array_search('Follow-up question', $contents);

        $this->assertLessThan($responseIdx, $firstIdx);
        $this->assertLessThan($followUpIdx, $responseIdx);

        // Cleanup.
        $this->messageMapper->delete($created1);
        $this->messageMapper->delete($created2);
        $this->messageMapper->delete($created3);
    }

    public function testGetRecentMessages(): void
    {
        // Create 5 messages.
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $msg = new Message();
            $msg->setUuid('test-msg-recent-' . $i . '-' . uniqid());
            $msg->setConversationId($this->testConversation->getId());
            $msg->setRole($i % 2 === 1 ? 'user' : 'assistant');
            $msg->setContent("Message $i");
            $messages[] = $this->messageMapper->insert($msg);
            usleep(10000); // Small delay to ensure different timestamps
        }

        // Get recent 3 messages.
        $recent = $this->messageMapper->getRecentMessagesForConversation(
            $this->testConversation->getId(),
            3
        );

        $this->assertCount(3, $recent);
        
        // Should be the last 3 messages.
        $contents = array_map(fn($m) => $m->getContent(), $recent);
        $this->assertContains('Message 3', $contents);
        $this->assertContains('Message 4', $contents);
        $this->assertContains('Message 5', $contents);

        // Cleanup.
        foreach ($messages as $msg) {
            $this->messageMapper->delete($msg);
        }
    }

    public function testDeleteMessagesByConversation(): void
    {
        // Create messages.
        $msg1 = new Message();
        $msg1->setUuid('test-msg-del-1-' . uniqid());
        $msg1->setConversationId($this->testConversation->getId());
        $msg1->setRole('user');
        $msg1->setContent('To delete');

        $msg2 = new Message();
        $msg2->setUuid('test-msg-del-2-' . uniqid());
        $msg2->setConversationId($this->testConversation->getId());
        $msg2->setRole('assistant');
        $msg2->setContent('Also to delete');

        $created1 = $this->messageMapper->insert($msg1);
        $created2 = $this->messageMapper->insert($msg2);

        // Delete all messages for conversation.
        $this->messageMapper->deleteByConversation($this->testConversation->getId());

        // Verify deleted.
        $remaining = $this->messageMapper->findByConversation($this->testConversation->getId());
        $this->assertCount(0, $remaining);
    }

    public function testMessageRoles(): void
    {
        $roles = ['user', 'assistant', 'system'];

        $createdMessages = [];
        foreach ($roles as $role) {
            $msg = new Message();
            $msg->setUuid('test-msg-role-' . $role . '-' . uniqid());
            $msg->setConversationId($this->testConversation->getId());
            $msg->setRole($role);
            $msg->setContent("Message from $role");
            $createdMessages[] = $this->messageMapper->insert($msg);
        }

        $found = $this->messageMapper->findByConversation($this->testConversation->getId());
        $foundRoles = array_map(fn($m) => $m->getRole(), $found);

        foreach ($roles as $role) {
            $this->assertContains($role, $foundRoles);
        }

        // Cleanup.
        foreach ($createdMessages as $msg) {
            $this->messageMapper->delete($msg);
        }
    }
}

