<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowRunController;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowRunControllerTest extends TestCase
{
    private IRequest&MockObject $request;

    private FlowRunMapper&MockObject $mapper;

    private FlowRunService&MockObject $runner;

    private FlowResolverRegistry&MockObject $resolvers;

    private FlowRunController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request   = $this->createMock(IRequest::class);
        $this->mapper    = $this->createMock(FlowRunMapper::class);
        $this->runner    = $this->createMock(FlowRunService::class);
        $this->resolvers = $this->createMock(FlowResolverRegistry::class);

        // FlowRunController gained an IUserSession dependency (it attributes a
        // test/retry run to the caller); the constructor call here was never
        // updated, so every test in this class died with an ArgumentCountError
        // before reaching its assertions.
        $this->controller = new FlowRunController(
            'openregister',
            $this->request,
            $this->mapper,
            $this->runner,
            $this->resolvers,
            $this->createMock(IUserSession::class)
        );
    }

    /** Map a params array onto the request mock's getParam(name, default). */
    private function params(array $values): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $values[$name] ?? $default
        );
    }

    public function testTestWithoutAFlowIdIsABadRequest(): void
    {
        $this->params([]);
        $res = $this->controller->test();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    public function testTestWithAnUnknownFlowIsNotFound(): void
    {
        $this->params(['flowId' => 'ghost']);
        $this->resolvers->method('resolveFlow')->willReturn(null);

        $res = $this->controller->test();
        $this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
    }

    public function testTestRunsSynchronouslyAndReturnsTheResult(): void
    {
        $this->params([
            'flowId'  => 'f1',
            'startAt' => 'middle',
            'pins'    => ['first' => [['json' => ['x' => 1]]]],
        ]);
        $this->resolvers->method('resolveFlow')->with('f1')->willReturn(['id' => 'f1', 'edges' => []]);

        $queued = new FlowRun();
        $queued->setStatus(FlowRun::STATUS_QUEUED);
        $this->runner->method('queue')->willReturn($queued);

        $done = new FlowRun();
        $done->setStatus(FlowRun::STATUS_COMPLETED);
        $done->setLog([['transition' => 'second', 'status' => 'completed']]);

        // The controller must pass the parsed startAt through to execute().
        $this->runner->expects($this->once())->method('execute')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'middle'
            )
            ->willReturn($done);

        $res  = $this->controller->test();
        $body = $res->getData();

        $this->assertSame(Http::STATUS_OK, $res->getStatus());
        $this->assertSame(FlowRun::STATUS_COMPLETED, $body['status']);
    }

    public function testTestPassesPinsOnTheRunContext(): void
    {
        $pins = ['first' => [['json' => ['pinned' => true]]]];
        $this->params(['flowId' => 'f1', 'pins' => $pins]);
        $this->resolvers->method('resolveFlow')->willReturn(['id' => 'f1']);

        // queue() must receive the pins on the context so the engine can read them.
        $this->runner->expects($this->once())->method('queue')
            ->with(
                'f1',
                $this->anything(),
                'test',
                ['pins' => $pins]
            )
            ->willReturn(new FlowRun());
        $done = new FlowRun();
        $done->setStatus(FlowRun::STATUS_COMPLETED);
        $this->runner->method('execute')->willReturn($done);

        $this->controller->test();
    }
}
