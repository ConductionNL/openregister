<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowNodeRunController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\IFlowDirectlyInvokable;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowNodeRunControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private IUserSession&MockObject $userSession;
	private FlowService&MockObject $flows;
	private FlowPublishedGraph&MockObject $publishedGraphs;
	private FlowLocator&MockObject $subjects;
	private FlowNodeRegistry&MockObject $nodes;
	private FlowRunService&MockObject $runner;
	private ObjectService&MockObject $objects;
	private SchemaMapper&MockObject $schemas;
	private PermissionHandler&MockObject $permissions;
	private FlowNodeRunController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->publishedGraphs = $this->createMock(FlowPublishedGraph::class);
		$this->subjects = $this->createMock(FlowLocator::class);
		$this->nodes = $this->createMock(FlowNodeRegistry::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->permissions = $this->createMock(PermissionHandler::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new FlowNodeRunController(
			appName: 'openregister',
			request: $this->request,
			userSession: $this->userSession,
			flows: $this->flows,
			publishedGraphs: $this->publishedGraphs,
			subjects: $this->subjects,
			nodes: $this->nodes,
			runner: $this->runner,
			objects: $this->objects,
			schemas: $this->schemas,
			permissions: $this->permissions
		);
	}//end setUp()

	/** An invokable node type, opted in via IFlowDirectlyInvokable, with a config form. */
	private function invokableNodeWithForm(): IFlowNode {
		return new class implements IFlowNode, IFlowDirectlyInvokable, IFlowNodeConfigForm {
			public function getId(): string {
				return 'dossiq.merge-template';
			}
			public function getDisplayName(): string {
				return 'Merge template';
			}
			public function getDescription(): string {
				return 'Merges a template.';
			}
			public function getIcon(): string {
				return 'icon.svg';
			}
			public function isAvailableForScope(int $scope): bool {
				return true;
			}
			public function validateConfig(array $config): void {
			}
			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
			public function configForm(): array {
				return [
					['key' => 'templateSlug', 'label' => 'Template', 'type' => 'select', 'optionsFrom' => '/api/dossiq/templates'],
				];
			}
		};
	}//end invokableNodeWithForm()

	/** A node type that has NOT opted in to direct invocation. */
	private function notInvokableNode(): IFlowNode {
		return new class implements IFlowNode {
			public function getId(): string {
				return 'dossiq.internal-only';
			}
			public function getDisplayName(): string {
				return 'Internal only';
			}
			public function getDescription(): string {
				return 'Not directly invokable.';
			}
			public function getIcon(): string {
				return 'icon.svg';
			}
			public function isAvailableForScope(int $scope): bool {
				return true;
			}
			public function validateConfig(array $config): void {
			}
			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
	}//end notInvokableNode()

	private function params(array $values): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($values) {
				return ($values[$key] ?? $default);
			}
		);
	}//end params()

	// --- Node resolution / opt-in (RN-1 half 1) -----------------------------

	public function testUnknownFlowIsNotFound(): void {
		$this->flows->method('find')->willThrowException(new DoesNotExistException('No such flow'));

		$response = $this->controller->run(id: 'ghost', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnknownFlowIsNotFound()

	public function testNoPublishedVersionIsNotFound(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(null);

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testNoPublishedVersionIsNotFound()

	public function testUnknownNodeIdIsNotFound(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(['nodes' => [['id' => 'other', 'type' => 'x']]]);

		$response = $this->controller->run(id: 'f1', nodeId: 'ghost-node');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnknownNodeIdIsNotFound()

	/**
	 * 🔴 or#3642 RN-1 CORE GUARD (1/2). A node type that has NOT implemented
	 * `IFlowDirectlyInvokable` must be refused, identically to a node id that
	 * does not exist — no oracle for "exists but not invokable" vs "no such
	 * node". Mutation check: remove the `instanceof IFlowDirectlyInvokable`
	 * check in `resolveInvokableStep()` and this reddens (200/201 instead of
	 * 404, and `runner->queue()` gets called).
	 */
	public function testANodeThatHasNotOptedInIsRefused(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'dossiq.internal-only']]]
		);
		$this->nodes->method('get')->with('dossiq.internal-only')->willReturn($this->notInvokableNode());

		$this->runner->expects($this->never())->method('queue');

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testANodeThatHasNotOptedInIsRefused()

	public function testAnOptedInNodeUnknownToTheRegistryIsNotFound(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'ghost.type']]]
		);
		$this->nodes->method('get')->willThrowException(new \UnexpectedValueException('no such type'));

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnOptedInNodeUnknownToTheRegistryIsNotFound()

	// --- Subject resolution + object-RBAC (RN-1 half 2) ---------------------

	private function anOptedInFlowAndNode(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'dossiq.merge-template', 'config' => ['x' => 1]]]]
		);
		$this->nodes->method('get')->with('dossiq.merge-template')->willReturn($this->invokableNodeWithForm());
	}//end anOptedInFlowAndNode()

	public function testAMissingSubjectFieldIsABadRequest(): void {
		$this->anOptedInFlowAndNode();
		$this->params(['subject' => ['uuid' => 'obj-1', 'register' => 'cases']]);

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAMissingSubjectFieldIsABadRequest()

	public function testAnUnresolvableSubjectIsNotFound(): void {
		$this->anOptedInFlowAndNode();
		$this->params(['subject' => ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case']]);
		$this->objects->method('find')->willReturn(null);

		$this->runner->expects($this->never())->method('queue');

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnresolvableSubjectIsNotFound()

	/**
	 * 🔴 or#3642 RN-1 CORE GUARD (2/2). A caller who cannot write to the
	 * subject object must be refused, EVEN THOUGH the node is opted in and
	 * resolves cleanly. Mutation check: hardcode `hasPermission()`'s result to
	 * `true` (or delete the call) in `resolveAuthorizedSubject()` and this
	 * reddens — the run proceeds (`queue()` is called, 201 comes back)
	 * against a subject the caller has no rights to.
	 */
	public function testACallerWithoutSubjectPermissionIsRefused(): void {
		$this->anOptedInFlowAndNode();
		$this->params(['subject' => ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case']]);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('case');
		$object->setOwner('somebody-else');
		$this->objects->method('find')->willReturn($object);
		$this->schemas->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(false);

		$this->runner->expects($this->never())->method('queue');

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutSubjectPermissionIsRefused()

	/**
	 * or#3642 spec.md: "`flow.run` alone is not sufficient" — this endpoint
	 * never asks FlowAccess/OpenRegisterActionAuthService anything at all
	 * (there is no such collaborator on this controller), so a caller who
	 * would pass the flow-wide `flow.run` right (seeded `@authenticated`,
	 * i.e. everyone) but lacks subject permission is STILL refused. This test
	 * exists so a future "helpfully" added `flow.run` shortcut is caught: if
	 * anyone wires FlowAccess in and short-circuits on `flow.run === true`
	 * before the subject check, this reddens.
	 */
	public function testFlowRunAloneIsNotConsultedNorSufficient(): void {
		$this->anOptedInFlowAndNode();
		$this->params(['subject' => ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case']]);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('case');
		$this->objects->method('find')->willReturn($object);
		$this->schemas->method('find')->willReturn(new Schema());
		// The caller has whatever `flow.run` grants (seeded @authenticated,
		// i.e. every signed-in user) -- this controller has no way to even
		// ask that question, and hasPermission() (subject RBAC) is the ONLY
		// vote, here refusing.
		$this->permissions->method('hasPermission')->willReturn(false);

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testFlowRunAloneIsNotConsultedNorSufficient()

	public function testAnAuthorizedCallerRunsTheNodeAndReceivesTheRun(): void {
		$this->anOptedInFlowAndNode();
		$this->params(['subject' => ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case'], 'config' => ['templateSlug' => 'welcome']]);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('case');
		$object->setOwner('alice');
		$this->objects->method('find')->willReturn($object);
		$this->schemas->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);

		$queued = new FlowRun();
		$queued->setStatus(FlowRun::STATUS_QUEUED);
		$this->runner->expects($this->once())->method('queue')
			->with('f1', ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case'], FlowRunService::TRIGGER_DIRECT_NODE, ['nodeId' => 'n1'], 'alice')
			->willReturn($queued);

		$this->subjects->method('resolveFlow')->willReturn(['id' => 'f1', 'nodes' => []]);

		$done = new FlowRun();
		$done->setStatus(FlowRun::STATUS_COMPLETED);
		$this->runner->expects($this->once())->method('executeNode')
			->with($queued, ['id' => 'f1', 'nodes' => []], $object, 'n1')
			->willReturn($done);

		$response = $this->controller->run(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(FlowRun::STATUS_COMPLETED, $response->getData()['status']);
	}//end testAnAuthorizedCallerRunsTheNodeAndReceivesTheRun()

	// --- The GET describe-before-running form() endpoint --------------------

	public function testFormIsRefusedForANonOptedInNode(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'dossiq.internal-only']]]
		);
		$this->nodes->method('get')->willReturn($this->notInvokableNode());

		$response = $this->controller->form(id: 'f1', nodeId: 'n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testFormIsRefusedForANonOptedInNode()

	public function testFormReturnsTheNodesDeclaredFieldsUnchanged(): void {
		$this->anOptedInFlowAndNode();

		$response = $this->controller->form(id: 'f1', nodeId: 'n1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[['key' => 'templateSlug', 'label' => 'Template', 'type' => 'select', 'optionsFrom' => '/api/dossiq/templates']],
			$body['configForm']
		);
	}//end testFormReturnsTheNodesDeclaredFieldsUnchanged()

	public function testFormOmitsConfigFormWhenTheNodeDeclaresNone(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->publishedGraphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'dossiq.no-form']]]
		);
		$noForm = new class implements IFlowNode, IFlowDirectlyInvokable {
			public function getId(): string {
				return 'dossiq.no-form';
			}
			public function getDisplayName(): string {
				return 'No form';
			}
			public function getDescription(): string {
				return '';
			}
			public function getIcon(): string {
				return '';
			}
			public function isAvailableForScope(int $scope): bool {
				return true;
			}
			public function validateConfig(array $config): void {
			}
			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
		$this->nodes->method('get')->willReturn($noForm);

		$response = $this->controller->form(id: 'f1', nodeId: 'n1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('configForm', $body);
	}//end testFormOmitsConfigFormWhenTheNodeDeclaresNone()

}//end class
