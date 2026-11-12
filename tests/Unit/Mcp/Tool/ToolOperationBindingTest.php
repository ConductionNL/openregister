<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Tool\AgentTool;
use OCA\OpenRegister\Mcp\Tool\ToolCallTrail;
use OCA\OpenRegister\Mcp\Tool\ApplicationTool;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins how the model's arguments reach the create and update functions of
 * the agent and application tools: by position through executeFunction(),
 * by name or position through a snake_case call, with a (string) 'null'
 * falling back to the default.
 */
class ToolOperationBindingTest extends TestCase
{

    /**
     * The agents the mapper was asked to write.
     *
     * @var array<int, Agent>
     */
    private array $agents = [];

    /**
     * The applications the mapper was asked to write.
     *
     * @var array<int, Application>
     */
    private array $applications = [];

    public function testCreateAgentBindsExecuteFunctionParametersByPosition(): void
    {
        $result = $this->agentTool()->executeFunction(
            'create_agent',
            ['name' => 'Bot', 'description' => 'Helps', 'type' => 'chat', 'systemPrompt' => 'Be kind']
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['Bot', 'Helps', 'chat', 'Be kind'], $this->agentFields(agent: $this->agents[0]));
    }

    public function testCreateAgentBindsASnakeCaseCallByName(): void
    {
        $json = $this->agentTool()->create_agent(name: 'Bot', systemPrompt: 'Be kind');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame(['Bot', null, null, 'Be kind'], $this->agentFields(agent: $this->agents[0]));
    }

    public function testUpdateAgentTreatsAStringNullAsTheDefault(): void
    {
        $json = $this->agentTool()->update_agent('a-1', 'null', 'New description');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame(['old name', 'New description', null, 'old prompt'], $this->agentFields(agent: $this->agents[0]));
    }

    public function testCreateApplicationBindsExecuteFunctionParametersByPosition(): void
    {
        $result = $this->applicationTool()->executeFunction(
            'create_application',
            ['name' => 'Zaken', 'description' => 'Cases', 'domain' => 'example.com']
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Zaken', $this->applications[0]->getName());
        $this->assertSame('Cases', $this->applications[0]->getDescription());
    }

    public function testUpdateApplicationBindsASnakeCaseCallByPosition(): void
    {
        $json = $this->applicationTool()->update_application('a-1', 'New name');

        $this->assertTrue(json_decode($json, true)['success']);
        $this->assertSame('New name', $this->applications[0]->getName());
        $this->assertSame('old description', $this->applications[0]->getDescription());
    }

    /**
     * The name, description, type and prompt of a written agent.
     *
     * @param Agent $agent The agent.
     *
     * @return array<int, string|null>
     */
    private function agentFields(Agent $agent): array
    {
        return [$agent->getName(), $agent->getDescription(), $agent->getType(), $agent->getPrompt()];
    }

    private function agentTool(): AgentTool
    {
        $mapper = $this->createMock(AgentMapper::class);
        $store  = function (Agent $agent): Agent {
            $this->agents[] = clone $agent;
            return $agent;
        };
        $mapper->method('insert')->willReturnCallback($store);
        $mapper->method('update')->willReturnCallback($store);
        $mapper->method('findByUuid')->willReturnCallback(
            function (): Agent {
                $agent = new Agent();
                $agent->setName('old name');
                $agent->setDescription('old description');
                $agent->setPrompt('old prompt');
                return $agent;
            }
        );

        return new AgentTool(new ToolCallTrail($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)), $mapper);
    }

    private function applicationTool(): ApplicationTool
    {
        $mapper = $this->createMock(ApplicationMapperInterface::class);
        $store  = function (Application $application): Application {
            $this->applications[] = clone $application;
            return $application;
        };
        $mapper->method('insert')->willReturnCallback($store);
        $mapper->method('update')->willReturnCallback($store);
        $mapper->method('findByUuid')->willReturnCallback(
            function (): Application {
                $application = new Application();
                $application->setName('old name');
                $application->setDescription('old description');
                return $application;
            }
        );

        return new ApplicationTool(new ToolCallTrail($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)), $mapper);
    }
}
