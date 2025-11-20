<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Test\TestCase;

/**
 * Integration test for organisation-filtered conversation list
 *
 * Tests that conversations are correctly filtered by organisation,
 * ensuring users only see conversations from their active organisation.
 *
 * @group DB
 */
class OrganisationFilteredConversationListTest extends TestCase
{
    private ConversationMapper $conversationMapper;
    private AgentMapper $agentMapper;
    private IDBConnection $db;
    private array $testAgents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->conversationMapper = new ConversationMapper($this->db, \OC::$server->get(ITimeFactory::class));
        $this->agentMapper = new AgentMapper($this->db);

        // Create test agents for different organisations.
        for ($org = 1; $org <= 3; $org++) {
            $agent = new Agent();
            $agent->setUuid('test-agent-org' . $org . '-' . uniqid());
            $agent->setName('Agent Org ' . $org);
            $agent->setType('chat');
            $agent->setOwner('test-user');
            $agent->setOrganisation($org);
            $this->testAgents[$org] = $this->agentMapper->insert($agent);
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->testAgents as $agent) {
                $this->agentMapper->delete($agent);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors.
        }

        parent::tearDown();
    }

    public function testConversationsFilteredByOrganisation(): void
    {
        // Create conversations for different organisations.
        $conversations = [];
        
        // Organisation 1 - 3 conversations.
        for ($i = 1; $i <= 3; $i++) {
            $conv = new Conversation();
            $conv->setUuid('test-conv-org1-' . $i . '-' . uniqid());
            $conv->setTitle('Org 1 Conversation ' . $i);
            $conv->setUserId('test-user');
            $conv->setOrganisation(1);
            $conv->setAgentId($this->testAgents[1]->getId());
            $conversations[1][] = $this->conversationMapper->insert($conv);
        }

        // Organisation 2 - 2 conversations.
        for ($i = 1; $i <= 2; $i++) {
            $conv = new Conversation();
            $conv->setUuid('test-conv-org2-' . $i . '-' . uniqid());
            $conv->setTitle('Org 2 Conversation ' . $i);
            $conv->setUserId('test-user');
            $conv->setOrganisation(2);
            $conv->setAgentId($this->testAgents[2]->getId());
            $conversations[2][] = $this->conversationMapper->insert($conv);
        }

        // Find conversations by organisation.
        $org1Convs = $this->conversationMapper->findByUser('test-user', 1);
        $org2Convs = $this->conversationMapper->findByUser('test-user', 2);

        // Extract UUIDs.
        $org1Uuids = array_map(fn($c) => $c->getUuid(), $org1Convs);
        $org2Uuids = array_map(fn($c) => $c->getUuid(), $org2Convs);

        // Verify org 1 conversations.
        foreach ($conversations[1] as $conv) {
            $this->assertContains($conv->getUuid(), $org1Uuids);
            $this->assertNotContains($conv->getUuid(), $org2Uuids);
        }

        // Verify org 2 conversations.
        foreach ($conversations[2] as $conv) {
            $this->assertContains($conv->getUuid(), $org2Uuids);
            $this->assertNotContains($conv->getUuid(), $org1Uuids);
        }

        // Cleanup.
        foreach ($conversations as $orgConvs) {
            foreach ($orgConvs as $conv) {
                $this->conversationMapper->delete($conv);
            }
        }
    }

    public function testConversationCountByOrganisation(): void
    {
        // Create conversations.
        $org1Conv1 = new Conversation();
        $org1Conv1->setUuid('test-conv-count-org1-1-' . uniqid());
        $org1Conv1->setTitle('Count Org 1 Conv 1');
        $org1Conv1->setUserId('test-user-count');
        $org1Conv1->setOrganisation(1);
        $org1Conv1->setAgentId($this->testAgents[1]->getId());
        $org1Conv1 = $this->conversationMapper->insert($org1Conv1);

        $org1Conv2 = new Conversation();
        $org1Conv2->setUuid('test-conv-count-org1-2-' . uniqid());
        $org1Conv2->setTitle('Count Org 1 Conv 2');
        $org1Conv2->setUserId('test-user-count');
        $org1Conv2->setOrganisation(1);
        $org1Conv2->setAgentId($this->testAgents[1]->getId());
        $org1Conv2 = $this->conversationMapper->insert($org1Conv2);

        $org2Conv1 = new Conversation();
        $org2Conv1->setUuid('test-conv-count-org2-1-' . uniqid());
        $org2Conv1->setTitle('Count Org 2 Conv 1');
        $org2Conv1->setUserId('test-user-count');
        $org2Conv1->setOrganisation(2);
        $org2Conv1->setAgentId($this->testAgents[2]->getId());
        $org2Conv1 = $this->conversationMapper->insert($org2Conv1);

        // Count by organisation.
        $org1Count = $this->conversationMapper->countByUser('test-user-count', 1);
        $org2Count = $this->conversationMapper->countByUser('test-user-count', 2);

        $this->assertEquals(2, $org1Count);
        $this->assertEquals(1, $org2Count);

        // Cleanup.
        $this->conversationMapper->delete($org1Conv1);
        $this->conversationMapper->delete($org1Conv2);
        $this->conversationMapper->delete($org2Conv1);
    }

    public function testUserSwitchingOrganisations(): void
    {
        // User in org 1.
        $org1Conv = new Conversation();
        $org1Conv->setUuid('test-conv-switch-org1-' . uniqid());
        $org1Conv->setTitle('Org 1 Conv');
        $org1Conv->setUserId('switching-user');
        $org1Conv->setOrganisation(1);
        $org1Conv->setAgentId($this->testAgents[1]->getId());
        $org1Conv = $this->conversationMapper->insert($org1Conv);

        // User switches to org 2.
        $org2Conv = new Conversation();
        $org2Conv->setUuid('test-conv-switch-org2-' . uniqid());
        $org2Conv->setTitle('Org 2 Conv');
        $org2Conv->setUserId('switching-user');
        $org2Conv->setOrganisation(2);
        $org2Conv->setAgentId($this->testAgents[2]->getId());
        $org2Conv = $this->conversationMapper->insert($org2Conv);

        // When in org 1, should only see org 1 conversations.
        $inOrg1 = $this->conversationMapper->findByUser('switching-user', 1);
        $inOrg1Uuids = array_map(fn($c) => $c->getUuid(), $inOrg1);
        $this->assertContains($org1Conv->getUuid(), $inOrg1Uuids);
        $this->assertNotContains($org2Conv->getUuid(), $inOrg1Uuids);

        // When in org 2, should only see org 2 conversations.
        $inOrg2 = $this->conversationMapper->findByUser('switching-user', 2);
        $inOrg2Uuids = array_map(fn($c) => $c->getUuid(), $inOrg2);
        $this->assertContains($org2Conv->getUuid(), $inOrg2Uuids);
        $this->assertNotContains($org1Conv->getUuid(), $inOrg2Uuids);

        // Cleanup.
        $this->conversationMapper->delete($org1Conv);
        $this->conversationMapper->delete($org2Conv);
    }

    public function testPaginationWithOrganisationFilter(): void
    {
        $conversations = [];
        
        // Create 15 conversations in org 1.
        for ($i = 1; $i <= 15; $i++) {
            $conv = new Conversation();
            $conv->setUuid('test-conv-page-' . $i . '-' . uniqid());
            $conv->setTitle('Page Conv ' . $i);
            $conv->setUserId('page-user');
            $conv->setOrganisation(1);
            $conv->setAgentId($this->testAgents[1]->getId());
            $conversations[] = $this->conversationMapper->insert($conv);
            usleep(10000); // Small delay for ordering
        }

        // Get first page (10 items).
        $page1 = $this->conversationMapper->findByUser('page-user', 1, 10, 0);
        $this->assertCount(10, $page1);

        // Get second page (5 items).
        $page2 = $this->conversationMapper->findByUser('page-user', 1, 10, 10);
        $this->assertCount(5, $page2);

        // Verify no overlap.
        $page1Uuids = array_map(fn($c) => $c->getUuid(), $page1);
        $page2Uuids = array_map(fn($c) => $c->getUuid(), $page2);
        $this->assertEmpty(array_intersect($page1Uuids, $page2Uuids));

        // Cleanup.
        foreach ($conversations as $conv) {
            $this->conversationMapper->delete($conv);
        }
    }

    public function testMultipleUsersInSameOrganisation(): void
    {
        // User 1 conversations.
        $user1Conv = new Conversation();
        $user1Conv->setUuid('test-conv-multi-user1-' . uniqid());
        $user1Conv->setTitle('User 1 Conv');
        $user1Conv->setUserId('user1');
        $user1Conv->setOrganisation(1);
        $user1Conv->setAgentId($this->testAgents[1]->getId());
        $user1Conv = $this->conversationMapper->insert($user1Conv);

        // User 2 conversations.
        $user2Conv = new Conversation();
        $user2Conv->setUuid('test-conv-multi-user2-' . uniqid());
        $user2Conv->setTitle('User 2 Conv');
        $user2Conv->setUserId('user2');
        $user2Conv->setOrganisation(1);
        $user2Conv->setAgentId($this->testAgents[1]->getId());
        $user2Conv = $this->conversationMapper->insert($user2Conv);

        // Each user should only see their own conversations.
        $user1Convs = $this->conversationMapper->findByUser('user1', 1);
        $user1Uuids = array_map(fn($c) => $c->getUuid(), $user1Convs);
        $this->assertContains($user1Conv->getUuid(), $user1Uuids);
        $this->assertNotContains($user2Conv->getUuid(), $user1Uuids);

        $user2Convs = $this->conversationMapper->findByUser('user2', 1);
        $user2Uuids = array_map(fn($c) => $c->getUuid(), $user2Convs);
        $this->assertContains($user2Conv->getUuid(), $user2Uuids);
        $this->assertNotContains($user1Conv->getUuid(), $user2Uuids);

        // Cleanup.
        $this->conversationMapper->delete($user1Conv);
        $this->conversationMapper->delete($user2Conv);
    }

    public function testConversationWithNullOrganisation(): void
    {
        // Create conversation without organisation.
        $noOrgConv = new Conversation();
        $noOrgConv->setUuid('test-conv-no-org-' . uniqid());
        $noOrgConv->setTitle('No Org Conv');
        $noOrgConv->setUserId('test-user');
        $noOrgConv->setOrganisation(null);
        $noOrgConv->setAgentId($this->testAgents[1]->getId());
        $noOrgConv = $this->conversationMapper->insert($noOrgConv);

        // Should not appear in any organisation-filtered list.
        $org1Convs = $this->conversationMapper->findByUser('test-user', 1);
        $org1Uuids = array_map(fn($c) => $c->getUuid(), $org1Convs);
        $this->assertNotContains($noOrgConv->getUuid(), $org1Uuids);

        // Could potentially be retrieved with null organisation filter.
        // (implementation-dependent).

        // Cleanup.
        $this->conversationMapper->delete($noOrgConv);
    }

    public function testDeletedConversationsFilteredByOrganisation(): void
    {
        // Create and soft delete conversations in different orgs.
        $org1Conv = new Conversation();
        $org1Conv->setUuid('test-conv-del-org1-' . uniqid());
        $org1Conv->setTitle('Deleted Org 1');
        $org1Conv->setUserId('test-user-del');
        $org1Conv->setOrganisation(1);
        $org1Conv->setAgentId($this->testAgents[1]->getId());
        $org1Conv = $this->conversationMapper->insert($org1Conv);
        $this->conversationMapper->softDelete($org1Conv->getId());

        $org2Conv = new Conversation();
        $org2Conv->setUuid('test-conv-del-org2-' . uniqid());
        $org2Conv->setTitle('Deleted Org 2');
        $org2Conv->setUserId('test-user-del');
        $org2Conv->setOrganisation(2);
        $org2Conv->setAgentId($this->testAgents[2]->getId());
        $org2Conv = $this->conversationMapper->insert($org2Conv);
        $this->conversationMapper->softDelete($org2Conv->getId());

        // Get deleted conversations by organisation.
        $org1Deleted = $this->conversationMapper->findDeletedByUser('test-user-del', 1);
        $org2Deleted = $this->conversationMapper->findDeletedByUser('test-user-del', 2);

        $org1DeletedUuids = array_map(fn($c) => $c->getUuid(), $org1Deleted);
        $org2DeletedUuids = array_map(fn($c) => $c->getUuid(), $org2Deleted);

        $this->assertContains($org1Conv->getUuid(), $org1DeletedUuids);
        $this->assertNotContains($org2Conv->getUuid(), $org1DeletedUuids);

        $this->assertContains($org2Conv->getUuid(), $org2DeletedUuids);
        $this->assertNotContains($org1Conv->getUuid(), $org2DeletedUuids);

        // Cleanup.
        $org1Found = $this->conversationMapper->find($org1Conv->getId());
        $org2Found = $this->conversationMapper->find($org2Conv->getId());
        $this->conversationMapper->delete($org1Found);
        $this->conversationMapper->delete($org2Found);
    }
}

