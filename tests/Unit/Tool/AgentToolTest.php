<?php

namespace Unit\Tool;

use BadMethodCallException;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Tool\AgentTool;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private AgentMapper&MockObject $agentMapper;
    private AgentTool $tool;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->agentMapper = $this->createMock(AgentMapper::class);

        $this->tool = new AgentTool(
            $this->agentMapper,
            $this->userSession,
            $this->logger
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function createAgentEntity(
        string $uuid,
        string $name,
        ?string $description = null,
        ?string $type = null,
        ?string $owner = null
    ): Agent {
        $entity = new Agent();
        $entity->setUuid($uuid);
        $entity->setName($name);
        if ($description !== null) {
            $entity->setDescription($description);
        }
        if ($type !== null) {
            $entity->setType($type);
        }
        if ($owner !== null) {
            $entity->setOwner($owner);
        }
        return $entity;
    }

    // ------------------------------------------------------------------
    // getName / getDescription / getFunctions
    // ------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('Agent Management', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('agent', strtolower($this->tool->getDescription()));
    }

    public function testGetFunctionsContainsAllCrud(): void
    {
        $functions = $this->tool->getFunctions();
        $names     = array_column($functions, 'name');
        $this->assertContains('list_agents', $names);
        $this->assertContains('get_agent', $names);
        $this->assertContains('create_agent', $names);
        $this->assertContains('update_agent', $names);
        $this->assertContains('delete_agent', $names);
        $this->assertCount(5, $functions);
    }

    public function testGetFunctionsStructure(): void
    {
        foreach ($this->tool->getFunctions() as $fn) {
            $this->assertArrayHasKey('name', $fn);
            $this->assertArrayHasKey('description', $fn);
            $this->assertArrayHasKey('parameters', $fn);
            $this->assertArrayHasKey('properties', $fn['parameters']);
            $this->assertArrayHasKey('required', $fn['parameters']);
        }
    }

    // ------------------------------------------------------------------
    // executeFunction
    // ------------------------------------------------------------------

    public function testExecuteFunctionCallsCorrectMethod(): void
    {
        $this->agentMapper->method('findAll')->willReturn([]);
        $this->agentMapper->method('count')->willReturn(0);

        $result = $this->tool->executeFunction('list_agents', []);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionUnknownMethodThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->tool->executeFunction('non_existent', []);
    }

    // ------------------------------------------------------------------
    // listAgents
    // ------------------------------------------------------------------

    public function testListAgentsSuccess(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Bot', 'A bot', 'assistant');
        $this->agentMapper->method('findAll')->willReturn([$agent]);
        $this->agentMapper->method('count')->willReturn(1);

        $result = $this->tool->listAgents();
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertCount(1, $result['data']['agents']);
        $this->assertSame(50, $result['data']['limit']);
        $this->assertSame(0, $result['data']['offset']);
    }

    public function testListAgentsWithPagination(): void
    {
        $this->agentMapper->expects($this->once())
            ->method('findAll')
            ->with(10, 5)
            ->willReturn([]);
        $this->agentMapper->method('count')->willReturn(0);

        $result = $this->tool->listAgents(10, 5);
        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['data']['limit']);
        $this->assertSame(5, $result['data']['offset']);
    }

    public function testListAgentsEmpty(): void
    {
        $this->agentMapper->method('findAll')->willReturn([]);
        $this->agentMapper->method('count')->willReturn(0);

        $result = $this->tool->listAgents();
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total']);
    }

    public function testListAgentsException(): void
    {
        $this->agentMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->listAgents();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // getAgent
    // ------------------------------------------------------------------

    public function testGetAgentSuccess(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Bot', 'Desc', 'assistant');
        $this->agentMapper->method('findByUuid')->willReturn($agent);

        $result = $this->tool->getAgent('uuid-1');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bot', $result['message']);
    }

    public function testGetAgentNotFound(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->getAgent('bad-uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testGetAgentGenericException(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->getAgent('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // createAgent
    // ------------------------------------------------------------------

    public function testCreateAgentSuccess(): void
    {
        $agent = $this->createAgentEntity('new-uuid', 'NewBot', 'Desc', 'chat');

        $this->agentMapper->expects($this->once())
            ->method('insert')
            ->willReturn($agent);

        $result = $this->tool->createAgent('NewBot', 'Desc', 'chat', 'You are a bot');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('NewBot', $result['message']);
        $this->assertStringContainsString('new-uuid', $result['message']);
    }

    public function testCreateAgentMinimalParams(): void
    {
        $agent = $this->createAgentEntity('uuid', 'SimpleBot');

        $this->agentMapper->method('insert')->willReturn($agent);

        $result = $this->tool->createAgent('SimpleBot');
        $this->assertTrue($result['success']);
    }

    public function testCreateAgentWithEmptyStrings(): void
    {
        $agent = $this->createAgentEntity('uuid', 'Bot');
        $this->agentMapper->method('insert')->willReturn($agent);

        $result = $this->tool->createAgent('Bot', '', '', '');
        $this->assertTrue($result['success']);
    }

    public function testCreateAgentSetsOwnerFromAgentContext(): void
    {
        $contextAgent = $this->createAgentEntity('ctx-uuid', 'Context', null, null, 'agent-owner');
        $this->tool->setAgent($contextAgent);

        $insertedAgent = $this->createAgentEntity('uuid', 'Bot');
        $this->agentMapper->method('insert')->willReturn($insertedAgent);

        $result = $this->tool->createAgent('Bot');
        $this->assertTrue($result['success']);
    }

    public function testCreateAgentException(): void
    {
        $this->agentMapper->method('insert')
            ->willThrowException(new \Exception('Constraint violation'));

        $result = $this->tool->createAgent('Bad');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Constraint violation', $result['error']);
    }

    // ------------------------------------------------------------------
    // updateAgent
    // ------------------------------------------------------------------

    public function testUpdateAgentAllFields(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Old', 'Old desc', 'old-type');

        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->agentMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateAgent('uuid-1', 'New', 'New desc', 'New prompt');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated', $result['message']);
    }

    public function testUpdateAgentNoFields(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Name', 'Desc', 'type');

        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->agentMapper->method('update')->willReturnCallback(function ($entity) {
            return $entity;
        });

        $result = $this->tool->updateAgent('uuid-1');
        $this->assertTrue($result['success']);
    }

    public function testUpdateAgentNotFound(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->updateAgent('bad-uuid', 'x');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testUpdateAgentGenericException(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->tool->updateAgent('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB error', $result['error']);
    }

    // ------------------------------------------------------------------
    // deleteAgent
    // ------------------------------------------------------------------

    public function testDeleteAgentSuccess(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Bot');
        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->agentMapper->expects($this->once())->method('delete');

        $result = $this->tool->deleteAgent('uuid-1');
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['uuid']);
        $this->assertStringContainsString('Bot', $result['message']);
    }

    public function testDeleteAgentNotFound(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->tool->deleteAgent('bad-uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testDeleteAgentGenericException(): void
    {
        $this->agentMapper->method('findByUuid')
            ->willThrowException(new \Exception('FK constraint'));

        $result = $this->tool->deleteAgent('uuid');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('FK constraint', $result['error']);
    }

    // ------------------------------------------------------------------
    // executeFunction routing
    // ------------------------------------------------------------------

    public function testExecuteFunctionGetAgent(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Bot', 'Desc', 'type');
        $this->agentMapper->method('findByUuid')->willReturn($agent);

        $result = $this->tool->executeFunction('get_agent', ['uuid-1']);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionCreateAgent(): void
    {
        $agent = $this->createAgentEntity('uuid', 'NewBot');
        $this->agentMapper->method('insert')->willReturn($agent);

        $result = $this->tool->executeFunction('create_agent', ['NewBot']);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionDeleteAgent(): void
    {
        $agent = $this->createAgentEntity('uuid-1', 'Bot');
        $this->agentMapper->method('findByUuid')->willReturn($agent);

        $result = $this->tool->executeFunction('delete_agent', ['uuid-1']);
        $this->assertTrue($result['success']);
    }
}
