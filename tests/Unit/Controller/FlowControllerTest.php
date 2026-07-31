<?php
/**
 * The read endpoints backing the visual flow builder.
 *
 * `eventCatalog()` answers "what can start a flow"; `nodeCatalog()` answers
 * "what can a flow do". The second had no HTTP surface at all, so anything
 * building a flow UI had to hardcode the step list — which goes stale silently
 * the moment an app contributes a leaf, because an unknown `edges[].type` only
 * fails when the flow RUNS.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\IRequest;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\FlowController
 */
class FlowControllerTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * The mocked node registry.
     *
     * @var FlowNodeRegistry
     */
    private FlowNodeRegistry $nodes;

    /**
     * The controller under test.
     *
     * @var FlowController
     */
    private FlowController $controller;


    /**
     * Build the controller over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->nodes   = $this->createMock(FlowNodeRegistry::class);

        $this->controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class)
        );

    }//end setUp()


    /**
     * The catalog surfaces the registry's palette in the documented envelope.
     *
     * @return void
     */
    public function testNodeCatalogReturnsThePaletteWithATotal(): void
    {
        $palette = [
            ['id' => 'openregister.set-fields', 'displayName' => 'Edit fields', 'description' => '', 'icon' => '/i.svg'],
            ['id' => 'openconnector.source-call', 'displayName' => 'Call a source', 'description' => '', 'icon' => '/j.svg'],
        ];
        $this->nodes->method('palette')->willReturn($palette);

        $response = $this->controller->nodeCatalog();
        $data     = $response->getData();

        $this->assertSame($palette, $data['results']);
        $this->assertSame(2, $data['total'], 'the envelope matches eventCatalog()');

    }//end testNodeCatalogReturnsThePaletteWithATotal()


    /**
     * An app-contributed leaf is visible — the whole point of the endpoint.
     *
     * @return void
     */
    public function testNodeCatalogSurfacesAppContributedLeaves(): void
    {
        $this->nodes->method('palette')->willReturn(
            [['id' => 'hermiq.agent-step', 'displayName' => 'Agent step', 'description' => '', 'icon' => '']]
        );

        $ids = array_column($this->controller->nodeCatalog()->getData()['results'], 'id');

        $this->assertContains('hermiq.agent-step', $ids);

    }//end testNodeCatalogSurfacesAppContributedLeaves()


    /**
     * Admin scope is the default, so a builder never has to ask for it.
     *
     * @return void
     */
    public function testNodeCatalogDefaultsToAdminScope(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->nodes->expects($this->once())
            ->method('palette')
            ->with(IManager::SCOPE_ADMIN)
            ->willReturn([]);

        $this->controller->nodeCatalog();

    }//end testNodeCatalogDefaultsToAdminScope()


    /**
     * `?scope=user` filters to what a non-administrator may actually run —
     * offering an admin-only step to a user would produce a flow that only
     * fails once it runs.
     *
     * @return void
     */
    public function testNodeCatalogHonoursTheUserScope(): void
    {
        $this->request->method('getParam')->willReturn('user');
        $this->nodes->expects($this->once())
            ->method('palette')
            ->with(IManager::SCOPE_USER)
            ->willReturn([]);

        $this->controller->nodeCatalog();

    }//end testNodeCatalogHonoursTheUserScope()


    /**
     * An empty registry is a legal answer, not an error.
     *
     * @return void
     */
    public function testNodeCatalogHandlesAnEmptyRegistry(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->nodes->method('palette')->willReturn([]);

        $data = $this->controller->nodeCatalog()->getData();

        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);

    }//end testNodeCatalogHandlesAnEmptyRegistry()
    /**
     * `?list=slots` serves the slot table as rows a manifest table can render.
     *
     * The state's natural shape and a table's required shape genuinely differ:
     * a slot table is keyed by slot number so a claim is one lookup, while a
     * manifest `object-table` needs an array at its responsePath.
     *
     * @return void
     */
    public function testStateCanServeOneKeyAsAList(): void
    {
        $state = new \OCA\OpenRegister\Db\FlowState();
        $state->setFlowId('flow-1');
        $state->setState(
            [
                'slots' => [
                    '1' => ['holder' => 'issue-7', 'since' => '2026-07-31T10:00:00+00:00', 'stage' => 'builder'],
                    '2' => null,
                ],
            ]
        );

        $mapper = $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class);
        $mapper->method('findByFlow')->willReturn($state);

        $this->request->method('getParam')->willReturn('slots');

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $mapper
        );

        $data = $controller->state(flowId: 'flow-1')->getData();

        $this->assertSame('slots', $data['key']);
        $this->assertSame(2, $data['total']);
        $this->assertSame('issue-7', $data['results'][0]['holder']);
        $this->assertSame('builder', $data['results'][0]['stage']);

        // A FREE slot is a row, not an omission. A table that drops empty slots
        // reads as a smaller pool rather than an idle one.
        $this->assertSame(2, $data['results'][1]['slot']);
        $this->assertArrayNotHasKey('holder', $data['results'][1]);

    }//end testStateCanServeOneKeyAsAList()


    /**
     * Without `?list=`, the payload is unchanged — no `results`, no `total`.
     *
     * @return void
     */
    public function testStateWithoutListIsUnchanged(): void
    {
        $mapper = $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class);
        $mapper->method('findByFlow')->willReturn(null);

        $this->request->method('getParam')->willReturn('');

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $mapper
        );

        $data = $controller->state(flowId: 'flow-1')->getData();

        $this->assertSame([], $data['state']);
        $this->assertArrayNotHasKey('results', $data);

    }//end testStateWithoutListIsUnchanged()
}//end class
