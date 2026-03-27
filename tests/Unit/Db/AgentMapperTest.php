<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AgentMapper
 *
 * Tests the non-DB logic in AgentMapper, including:
 * - Construction with mocked dependencies
 * - canUserAccessAgent() access control logic
 * - canUserModifyAgent() modification permission logic
 */
class AgentMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IEventDispatcher&MockObject $eventDispatcher;
    private AgentMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->mapper = new AgentMapper(
            $this->db,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->eventDispatcher
        );
    }

    /**
     * Helper to create an Agent entity with specific property values.
     *
     * Uses real Agent instances because Nextcloud Entity uses magic __call
     * for getters/setters, which cannot be mocked with createMock().
     *
     * @param bool|null   $isPrivate    Whether the agent is private
     * @param string|null $owner        Owner user ID
     * @param array|null  $invitedUsers Array of invited user IDs
     *
     * @return Agent
     */
    private function createAgent(?bool $isPrivate = null, ?string $owner = null, ?array $invitedUsers = null): Agent
    {
        $agent = new Agent();
        $agent->setIsPrivate($isPrivate);
        $agent->setOwner($owner);
        $agent->setInvitedUsers($invitedUsers);
        return $agent;
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructorCreatesInstance(): void
    {
        $this->assertInstanceOf(AgentMapper::class, $this->mapper);
    }

    public function testGetTableNameReturnsExpectedSuffix(): void
    {
        $this->assertStringContainsString('openregister_agents', $this->mapper->getTableName());
    }

    // -------------------------------------------------------------------------
    // canUserAccessAgent — pure logic, no DB
    // -------------------------------------------------------------------------

    public function testCanUserAccessAgentNonPrivateReturnsTrue(): void
    {
        $agent = $this->createAgent(false, 'some-owner');
        $this->assertTrue($this->mapper->canUserAccessAgent($agent, 'anyuser'));
    }

    public function testCanUserAccessAgentNullPrivateReturnsTrue(): void
    {
        $agent = $this->createAgent(null, 'some-owner');
        $this->assertTrue($this->mapper->canUserAccessAgent($agent, 'anyuser'));
    }

    public function testCanUserAccessAgentPrivateOwnerReturnsTrue(): void
    {
        $agent = $this->createAgent(true, 'owner-user');
        $this->assertTrue($this->mapper->canUserAccessAgent($agent, 'owner-user'));
    }

    public function testCanUserAccessAgentPrivateInvitedUserReturnsTrue(): void
    {
        $agent = $this->createAgent(true, 'owner-user', ['invited-user', 'another-user']);
        $this->assertTrue($this->mapper->canUserAccessAgent($agent, 'invited-user'));
    }

    public function testCanUserAccessAgentPrivateNonOwnerNonInvitedReturnsFalse(): void
    {
        $agent = $this->createAgent(true, 'owner-user', ['invited-user']);
        $this->assertFalse($this->mapper->canUserAccessAgent($agent, 'stranger'));
    }

    public function testCanUserAccessAgentPrivateNoInvitedUsersReturnsFalse(): void
    {
        $agent = $this->createAgent(true, 'owner-user', null);
        $this->assertFalse($this->mapper->canUserAccessAgent($agent, 'stranger'));
    }

    public function testCanUserAccessAgentPrivateEmptyInvitedUsersReturnsFalse(): void
    {
        $agent = $this->createAgent(true, 'owner-user', []);
        $this->assertFalse($this->mapper->canUserAccessAgent($agent, 'stranger'));
    }

    // -------------------------------------------------------------------------
    // canUserModifyAgent — pure logic, no DB
    // -------------------------------------------------------------------------

    public function testCanUserModifyAgentOwnerReturnsTrue(): void
    {
        $agent = $this->createAgent(false, 'owner-user');
        $this->assertTrue($this->mapper->canUserModifyAgent($agent, 'owner-user'));
    }

    public function testCanUserModifyAgentNonOwnerReturnsFalse(): void
    {
        $agent = $this->createAgent(false, 'owner-user');
        $this->assertFalse($this->mapper->canUserModifyAgent($agent, 'other-user'));
    }

    public function testCanUserModifyAgentNullOwnerReturnsFalseForAnyUser(): void
    {
        $agent = $this->createAgent(false, null);
        $this->assertFalse($this->mapper->canUserModifyAgent($agent, 'any-user'));
    }
}
