<?php

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration test for agent RBAC (organisation + private + invited users)
 *
 * Tests that agents correctly enforce role-based access control including:
 * - Organisation-based filtering
 * - Private agent access restrictions
 * - Invited user access
 * - Group-based access control
 *
 * @group DB
 */
class AgentRbacTest extends TestCase
{
    private AgentMapper $agentMapper;
    private IDBConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = \OC::$server->getDatabaseConnection();
        $this->agentMapper = new AgentMapper($this->db);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testPublicAgentAccessibleByAllInOrganisation(): void
    {
        $publicAgent = new Agent();
        $publicAgent->setUuid('test-agent-public-' . uniqid());
        $publicAgent->setName('Public Agent');
        $publicAgent->setType('chat');
        $publicAgent->setOwner('user1');
        $publicAgent->setOrganisation(1);
        $publicAgent->setIsPrivate(false); // Public agent
        $publicAgent = $this->agentMapper->insert($publicAgent);

        // Any user in the same organisation should be able to access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($publicAgent, 'user1'));
        $this->assertTrue($this->agentMapper->canUserAccessAgent($publicAgent, 'user2'));
        $this->assertTrue($this->agentMapper->canUserAccessAgent($publicAgent, 'user3'));

        // Cleanup
        $this->agentMapper->delete($publicAgent);
    }

    public function testPrivateAgentOnlyAccessibleByOwner(): void
    {
        $privateAgent = new Agent();
        $privateAgent->setUuid('test-agent-private-' . uniqid());
        $privateAgent->setName('Private Agent');
        $privateAgent->setType('chat');
        $privateAgent->setOwner('user1');
        $privateAgent->setOrganisation(1);
        $privateAgent->setIsPrivate(true); // Private agent
        $privateAgent->setInvitedUsers([]); // No invited users
        $privateAgent = $this->agentMapper->insert($privateAgent);

        // Only owner should have access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($privateAgent, 'user1'));
        $this->assertFalse($this->agentMapper->canUserAccessAgent($privateAgent, 'user2'));
        $this->assertFalse($this->agentMapper->canUserAccessAgent($privateAgent, 'user3'));

        // Cleanup
        $this->agentMapper->delete($privateAgent);
    }

    public function testPrivateAgentAccessibleByInvitedUsers(): void
    {
        $privateAgent = new Agent();
        $privateAgent->setUuid('test-agent-invited-' . uniqid());
        $privateAgent->setName('Private Agent with Invites');
        $privateAgent->setType('chat');
        $privateAgent->setOwner('user1');
        $privateAgent->setOrganisation(1);
        $privateAgent->setIsPrivate(true);
        $privateAgent->setInvitedUsers(['user2', 'user3']); // Invited users
        $privateAgent = $this->agentMapper->insert($privateAgent);

        // Owner and invited users should have access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($privateAgent, 'user1'));
        $this->assertTrue($this->agentMapper->canUserAccessAgent($privateAgent, 'user2'));
        $this->assertTrue($this->agentMapper->canUserAccessAgent($privateAgent, 'user3'));
        
        // Other users should not have access
        $this->assertFalse($this->agentMapper->canUserAccessAgent($privateAgent, 'user4'));

        // Cleanup
        $this->agentMapper->delete($privateAgent);
    }

    public function testAddInvitedUserToPrivateAgent(): void
    {
        $privateAgent = new Agent();
        $privateAgent->setUuid('test-agent-add-invited-' . uniqid());
        $privateAgent->setName('Private Agent');
        $privateAgent->setType('chat');
        $privateAgent->setOwner('user1');
        $privateAgent->setOrganisation(1);
        $privateAgent->setIsPrivate(true);
        $privateAgent->setInvitedUsers([]);
        $privateAgent = $this->agentMapper->insert($privateAgent);

        // Initially user2 has no access
        $this->assertFalse($this->agentMapper->canUserAccessAgent($privateAgent, 'user2'));

        // Add user2 to invited users
        $privateAgent->addInvitedUser('user2');
        $updated = $this->agentMapper->update($privateAgent);

        // Now user2 should have access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($updated, 'user2'));

        // Cleanup
        $this->agentMapper->delete($updated);
    }

    public function testRemoveInvitedUserFromPrivateAgent(): void
    {
        $privateAgent = new Agent();
        $privateAgent->setUuid('test-agent-remove-invited-' . uniqid());
        $privateAgent->setName('Private Agent');
        $privateAgent->setType('chat');
        $privateAgent->setOwner('user1');
        $privateAgent->setOrganisation(1);
        $privateAgent->setIsPrivate(true);
        $privateAgent->setInvitedUsers(['user2', 'user3']);
        $privateAgent = $this->agentMapper->insert($privateAgent);

        // Initially user2 has access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($privateAgent, 'user2'));

        // Remove user2 from invited users
        $privateAgent->removeInvitedUser('user2');
        $updated = $this->agentMapper->update($privateAgent);

        // Now user2 should not have access
        $this->assertFalse($this->agentMapper->canUserAccessAgent($updated, 'user2'));
        
        // user3 should still have access
        $this->assertTrue($this->agentMapper->canUserAccessAgent($updated, 'user3'));

        // Cleanup
        $this->agentMapper->delete($updated);
    }

    public function testOnlyOwnerCanModifyAgent(): void
    {
        $agent = new Agent();
        $agent->setUuid('test-agent-modify-' . uniqid());
        $agent->setName('Agent');
        $agent->setType('chat');
        $agent->setOwner('user1');
        $agent->setOrganisation(1);
        $agent->setIsPrivate(true);
        $agent->setInvitedUsers(['user2']);
        $agent = $this->agentMapper->insert($agent);

        // Only owner can modify
        $this->assertTrue($this->agentMapper->canUserModifyAgent($agent, 'user1'));
        $this->assertFalse($this->agentMapper->canUserModifyAgent($agent, 'user2')); // Even if invited
        $this->assertFalse($this->agentMapper->canUserModifyAgent($agent, 'user3'));

        // Cleanup
        $this->agentMapper->delete($agent);
    }

    public function testOrganisationBasedFiltering(): void
    {
        // Create agents in different organisations
        $agent1 = new Agent();
        $agent1->setUuid('test-agent-org1-' . uniqid());
        $agent1->setName('Agent Org 1');
        $agent1->setType('chat');
        $agent1->setOwner('user1');
        $agent1->setOrganisation(1);
        $agent1->setIsPrivate(false);
        $agent1 = $this->agentMapper->insert($agent1);

        $agent2 = new Agent();
        $agent2->setUuid('test-agent-org2-' . uniqid());
        $agent2->setName('Agent Org 2');
        $agent2->setType('chat');
        $agent2->setOwner('user1');
        $agent2->setOrganisation(2);
        $agent2->setIsPrivate(false);
        $agent2 = $this->agentMapper->insert($agent2);

        // Find agents by organisation
        $org1Agents = $this->agentMapper->findByOrganisation(1, 'user1');
        $org2Agents = $this->agentMapper->findByOrganisation(2, 'user1');

        $org1Uuids = array_map(fn($a) => $a->getUuid(), $org1Agents);
        $org2Uuids = array_map(fn($a) => $a->getUuid(), $org2Agents);

        $this->assertContains($agent1->getUuid(), $org1Uuids);
        $this->assertNotContains($agent2->getUuid(), $org1Uuids);

        $this->assertContains($agent2->getUuid(), $org2Uuids);
        $this->assertNotContains($agent1->getUuid(), $org2Uuids);

        // Cleanup
        $this->agentMapper->delete($agent1);
        $this->agentMapper->delete($agent2);
    }

    public function testGroupBasedAccessControl(): void
    {
        $agent = new Agent();
        $agent->setUuid('test-agent-groups-' . uniqid());
        $agent->setName('Group Restricted Agent');
        $agent->setType('chat');
        $agent->setOwner('user1');
        $agent->setOrganisation(1);
        $agent->setIsPrivate(false);
        $agent->setGroups(['group1', 'group2']); // Only these groups have access
        $agent = $this->agentMapper->insert($agent);

        // Note: Group membership checking would be done in the controller/service layer
        // The mapper just stores the group restrictions
        $this->assertCount(2, $agent->getGroups());
        $this->assertContains('group1', $agent->getGroups());
        $this->assertContains('group2', $agent->getGroups());

        // Cleanup
        $this->agentMapper->delete($agent);
    }

    public function testPrivateAgentNotReturnedInPublicListing(): void
    {
        // Create a public and a private agent
        $publicAgent = new Agent();
        $publicAgent->setUuid('test-agent-public-list-' . uniqid());
        $publicAgent->setName('Public Agent');
        $publicAgent->setType('chat');
        $publicAgent->setOwner('user1');
        $publicAgent->setOrganisation(1);
        $publicAgent->setIsPrivate(false);
        $publicAgent = $this->agentMapper->insert($publicAgent);

        $privateAgent = new Agent();
        $privateAgent->setUuid('test-agent-private-list-' . uniqid());
        $privateAgent->setName('Private Agent');
        $privateAgent->setType('chat');
        $privateAgent->setOwner('user1');
        $privateAgent->setOrganisation(1);
        $privateAgent->setIsPrivate(true);
        $privateAgent->setInvitedUsers([]);
        $privateAgent = $this->agentMapper->insert($privateAgent);

        // Get agents for organisation as user2 (not owner, not invited)
        $agentsForUser2 = $this->agentMapper->findByOrganisation(1, 'user2');
        $uuids = array_map(fn($a) => $a->getUuid(), $agentsForUser2);

        // Should include public agent
        $this->assertContains($publicAgent->getUuid(), $uuids);
        
        // Should NOT include private agent for non-owner
        $this->assertNotContains($privateAgent->getUuid(), $uuids);

        // Get agents as owner (user1)
        $agentsForUser1 = $this->agentMapper->findByOrganisation(1, 'user1');
        $uuids1 = array_map(fn($a) => $a->getUuid(), $agentsForUser1);

        // Should include both for owner
        $this->assertContains($publicAgent->getUuid(), $uuids1);
        $this->assertContains($privateAgent->getUuid(), $uuids1);

        // Cleanup
        $this->agentMapper->delete($publicAgent);
        $this->agentMapper->delete($privateAgent);
    }

    public function testAgentRbacWithNullOrganisation(): void
    {
        // Agent without organisation (should still work with RBAC)
        $agent = new Agent();
        $agent->setUuid('test-agent-no-org-' . uniqid());
        $agent->setName('No Org Agent');
        $agent->setType('chat');
        $agent->setOwner('user1');
        $agent->setOrganisation(null);
        $agent->setIsPrivate(true);
        $agent->setInvitedUsers(['user2']);
        $agent = $this->agentMapper->insert($agent);

        // RBAC should still work
        $this->assertTrue($this->agentMapper->canUserAccessAgent($agent, 'user1'));
        $this->assertTrue($this->agentMapper->canUserAccessAgent($agent, 'user2'));
        $this->assertFalse($this->agentMapper->canUserAccessAgent($agent, 'user3'));

        // Cleanup
        $this->agentMapper->delete($agent);
    }

    public function testHasInvitedUserHelper(): void
    {
        $agent = new Agent();
        $agent->setUuid('test-agent-has-invited-' . uniqid());
        $agent->setName('Agent');
        $agent->setType('chat');
        $agent->setOwner('user1');
        $agent->setOrganisation(1);
        $agent->setInvitedUsers(['user2', 'user3']);
        $agent = $this->agentMapper->insert($agent);

        $this->assertTrue($agent->hasInvitedUser('user2'));
        $this->assertTrue($agent->hasInvitedUser('user3'));
        $this->assertFalse($agent->hasInvitedUser('user4'));

        // Cleanup
        $this->agentMapper->delete($agent);
    }
}

