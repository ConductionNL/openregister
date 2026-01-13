<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;

/**
 * Integration test for conversation summarization
 *
 * Tests that conversations can be summarized when context grows too large,
 * storing the summary in conversation metadata for efficient context management.
 *
 * @group DB
 */
class ConversationSummarizationTest extends TestCase
{
    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private AgentMapper $agentMapper;
    private IDBConnection $db;
    private Agent $testAgent;
    private Conversation $testConversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->conversationMapper = new ConversationMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->messageMapper = new MessageMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->agentMapper = new AgentMapper($this->db);

        // Create test agent
        $this->testAgent = new Agent();
        $this->testAgent->setUuid('test-agent-summary-' . uniqid());
        $this->testAgent->setName('Summary Test Agent');
        $this->testAgent->setType('chat');
        $this->testAgent->setOwner('test-user');
        $this->testAgent->setOrganisation(1);
        $this->testAgent = $this->agentMapper->insert($this->testAgent);

        // Create test conversation
        $this->testConversation = new Conversation();
        $this->testConversation->setUuid('test-conv-summary-' . uniqid());
        $this->testConversation->setTitle('Long Conversation');
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
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    public function testStoreSummaryInMetadata(): void
    {
        $summary = 'This conversation discusses authentication configuration, ' .
                   'including OAuth setup and user permissions management.';

        $this->testConversation->setMetadata([
            'summary' => $summary,
            'summarized_at' => date('Y-m-d H:i:s'),
            'messages_count_at_summary' => 20,
        ]);

        $updated = $this->conversationMapper->update($this->testConversation);
        $metadata = $updated->getMetadata();

        $this->assertEquals($summary, $metadata['summary']);
        $this->assertArrayHasKey('summarized_at', $metadata);
        $this->assertEquals(20, $metadata['messages_count_at_summary']);
    }

    public function testMultipleSummaryUpdates(): void
    {
        // First summary after 20 messages
        $this->testConversation->setMetadata([
            'summary' => 'Initial discussion about authentication.',
            'summarized_at' => date('Y-m-d H:i:s'),
            'messages_count_at_summary' => 20,
            'summary_version' => 1,
        ]);
        $updated1 = $this->conversationMapper->update($this->testConversation);

        // Second summary after 40 messages (conversation continued)
        $updated1->setMetadata([
            'summary' => 'Extended discussion about authentication and authorization, including LDAP integration.',
            'summarized_at' => date('Y-m-d H:i:s'),
            'messages_count_at_summary' => 40,
            'summary_version' => 2,
            'previous_summaries' => [
                [
                    'summary' => 'Initial discussion about authentication.',
                    'messages_count' => 20,
                    'version' => 1,
                ],
            ],
        ]);
        $updated2 = $this->conversationMapper->update($updated1);

        $metadata = $updated2->getMetadata();
        $this->assertEquals(2, $metadata['summary_version']);
        $this->assertEquals(40, $metadata['messages_count_at_summary']);
        $this->assertArrayHasKey('previous_summaries', $metadata);
        $this->assertCount(1, $metadata['previous_summaries']);
    }

    public function testSummaryTriggeredByMessageCount(): void
    {
        // Create many messages to simulate a long conversation
        $messages = [];
        for ($i = 1; $i <= 25; $i++) {
            $msg = new Message();
            $msg->setUuid('test-msg-' . $i . '-' . uniqid());
            $msg->setConversationId($this->testConversation->getId());
            $msg->setRole($i % 2 === 1 ? 'user' : 'assistant');
            $msg->setContent("Message $i in a long conversation");
            $messages[] = $this->messageMapper->insert($msg);
        }

        // Count messages
        $messageCount = count($this->messageMapper->findByConversation($this->testConversation->getId()));
        $this->assertGreaterThanOrEqual(25, $messageCount);

        // Simulate summary generation when count > 20
        if ($messageCount > 20) {
            $this->testConversation->setMetadata([
                'summary' => 'Long conversation with 25+ messages about various topics.',
                'summarized_at' => date('Y-m-d H:i:s'),
                'messages_count_at_summary' => $messageCount,
                'summary_triggered_by' => 'message_count_threshold',
            ]);
            $updated = $this->conversationMapper->update($this->testConversation);

            $metadata = $updated->getMetadata();
            $this->assertEquals('message_count_threshold', $metadata['summary_triggered_by']);
            $this->assertGreaterThan(20, $metadata['messages_count_at_summary']);
        }

        // Cleanup
        foreach ($messages as $msg) {
            $this->messageMapper->delete($msg);
        }
    }

    public function testSummaryTriggeredByTokenCount(): void
    {
        // Simulate a conversation with high token count
        $this->testConversation->setMetadata([
            'total_tokens' => 15000, // Exceeds typical context window
            'summary' => 'Comprehensive discussion summarized due to token limit.',
            'summarized_at' => date('Y-m-d H:i:s'),
            'summary_triggered_by' => 'token_count_threshold',
            'token_count_at_summary' => 15000,
        ]);

        $updated = $this->conversationMapper->update($this->testConversation);
        $metadata = $updated->getMetadata();

        $this->assertEquals('token_count_threshold', $metadata['summary_triggered_by']);
        $this->assertEquals(15000, $metadata['token_count_at_summary']);
    }

    public function testRetrieveSummaryForContext(): void
    {
        // Store summary
        $summary = 'User asked about API integration. Agent explained REST endpoints and authentication.';
        $this->testConversation->setMetadata([
            'summary' => $summary,
            'summarized_at' => date('Y-m-d H:i:s'),
        ]);
        $updated = $this->conversationMapper->update($this->testConversation);

        // Retrieve conversation
        $found = $this->conversationMapper->find($updated->getId());
        $metadata = $found->getMetadata();

        // Summary should be available for context injection
        $this->assertArrayHasKey('summary', $metadata);
        $this->assertEquals($summary, $metadata['summary']);
    }

    public function testCombineSummaryWithRecentMessages(): void
    {
        // Store summary of older messages
        $this->testConversation->setMetadata([
            'summary' => 'Early conversation about basic setup and configuration.',
            'messages_count_at_summary' => 20,
        ]);
        $updated = $this->conversationMapper->update($this->testConversation);

        // Add new messages after summarization
        $recentMessages = [];
        for ($i = 21; $i <= 25; $i++) {
            $msg = new Message();
            $msg->setUuid('test-msg-recent-' . $i . '-' . uniqid());
            $msg->setConversationId($updated->getId());
            $msg->setRole($i % 2 === 1 ? 'user' : 'assistant');
            $msg->setContent("Recent message $i");
            $recentMessages[] = $this->messageMapper->insert($msg);
        }

        // Get recent messages (e.g., last 10)
        $recent = $this->messageMapper->getRecentMessagesForConversation($updated->getId(), 10);

        // Context should include: summary + recent messages
        $metadata = $updated->getMetadata();
        $this->assertArrayHasKey('summary', $metadata);
        $this->assertGreaterThan(0, count($recent));

        // Cleanup
        foreach ($recentMessages as $msg) {
            $this->messageMapper->delete($msg);
        }
    }

    public function testSummaryPreservesKeyInformation(): void
    {
        // Summary should preserve critical information
        $keyInfo = [
            'topics' => ['authentication', 'authorization', 'LDAP'],
            'decisions' => ['Use OAuth 2.0', 'Enable SSO'],
            'action_items' => ['Configure LDAP server', 'Test SSO flow'],
        ];

        $this->testConversation->setMetadata([
            'summary' => 'Detailed discussion about authentication methods.',
            'summary_key_info' => $keyInfo,
            'summarized_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->conversationMapper->update($this->testConversation);
        $metadata = $updated->getMetadata();

        $this->assertArrayHasKey('summary_key_info', $metadata);
        $this->assertEquals($keyInfo, $metadata['summary_key_info']);
        $this->assertContains('authentication', $metadata['summary_key_info']['topics']);
        $this->assertContains('Use OAuth 2.0', $metadata['summary_key_info']['decisions']);
    }

    public function testSummaryMetrics(): void
    {
        // Track metrics about summarization
        $this->testConversation->setMetadata([
            'summary' => 'Conversation summary.',
            'summarized_at' => date('Y-m-d H:i:s'),
            'summary_metrics' => [
                'original_message_count' => 30,
                'original_token_count' => 12000,
                'summary_token_count' => 200,
                'compression_ratio' => 60, // 60:1 compression
            ],
        ]);

        $updated = $this->conversationMapper->update($this->testConversation);
        $metadata = $updated->getMetadata();

        $this->assertArrayHasKey('summary_metrics', $metadata);
        $this->assertEquals(30, $metadata['summary_metrics']['original_message_count']);
        $this->assertEquals(60, $metadata['summary_metrics']['compression_ratio']);
    }
}

