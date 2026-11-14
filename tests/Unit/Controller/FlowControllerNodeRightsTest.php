<?php

/**
 * Unit test: no flow save path accepts a step whose right the caller lacks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchangeInterface;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRightRefused;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Create, BPMN import, update, draft, publish and adopt each refuse 403.
 *
 * @covers \OCA\OpenRegister\Controller\FlowController
 * @uses   \OCA\OpenRegister\Controller\Support\FlowCatalogue
 * @uses   \OCA\OpenRegister\Controller\Support\FlowControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\FlowDefinitions
 * @uses   \OCA\OpenRegister\Controller\Support\FlowNodeTypes
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeRightRefused
 */
final class FlowControllerNodeRightsTest extends TestCase {

	/**
	 * The step type the e-mail right belongs to.
	 *
	 * @var string
	 */
	private const EMAIL_STEP = 'openregister.send-email';

	/**
	 * The right the e-mail step needs.
	 *
	 * @var string
	 */
	private const EMAIL_RIGHT = 'flow.node.send-email';

	/**
	 * A flow document holding an e-mail step.
	 *
	 * @var array<string, mixed>
	 */
	private const EMAIL_FLOW = ['name' => 'Tell them', 'nodes' => [['type' => self::EMAIL_STEP, 'label' => 'Tell the applicant']]];

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The flow CRUD surface.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * Versions, for publish and draft.
	 *
	 * @var FlowVersionService&MockObject
	 */
	private FlowVersionService&MockObject $versions;

	/**
	 * The guard, which refuses the e-mail step.
	 *
	 * @var FlowNodeRightsGuardInterface&MockObject
	 */
	private FlowNodeRightsGuardInterface&MockObject $nodeRights;

	/**
	 * Build the collaborators, with a guard that refuses the e-mail step.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->versions = $this->createMock(FlowVersionService::class);
		$this->nodeRights = $this->createMock(FlowNodeRightsGuardInterface::class);
	}//end setUp()

	/**
	 * A guard that refuses every document holding the e-mail step, in either position.
	 *
	 * @return void
	 */
	private function refuseTheEmailStep(): void {
		$this->nodeRights->method('assertMayAuthor')->willReturnCallback(
			static function (IUser $user, array $document, ?array $stored = null): void {
				foreach (array_merge(($document['nodes'] ?? []), (($stored ?? [])['nodes'] ?? [])) as $node) {
					if (($node['type'] ?? '') === self::EMAIL_STEP) {
						throw new FlowNodeRightRefused(
							nodeType: self::EMAIL_STEP,
							step: (string)($node['label'] ?? $node['id'] ?? ''),
							right: self::EMAIL_RIGHT
						);
					}
				}
			}
		);
	}//end refuseTheEmailStep()

	/**
	 * The controller over these collaborators, with the caller an administrator for the flow rights.
	 *
	 * @param FlowBpmnInterchangeInterface|null $bpmn The BPMN interchange, when the test imports.
	 *
	 * @return FlowController
	 */
	private function controller(?FlowBpmnInterchangeInterface $bpmn = null): FlowController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('planner');

		$access = $this->createMock(\OCA\OpenRegister\Service\Flow\FlowAccess::class);
		$access->method('currentUser')->willReturn($user);
		// The flow verbs are held; only the node right is not.
		$access->method('may')->willReturn(true);
		$access->method('callerIsAdmin')->willReturn(false);

		// The preflight's own report, so savedBody() has the `warnings` key it reads.
		$preflight = $this->createMock(FlowNodePreflight::class);
		$preflight->method('inspect')->willReturn(['blocking' => [], 'warnings' => []]);

		return FlowControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(EventCatalogService::class),
			$this->createMock(FlowNodeRegistry::class),
			$this->createMock(FlowStateMapper::class),
			$preflight,
			$this->flows,
			$access,
			$this->versions,
			$this->nodeRights,
			$bpmn
		);
	}//end controller()

	/**
	 * A 403 naming the step type and the right.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 * @param string                              $path     The endpoint, for the message.
	 *
	 * @return void
	 */
	private function assertRefused(\OCP\AppFramework\Http\JSONResponse $response, string $path): void {
		$data = $response->getData();
		$this->assertSame(403, $response->getStatus(), $path.' must refuse');
		$this->assertSame(self::EMAIL_STEP, $data['nodeType'], $path.' names the step type');
		$this->assertSame(self::EMAIL_RIGHT, $data['right'], $path.' names the right');
		$this->assertStringContainsString(self::EMAIL_RIGHT, (string)$data['error'], $path.' says so in its message');
	}//end assertRefused()

	/**
	 * A stored flow holding the e-mail step.
	 *
	 * @return Flow
	 */
	private function storedEmailFlow(): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes([['type' => self::EMAIL_STEP, 'id' => 'n1']]);
		$flow->setEdges([]);
		return $flow;
	}//end storedEmailFlow()

	/**
	 * create() refuses a flow with an e-mail step and stores nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testCreateRefusesAStepWithoutItsRight(): void {
		$this->refuseTheEmailStep();
		$this->request->method('getParams')->willReturn(self::EMAIL_FLOW);
		$this->flows->expects($this->never())->method('save');

		$this->assertRefused(response: $this->controller()->create(), path: 'POST /api/flows');
	}//end testCreateRefusesAStepWithoutItsRight()

	/**
	 * A flow with no restricted step is still created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testCreateStillStoresAFlowWithoutARestrictedStep(): void {
		$this->refuseTheEmailStep();
		$this->request->method('getParams')->willReturn(['name' => 'Plain', 'nodes' => [['type' => 'openregister.set-fields']]]);
		$stored = new Flow();
		$stored->setUuid('flow-2');
		$this->flows->expects($this->once())->method('save')->willReturn($stored);

		$this->assertSame(201, $this->controller()->create()->getStatus());
	}//end testCreateStillStoresAFlowWithoutARestrictedStep()

	/**
	 * importBpmn() refuses a mapped flow with an e-mail step and stores nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testTheBpmnImportRefusesAStepWithoutItsRight(): void {
		$this->refuseTheEmailStep();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'xml' ? '<definitions/>' : $default)
		);
		$bpmn = $this->createMock(FlowBpmnInterchangeInterface::class);
		$bpmn->method('import')->willReturn(['flow' => self::EMAIL_FLOW, 'report' => new BpmnMappingReport()]);
		$this->flows->expects($this->never())->method('save');

		$this->assertRefused(response: $this->controller(bpmn: $bpmn)->importBpmn(), path: 'POST /api/flows/bpmn');
	}//end testTheBpmnImportRefusesAStepWithoutItsRight()

	/**
	 * update() refuses when the STORED flow holds the step, even if the new body does not.
	 *
	 * Changing only the name of a powerful flow is still editing it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testUpdateRefusesBecauseOfTheStoredStep(): void {
		$this->refuseTheEmailStep();
		$this->request->method('getParams')->willReturn(['name' => 'A new name']);
		$this->flows->method('find')->with('flow-1')->willReturn($this->storedEmailFlow());
		$this->flows->expects($this->never())->method('save');

		$this->assertRefused(response: $this->controller()->update('flow-1'), path: 'PUT /api/flows/{id}');
	}//end testUpdateRefusesBecauseOfTheStoredStep()

	/**
	 * publish(), draft() and adopt() refuse a stored flow holding the step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testPublishDraftAndAdoptRefuseAStoredRestrictedStep(): void {
		$this->refuseTheEmailStep();
		$this->flows->method('find')->with('flow-1')->willReturn($this->storedEmailFlow());
		$this->flows->expects($this->never())->method('adopt');
		$this->versions->expects($this->never())->method('publish');
		$this->versions->expects($this->never())->method('createDraft');

		$controller = $this->controller();
		$this->assertRefused(response: $controller->publish('flow-1'), path: 'POST /api/flows/{id}/publish');
		$this->assertRefused(response: $controller->draft('flow-1'), path: 'POST /api/flows/{id}/draft');
		$this->assertRefused(response: $controller->adopt('flow-1'), path: 'POST /api/flows/{id}/adopt');
	}//end testPublishDraftAndAdoptRefuseAStoredRestrictedStep()

	/**
	 * The node catalogue marks a step the caller may not use as locked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testTheNodeCatalogueLocksAStepTheCallerMayNotUse(): void {
		$annotated = [['id' => self::EMAIL_STEP, 'requiresRight' => self::EMAIL_RIGHT, 'locked' => true]];
		$this->nodeRights->expects($this->once())->method('annotatePalette')->willReturn($annotated);

		$data = $this->controller()->nodeCatalog()->getData();

		$this->assertSame($annotated, $data['results']);
		$this->assertSame(1, $data['total']);
	}//end testTheNodeCatalogueLocksAStepTheCallerMayNotUse()
}//end class
