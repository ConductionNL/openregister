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
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
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
     * The mocked preflight.
     *
     * @var FlowNodePreflight
     */
    private FlowNodePreflight $preflight;

    /**
     * The flow CRUD surface, mocked.
     *
     * @var \OCA\OpenRegister\Service\Flow\FlowService
     */
    private $flows;

    /**
     * The mocked user session, used to resolve the caller.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The mocked group manager, used to answer "is the caller an admin".
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

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

        $this->request   = $this->createMock(IRequest::class);
        $this->nodes     = $this->createMock(FlowNodeRegistry::class);
        $this->preflight = $this->createMock(FlowNodePreflight::class);

        $this->flows = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowService::class);

        $this->actionAuth = $this->createMock(\OCA\OpenRegister\Service\OpenRegisterActionAuthService::class);
        $this->actionAuth->method('can')->willReturn(true);

        // Default to an ADMIN caller so the pre-existing tests keep exercising
        // the palette they were written against; the escalation tests below
        // override this with a non-admin.
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $adminUser          = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($adminUser);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class),
            $this->preflight,
            $this->flows,
            // A REAL FlowAccess over the same three mocks the controller used
            // to take directly, not a mock of it. The extraction moved where
            // these live, not what they decide, and every assertion below still
            // exercises the same session / group / rights logic end to end.
            //
            // The action-auth ALLOWS by default, so the pre-existing tests keep
            // asserting what they were written to assert. The rights themselves
            // are covered in ActionAuthEveryoneTest, and the refusal path below
            // overrides this.
            $this->access($this->userSession, $this->groupManager, $this->actionAuth)
        );

    }//end setUp()

    /**
     * A real FlowAccess over the given collaborators.
     *
     * @param IUserSession                                            $userSession  The session.
     * @param IGroupManager                                           $groupManager The group manager.
     * @param \OCA\OpenRegister\Service\OpenRegisterActionAuthService $actionAuth   The rights matrix.
     *
     * @return FlowAccess The access service.
     */
    private function access(
        IUserSession $userSession,
        IGroupManager $groupManager,
        \OCA\OpenRegister\Service\OpenRegisterActionAuthService $actionAuth
    ): FlowAccess {
        return new FlowAccess(
            userSession: $userSession,
            groupManager: $groupManager,
            actionAuth: $actionAuth
        );

    }//end access()

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
     * An ADMIN caller gets the admin palette without asking for it.
     *
     * The name says "defaults" because that is what it means for an
     * administrator. It is no longer a property of the request: the scope is
     * derived from the caller, and this test's caller is an admin because
     * setUp() makes one. The non-admin half is covered at the bottom of this
     * class.
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
            $mapper,
            $this->preflight,
            $this->flows,
            $this->access($this->userSession, $this->groupManager, $this->actionAuth)
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
     * THE SECURITY PROPERTY for `state()`.
     *
     * `GET /api/flow/{flowId}/state` is `@NoAdminRequired`, and it used to hand
     * the client-supplied uuid straight to `FlowStateMapper::findByFlow()`,
     * which applies no organisation scoping whatsoever. Any authenticated user
     * could therefore read any other organisation's flow state — arbitrary data
     * written by flow nodes (slot holders, external ids, run bookkeeping) — by
     * naming its uuid. It was also the only method in the class that broke the
     * invariant its own file header states.
     *
     * Two assertions, because either alone is satisfiable without the fix:
     * the mapper must not be consulted at all, AND the caller must get a 404
     * rather than an empty-but-200 body that reads like "this flow has no
     * state".
     *
     * @return void
     */
    public function testStateRefusesAFlowTheCallerMayNotSee(): void
    {
        $mapper = $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class);
        $mapper->expects($this->never())->method('findByFlow');

        $this->flows->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('No such flow'));

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $mapper,
            $this->preflight,
            $this->flows,
            $this->access($this->userSession, $this->groupManager, $this->actionAuth)
        );

        $response = $controller->state(flowId: 'someone-elses-flow');

        $this->assertSame(404, $response->getStatus());
        $this->assertSame(['error' => 'No such flow'], $response->getData());

    }//end testStateRefusesAFlowTheCallerMayNotSee()

    /**
     * The positive control for the test above: a flow the caller CAN see still
     * serves its state. A refusal test that cannot be shown to pass on the
     * allowed path is only evidence about the refusal.
     *
     * @return void
     */
    public function testStateStillServesAFlowTheCallerCanSee(): void
    {
        $state = new \OCA\OpenRegister\Db\FlowState();
        $state->setFlowId('flow-1');
        $state->setState(['slots' => ['1' => ['holder' => 'issue-7']]]);

        $mapper = $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class);
        $mapper->expects($this->once())->method('findByFlow')->willReturn($state);

        $this->flows->method('find')->willReturn(new \OCA\OpenRegister\Db\Flow());
        $this->request->method('getParam')->willReturn('');

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $mapper,
            $this->preflight,
            $this->flows,
            $this->access($this->userSession, $this->groupManager, $this->actionAuth)
        );

        $response = $controller->state(flowId: 'flow-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['1' => ['holder' => 'issue-7']], $response->getData()['state']['slots']);

    }//end testStateStillServesAFlowTheCallerCanSee()

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
            $mapper,
            $this->preflight,
            $this->flows,
            $this->access($this->userSession, $this->groupManager, $this->actionAuth)
        );

        $data = $controller->state(flowId: 'flow-1')->getData();

        $this->assertSame([], $data['state']);
        $this->assertArrayNotHasKey('results', $data);

    }//end testStateWithoutListIsUnchanged()

    /**
     * Preflighting a document that cannot run answers 200 with a verdict.
     *
     * A flow that will not run is a valid ANSWER, not a failed request — a CI
     * job asking the question needs the finding list, not an HTTP error it has
     * to reverse-engineer.
     *
     * @return void
     */
    public function testValidateReportsBlockingFindings(): void
    {
        $flow = [
            'name'  => 'hydra-file-findings',
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.explode']],
        ];

        $this->request->method('getParam')->willReturn($flow);
        $this->preflight->method('looksLikeFlow')->willReturn(true);
        $this->preflight->method('inspect')->willReturn(
            [
                'blocking' => [
                    [
                        'type'   => 'openregister.explode',
                        'app'    => 'openregister',
                        'edge'   => 'e1',
                        'reason' => FlowNodePreflight::REASON_MISSING_FROM_OWNER,
                    ],
                ],
                'warnings' => [],
            ]
        );
        $this->preflight->method('describe')->willReturn('Flow "hydra-file-findings" names 1 step type(s)');

        $response = $this->controller->validate();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['valid']);
        $this->assertCount(1, $data['blocking']);
        $this->assertSame('openregister.explode', $data['blocking'][0]['type']);
        $this->assertArrayHasKey('message', $data);

    }//end testValidateReportsBlockingFindings()

    /**
     * An absent optional app is a warning, and the document stays valid.
     *
     * @return void
     */
    public function testValidateTreatsAnAbsentAppAsAWarning(): void
    {
        $this->request->method('getParam')->willReturn(['nodes' => [], 'edges' => []]);
        $this->preflight->method('looksLikeFlow')->willReturn(true);
        $this->preflight->method('inspect')->willReturn(
            [
                'blocking' => [],
                'warnings' => [
                    [
                        'type'   => 'openconnector.source-call',
                        'app'    => 'openconnector',
                        'edge'   => 'e1',
                        'reason' => FlowNodePreflight::REASON_OWNER_NOT_ENABLED,
                    ],
                ],
            ]
        );

        $data = $this->controller->validate()->getData();

        $this->assertTrue($data['valid']);
        $this->assertCount(1, $data['warnings']);
        $this->assertArrayNotHasKey('message', $data);

    }//end testValidateTreatsAnAbsentAppAsAWarning()

    /**
     * A body that is not a graph is a 400, not a silent pass.
     *
     * @return void
     */
    public function testValidateRejectsANonFlowBody(): void
    {
        $this->request->method('getParam')->willReturn(['title' => 'not a flow']);
        $this->preflight->method('looksLikeFlow')->willReturn(false);

        $response = $this->controller->validate();

        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($response->getData()['valid']);

    }//end testValidateRejectsANonFlowBody()

    /**
     * A non-administrator must never receive the admin palette.
     *
     * This is the regression test for the actual defect. The scope used to come
     * from `?scope=`, which is the caller's to set, so it could never have been
     * a privilege check — and because it DEFAULTED to SCOPE_ADMIN, the failure
     * mode was the dangerous one: a non-administrator who simply did not send
     * the parameter got the admin palette. Omitting it is the normal case.
     *
     * @return void
     */
    public function testANonAdminNeverReceivesTheAdminPaletteWhenNoScopeIsSent(): void
    {
        $userSession  = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $plainUser    = $this->createMock(IUser::class);
        $plainUser->method('getUID')->willReturn('jan');
        $userSession->method('getUser')->willReturn($plainUser);
        $groupManager->method('isAdmin')->willReturn(false);

        // No scope parameter — the normal case, and the one that used to leak.
        $this->request->method('getParam')->willReturn(null);
        $this->nodes->expects($this->once())
            ->method('palette')
            ->with(IManager::SCOPE_USER)
            ->willReturn([]);

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
            $this->preflight,
            $this->flows,
            $this->access($userSession, $groupManager, $this->actionAuth)
        );

        $controller->nodeCatalog();

    }//end testANonAdminNeverReceivesTheAdminPaletteWhenNoScopeIsSent()

    /**
     * A non-administrator cannot escalate by ASKING for the admin scope.
     *
     * @return void
     */
    public function testANonAdminCannotRequestTheAdminScope(): void
    {
        $userSession  = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $plainUser    = $this->createMock(IUser::class);
        $plainUser->method('getUID')->willReturn('jan');
        $userSession->method('getUser')->willReturn($plainUser);
        $groupManager->method('isAdmin')->willReturn(false);

        $this->request->method('getParam')->willReturn('admin');
        $this->nodes->expects($this->once())
            ->method('palette')
            ->with(IManager::SCOPE_USER)
            ->willReturn([]);

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
            $this->preflight,
            $this->flows,
            $this->access($userSession, $groupManager, $this->actionAuth)
        );

        $controller->nodeCatalog();

    }//end testANonAdminCannotRequestTheAdminScope()

    /**
     * An unresolvable session fails CLOSED, onto the reduced palette.
     *
     * @return void
     */
    public function testAnUnresolvableSessionGetsTheUserPalette(): void
    {
        $userSession  = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userSession->method('getUser')->willReturn(null);
        $groupManager->expects($this->never())->method('isAdmin');

        $this->request->method('getParam')->willReturn(null);
        $this->nodes->expects($this->once())
            ->method('palette')
            ->with(IManager::SCOPE_USER)
            ->willReturn([]);

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
            $this->preflight,
            $this->flows,
            $this->access($userSession, $groupManager, $this->actionAuth)
        );

        $controller->nodeCatalog();

    }//end testAnUnresolvableSessionGetsTheUserPalette()

    /**
     * A caller without the right is refused with 403, not 500.
     *
     * The four flow rights are seeded open, so this path only fires once an
     * admin has narrowed them — which makes it exactly the one nobody
     * exercises by accident. It returns a RESPONSE rather than letting an OCS
     * exception escape a plain Controller, because that surfaces as a 500, and
     * a right that reads as a server fault is one nobody can act on.
     *
     * @return void
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
     */
    public function testAWriteIsRefusedWhenTheRightIsNotHeld(): void
    {
        $denying = $this->createMock(\OCA\OpenRegister\Service\OpenRegisterActionAuthService::class);
        $denying->method('can')->willReturn(false);

        $controller = new FlowController(
            'openregister',
            $this->request,
            $this->createMock(EventCatalogService::class),
            $this->nodes,
            $this->createMock(originalClassName: \OCA\OpenRegister\Db\FlowStateMapper::class),
            $this->preflight,
            $this->flows,
            $this->access($this->userSession, $this->groupManager, $denying)
        );

        // The flow service must never be reached: a refusal that still wrote
        // would be a right in name only.
        $this->flows->expects($this->never())->method('save');
        $this->flows->expects($this->never())->method('run');

        $responses = [
            $controller->create(),
            $controller->update('x'),
            $controller->destroy('x'),
            $controller->run('x'),
        ];

        foreach ($responses as $response) {
            $this->assertSame(403, $response->getStatus());
        }

    }//end testAWriteIsRefusedWhenTheRightIsNotHeld()
}//end class
