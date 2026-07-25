<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Mcp\BuiltIn\FlowMcpToolProvider;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class FlowMcpToolProviderTest extends TestCase
{
    private FlowRunService $runner;
    private FlowRunMapper $mapper;
    private FlowMcpToolProvider $provider;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(FlowRunService::class);
        $this->mapper = $this->createMock(FlowRunMapper::class);
        $this->provider = new FlowMcpToolProvider($this->runner, $this->mapper);
    }

    public function testTheAppIdIsOpenregister(): void
    {
        $this->assertSame('openregister', $this->provider->getAppId());
    }

    /**
     * Every tool id must be namespaced with the app id, or McpToolsService
     * silently drops it.
     */
    public function testEveryToolIsNamespacedAndWellFormed(): void
    {
        foreach ($this->provider->getTools() as $tool) {
            $this->assertStringStartsWith('openregister.', $tool['id']);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testRunFlowQueuesARunAndReturnsItsUuid(): void
    {
        $run = new FlowRun();
        $run->setUuid('run-123');
        $run->setStatus(FlowRun::STATUS_QUEUED);

        $this->runner->expects($this->once())
            ->method('queue')
            ->with(
                $this->equalTo('f1'),
                $this->callback(fn ($s) => $s['uuid'] === 'u1'),
                $this->equalTo('mcp')
            )
            ->willReturn($run);

        $result = $this->provider->invokeTool('openregister.runFlow', [
            'flowId'   => 'f1',
            'uuid'     => 'u1',
            'register' => 'reg',
            'schema'   => 'sch',
        ]);

        $this->assertSame('run-123', $result['runUuid']);
        $this->assertTrue($result['queued']);
    }

    public function testRunFlowNeedsAFlowId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->provider->invokeTool('openregister.runFlow', []);
    }

    public function testFlowRunStatusReturnsTheRun(): void
    {
        $run = new FlowRun();
        $run->setUuid('run-9');
        $run->setStatus(FlowRun::STATUS_COMPLETED);
        $this->mapper->method('findByUuid')->with('run-9')->willReturn($run);

        $result = $this->provider->invokeTool('openregister.flowRunStatus', ['runUuid' => 'run-9']);

        $this->assertTrue($result['found']);
        $this->assertSame('completed', $result['status']);
    }

    public function testFlowRunStatusReturnsNotFoundRatherThanThrowing(): void
    {
        $this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));

        $result = $this->provider->invokeTool('openregister.flowRunStatus', ['runUuid' => 'ghost']);

        $this->assertFalse($result['found']);
    }

    public function testAnUnknownToolThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->provider->invokeTool('openregister.somethingElse', []);
    }
}
