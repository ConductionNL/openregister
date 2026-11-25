<?php

/**
 * Saving a flow with a code step needs `flow.code`: PUT /api/flows/{id} through the real rights guard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\CodeRunnerClientInterface;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\Nodes\CodeNode;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowControllerFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\FlowController
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\CodeNode
 */
final class FlowControllerCodeStepRightTest extends TestCase {

	private IRequest&MockObject $request;

	private FlowService&MockObject $flows;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->flows = $this->createMock(FlowService::class);
	}

	/**
	 * The controller, with a maker who holds every flow verb and, or not, `flow.code`.
	 *
	 * @param bool $holdsCode Whether the maker holds `flow.code`.
	 */
	private function controller(bool $holdsCode): FlowController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maker');
		$access = $this->createMock(FlowAccess::class);
		$access->method('currentUser')->willReturn($user);
		$access->method('callerIsAdmin')->willReturn(false);
		$access->method('may')->willReturnCallback(static fn (IUser $u, string $action): bool => $action !== 'flow.code' || $holdsCode);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default): int => $default);
		$appConfig->method('getValueString')->willReturn('{}');
		$code = new CodeNode($l10n, $this->createMock(IURLGenerator::class), $this->createMock(CodeRunnerClientInterface::class), $appConfig);
		$nodes = $this->createMock(FlowNodeRegistryInterface::class);
		$nodes->method('get')->willReturnCallback(
			static fn (string $type) => $type === 'openregister.code' ? $code : throw new \UnexpectedValueException('not registered here')
		);

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
			$this->createMock(FlowVersionService::class),
			new FlowNodeRightsGuard(nodes: $nodes, access: $access, appConfig: $appConfig)
		);
	}

	/**
	 * A stored flow without a code step, and an update adding one.
	 */
	private function addingACodeStep(): void {
		$stored = new Flow();
		$stored->setUuid('flow-1');
		$stored->setNodes([['id' => 'n1', 'type' => 'openregister.set-fields']]);
		$stored->setEdges([]);
		$this->flows->method('find')->with('flow-1')->willReturn($stored);
		$this->request->method('getParams')->willReturn(
			[
				'name' => 'naam in hoofdletters',
				'nodes' => [['id' => 'n1', 'type' => 'openregister.code', 'label' => 'Hoofdletters', 'config' => ['source' => 'return item;']]],
				'edges' => [],
			]
		);
	}

	public function testAMakerWithoutFlowCodeCannotAddACodeStep(): void {
		$this->addingACodeStep();
		$this->flows->expects($this->never())->method('save');

		$response = $this->controller(holdsCode: false)->update('flow-1');
		$data = $response->getData();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('openregister.code', $data['nodeType']);
		$this->assertSame('flow.code', $data['right']);
		$this->assertStringContainsString('flow.code', (string) $data['error']);
	}

	public function testAMakerWithFlowCodeIsNotRefusedForTheCodeStep(): void {
		$this->addingACodeStep();

		$response = $this->controller(holdsCode: true)->update('flow-1');

		$this->assertNotSame(403, $response->getStatus());
	}
}
