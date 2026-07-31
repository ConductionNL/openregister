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
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\OrganisationService;
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

    private OrganisationService&MockObject $organisations;

    private FlowRunController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request       = $this->createMock(IRequest::class);
        $this->mapper        = $this->createMock(FlowRunMapper::class);
        $this->runner        = $this->createMock(FlowRunService::class);
        $this->resolvers     = $this->createMock(FlowResolverRegistry::class);
        $this->organisations = $this->createMock(OrganisationService::class);

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
            $this->createMock(IUserSession::class),
            $this->organisations
        );
    }

    /** Make getActiveOrganisation() answer with an organisation of this uuid. */
    private function activeOrganisation(?string $uuid): void
    {
        if ($uuid === null) {
            $this->organisations->method('getActiveOrganisation')->willReturn(null);
            return;
        }

        $organisation = new Organisation();
        $organisation->setUuid($uuid);
        $this->organisations->method('getActiveOrganisation')->willReturn($organisation);
    }

    /** Map a params array onto the request mock's getParam(name, default). */
    private function params(array $values): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name, $default = null) => $values[$name] ?? $default
        );
    }

    public function testActiveWithNoResolvableOrganisationReturnsNothing(): void
    {
        $this->params([]);
        $this->activeOrganisation(null);

        // The mapper must not be consulted at all: an unscoped read here would
        // put every tenant's runs on the caller's dashboard.
        $this->mapper->expects($this->never())->method('findActive');

        $body = $this->controller->active()->getData();

        $this->assertSame([], $body['results']);
        $this->assertSame(0, $body['total']);
    }

    public function testActiveScopesToTheCallersOrganisation(): void
    {
        $this->params([]);
        $this->activeOrganisation('org-a');

        $this->mapper->expects($this->once())->method('findActive')
            ->with('org-a', 10)
            ->willReturn([]);
        $this->mapper->expects($this->once())->method('countActive')
            ->with('org-a')
            ->willReturn(0);

        $this->controller->active();
    }

    public function testActiveSummarisesEachRunWithItsFlowNameAndStep(): void
    {
        $this->params(['limit' => 5]);
        $this->activeOrganisation('org-a');

        $run = new FlowRun();
        $run->setUuid('run-1');
        $run->setFlowId('f1');
        $run->setStatus(FlowRun::STATUS_SUSPENDED);
        $run->setTrigger('object.created');
        $run->setTriggeredBy('alice');
        $run->setMarking(['await-approval' => 1]);
        $run->setSubjectUuid('subj-1');
        $run->setSubjectRegister('hermiq');
        $run->setSubjectSchema('agent');

        $this->mapper->method('findActive')->willReturn([$run]);
        $this->mapper->method('countActive')->willReturn(42);
        $this->resolvers->method('resolveFlow')->with('f1')->willReturn(['id' => 'f1', 'name' => 'Hydra Triage']);

        $body = $this->controller->active()->getData();
        $row  = $body['results'][0];

        $this->assertSame('run-1', $row['uuid']);
        $this->assertSame('Hydra Triage', $row['flowName']);
        $this->assertSame(FlowRun::STATUS_SUSPENDED, $row['status']);
        $this->assertSame('await-approval', $row['step']);
        $this->assertSame('alice', $row['startedBy']);
        $this->assertSame('agent', $row['subject']['schema']);
        // The honest total, not the length of the bounded page.
        $this->assertSame(42, $body['total']);
    }

    public function testActiveFallsBackToTheFlowIdWhenTheFlowNoLongerResolves(): void
    {
        $this->params([]);
        $this->activeOrganisation('org-a');

        $run = new FlowRun();
        $run->setUuid('run-2');
        $run->setFlowId('orphan-flow');
        $run->setStatus(FlowRun::STATUS_QUEUED);

        $this->mapper->method('findActive')->willReturn([$run]);
        $this->mapper->method('countActive')->willReturn(1);
        // The owning app is disabled — no resolver claims the id.
        $this->resolvers->method('resolveFlow')->willReturn(null);

        $row = $this->controller->active()->getData()['results'][0];

        $this->assertSame('orphan-flow', $row['flowName']);
        $this->assertNull($row['step']);
    }

    public function testActiveCapsTheRequestedLimit(): void
    {
        $this->params(['limit' => 5000]);
        $this->activeOrganisation('org-a');

        $this->mapper->expects($this->once())->method('findActive')
            ->with('org-a', 50)
            ->willReturn([]);
        $this->mapper->method('countActive')->willReturn(0);

        $this->assertSame(50, $this->controller->active()->getData()['limit']);
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
