<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;
use DateTime;

/**
 * Integration test for soft delete and restore of conversations
 *
 * @group DB
 */
class ConversationSoftDeleteTest extends TestCase
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

        // Create test agent
        $this->testAgent = new Agent();
        $this->testAgent->setUuid('test-agent-del-' . uniqid());
        $this->testAgent->setName('Test Agent');
        $this->testAgent->setType('chat');
        $this->testAgent->setOwner('test-user');
        $this->testAgent->setOrganisation(1);
        $this->testAgent = $this->agentMapper->insert($this->testAgent);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->testAgent)) {
                $this->agentMapper->delete($this->testAgent);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    public function testSoftDelete(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-soft-del-' . uniqid());
        $conversation->setTitle('To Soft Delete');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);
        $this->assertNull($created->getDeletedAt());

        // Soft delete
        $softDeleted = $this->conversationMapper->softDelete($created->getId());

        $this->assertNotNull($softDeleted->getDeletedAt());
        $this->assertInstanceOf(DateTime::class, $softDeleted->getDeletedAt());

        // Should still exist in database
        $found = $this->conversationMapper->find($created->getId());
        $this->assertNotNull($found->getDeletedAt());

        // Cleanup
        $this->conversationMapper->delete($softDeleted);
    }

    public function testSoftDeleteExcludesFromNormalQueries(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-excl-' . uniqid());
        $conversation->setTitle('Excluded After Delete');
        $conversation->setUserId('test-user-excl');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Count before soft delete
        $beforeCount = $this->conversationMapper->countByUser('test-user-excl', 1, false);

        // Soft delete
        $this->conversationMapper->softDelete($created->getId());

        // Count after soft delete (excluding deleted)
        $afterCount = $this->conversationMapper->countByUser('test-user-excl', 1, false);

        $this->assertEquals($beforeCount - 1, $afterCount);

        // Cleanup
        $found = $this->conversationMapper->find($created->getId());
        $this->conversationMapper->delete($found);
    }

    public function testFindDeletedConversations(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-find-del-' . uniqid());
        $conversation->setTitle('Find Deleted');
        $conversation->setUserId('test-user-del');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Soft delete
        $this->conversationMapper->softDelete($created->getId());

        // Find deleted conversations
        $deleted = $this->conversationMapper->findDeletedByUser('test-user-del', 1);

        $uuids = array_map(fn($c) => $c->getUuid(), $deleted);
        $this->assertContains($created->getUuid(), $uuids);

        // All should have deletedAt set
        foreach ($deleted as $conv) {
            $this->assertNotNull($conv->getDeletedAt());
        }

        // Cleanup
        $found = $this->conversationMapper->find($created->getId());
        $this->conversationMapper->delete($found);
    }

    public function testRestoreConversation(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-restore-' . uniqid());
        $conversation->setTitle('To Restore');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Soft delete
        $softDeleted = $this->conversationMapper->softDelete($created->getId());
        $this->assertNotNull($softDeleted->getDeletedAt());

        // Restore
        $restored = $this->conversationMapper->restore($created->getId());

        $this->assertNull($restored->getDeletedAt());
        $this->assertEquals($created->getUuid(), $restored->getUuid());
        $this->assertEquals('To Restore', $restored->getTitle());

        // Cleanup
        $this->conversationMapper->delete($restored);
    }

    public function testRestoredConversationAppearsInNormalQueries(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-restored-' . uniqid());
        $conversation->setTitle('Restored Conversation');
        $conversation->setUserId('test-user-restored');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Soft delete
        $this->conversationMapper->softDelete($created->getId());

        // Count while deleted
        $deletedCount = $this->conversationMapper->countByUser('test-user-restored', 1, false);

        // Restore
        $this->conversationMapper->restore($created->getId());

        // Count after restore
        $restoredCount = $this->conversationMapper->countByUser('test-user-restored', 1, false);

        $this->assertEquals($deletedCount + 1, $restoredCount);

        // Cleanup
        $found = $this->conversationMapper->find($created->getId());
        $this->conversationMapper->delete($found);
    }

    public function testHardDeleteRemovesPermanently(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-hard-del-' . uniqid());
        $conversation->setTitle('Hard Delete');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);
        $id = $created->getId();

        // Soft delete first
        $this->conversationMapper->softDelete($id);

        // Hard delete
        $found = $this->conversationMapper->find($id);
        $this->conversationMapper->delete($found);

        // Verify completely removed
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->conversationMapper->find($id);
    }

    public function testCleanupOldDeletedConversations(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-cleanup-' . uniqid());
        $conversation->setTitle('Old Deleted');
        $conversation->setUserId('test-user');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Soft delete
        $softDeleted = $this->conversationMapper->softDelete($created->getId());

        // Manually set deletedAt to 31 days ago
        $oldDate = new DateTime();
        $oldDate->modify('-31 days');
        $softDeleted->setDeletedAt($oldDate);
        $this->conversationMapper->update($softDeleted);

        // Cleanup old deleted (older than 30 days)
        $deleted = $this->conversationMapper->cleanupOldDeleted(30);

        $this->assertGreaterThanOrEqual(1, $deleted);

        // Verify it's gone
        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->conversationMapper->find($created->getId());
    }

    public function testIncludeDeletedParameter(): void
    {
        $conversation = new Conversation();
        $conversation->setUuid('test-conv-include-' . uniqid());
        $conversation->setTitle('Include Test');
        $conversation->setUserId('test-user-include');
        $conversation->setOrganisation(1);
        $conversation->setAgentId($this->testAgent->getId());

        $created = $this->conversationMapper->insert($conversation);

        // Soft delete
        $this->conversationMapper->softDelete($created->getId());

        // Find without including deleted
        $withoutDeleted = $this->conversationMapper->findByUser('test-user-include', 1, false);
        $uuidsWithout = array_map(fn($c) => $c->getUuid(), $withoutDeleted);
        $this->assertNotContains($created->getUuid(), $uuidsWithout);

        // Find including deleted
        $withDeleted = $this->conversationMapper->findByUser('test-user-include', 1, true);
        $uuidsWith = array_map(fn($c) => $c->getUuid(), $withDeleted);
        $this->assertContains($created->getUuid(), $uuidsWith);

        // Cleanup
        $found = $this->conversationMapper->find($created->getId());
        $this->conversationMapper->delete($found);
    }
}

