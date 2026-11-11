<?php

/**
 * FlowNodeRunController characterisation tests.
 *
 * Pins what a direct node run answers and passes on for the branches the
 * controller's own test does not: the exact 404 / 400 / 403 bodies, the
 * permission question asked, the queue refusals, and the flow document the
 * run executes when the flow no longer resolves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowNodeRunController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowUnattributed;
use OCA\OpenRegister\Service\Flow\IFlowDirectlyInvokable;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowNodeRunControllerFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;
use OCA\OpenRegister\Service\Flow\FlowRunPass;

/**
 * Characterisation of FlowNodeRunController::run() and its two resolutions.
 */
class FlowNodeRunCharacterisationTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The flow service double.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * The published-graph double.
	 *
	 * @var FlowPublishedGraph&MockObject
	 */
	private FlowPublishedGraph&MockObject $graphs;

	/**
	 * The flow locator double.
	 *
	 * @var FlowLocator&MockObject
	 */
	private FlowLocator&MockObject $subjects;

	/**
	 * The node registry double.
	 *
	 * @var FlowNodeRegistry&MockObject
	 */
	private FlowNodeRegistry&MockObject $nodes;

	/**
	 * The runner double.
	 *
	 * @var FlowRunService&MockObject
	 */
	private FlowRunService&MockObject $runner;

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * The permission double.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissions;

	/**
	 * The controller under test.
	 *
	 * @var FlowNodeRunController
	 */
	private FlowNodeRunController $controller;

	/**
	 * Build the controller over doubles; alice is signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->flows = $this->createMock(FlowService::class);
		$this->graphs = $this->createMock(FlowPublishedGraph::class);
		$this->subjects = $this->createMock(FlowLocator::class);
		$this->nodes = $this->createMock(FlowNodeRegistry::class);
		$this->runner = $this->createMock(FlowRunService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = FlowNodeRunControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			userSession: $session,
			flows: $this->flows,
			publishedGraphs: $this->graphs,
			subjects: $this->subjects,
			nodes: $this->nodes,
			runner: $this->runner,
			objects: $this->objects,
			schemas: $this->schemas,
			permissions: $this->permissions
		);
	}

	/**
	 * Answer request params from a map.
	 *
	 * @param array<string, mixed> $values The params.
	 *
	 * @return void
	 */
	private function params(array $values): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($values[$key] ?? $default)
		);
	}

	/**
	 * Flow f1 publishes n2 (second in its graph) of an opted-in type.
	 *
	 * @return void
	 */
	private function invokableStep(): void {
		$this->flows->method('find')->willReturn(new Flow());
		$this->graphs->method('graphOf')->willReturn(
			['nodes' => [['id' => 'n1', 'type' => 'other'], ['id' => 'n2', 'type' => 'merge', 'config' => ['a' => 1]]]]
		);
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowDirectlyInvokable::class]);
		$this->nodes->method('get')->with('merge')->willReturn($node);
	}

	/**
	 * A permitted subject obj-1 owned by bob.
	 *
	 * @return ObjectEntity
	 */
	private function permittedSubject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setOwner('bob');
		$this->objects->method('find')->willReturn($object);
		$this->schemas->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->params(['subject' => ['uuid' => ' obj-1 ', 'register' => ' cases', 'schema' => 'case '], 'config' => ['b' => 2]]);

		return $object;
	}

	/**
	 * Every node-resolution refusal names the flow or the node.
	 *
	 * @return void
	 */
	public function testTheResolutionRefusalsNameTheFlowOrTheNode(): void {
		$this->flows->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('gone')),
			new Flow(),
			new Flow(),
			new Flow()
		);
		$this->graphs->method('graphOf')->willReturnOnConsecutiveCalls(null, ['nodes' => [['id' => 'n1']]], ['nodes' => [['id' => 'n2', 'type' => 'x']]]);
		$this->nodes->method('get')->willThrowException(new RuntimeException('unknown'));

		$answers = [];
		for ($i = 0; $i < 4; $i++) {
			$response = $this->controller->run(id: 'f1', nodeId: 'n2');
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[404, ['error' => 'No such flow: f1']],
				[404, ['error' => 'No such node: n2']],
				[404, ['error' => 'No such node: n2']],
				[404, ['error' => 'No such node: n2']],
			],
			$answers
		);
	}

	/**
	 * Each missing subject part is the same 400, and nothing is loaded.
	 *
	 * @return void
	 */
	public function testEachMissingSubjectPartIsTheSame400(): void {
		$this->invokableStep();
		$this->objects->expects($this->never())->method('find');
		$subjects = [['register' => 'r', 'schema' => 's'], ['uuid' => 'u', 'schema' => 's'], ['uuid' => 'u', 'register' => ' ', 'schema' => 's']];
		$current = 0;
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use (&$current, $subjects) {
				if ($key === 'subject') {
					return $subjects[$current];
				}

				return $default;
			}
		);

		for ($current = 0; $current < 3; $current++) {
			$response = $this->controller->run(id: 'f1', nodeId: 'n2');
			$this->assertSame(
				[400, ['error' => 'A subject needs uuid, register and schema.']],
				[$response->getStatus(), $response->getData()]
			);
		}
	}

	/**
	 * The subject is loaded trimmed, without RBAC or audit, and asked about update as alice.
	 *
	 * @return void
	 */
	public function testTheSubjectIsLoadedTrimmedAndAskedAboutUpdate(): void {
		$this->invokableStep();
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setOwner('bob');
		$schema = new Schema();
		$this->objects->expects($this->once())->method('find')
			->with('obj-1', [], false, 'cases', 'case', false, true, true, false)
			->willReturn($object);
		$this->schemas->expects($this->once())->method('find')->with('7', [], false, false)->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', 'alice', 'bob', true, $object)
			->willReturn(false);
		$this->params(['subject' => ['uuid' => ' obj-1 ', 'register' => ' cases', 'schema' => 'case ']]);

		$response = $this->controller->run(id: 'f1', nodeId: 'n2');

		$this->assertSame(
			[403, ['error' => 'You do not have permission to act on this object.']],
			[$response->getStatus(), $response->getData()]
		);
	}

	/**
	 * A subject whose schema does not load is refused, not allowed.
	 *
	 * @return void
	 */
	public function testASubjectWhoseSchemaDoesNotLoadIsRefused(): void {
		$this->invokableStep();
		$object = new ObjectEntity();
		$object->setSchema('7');
		$this->objects->method('find')->willReturn($object);
		$this->schemas->method('find')->willThrowException(new RuntimeException('lost'));
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->params(['subject' => ['uuid' => 'u', 'register' => 'r', 'schema' => 's']]);

		$response = $this->controller->run(id: 'f1', nodeId: 'n2');

		$this->assertSame(
			[403, ['error' => 'The subject\'s schema could not be resolved.']],
			[$response->getStatus(), $response->getData()]
		);
	}

	/**
	 * A subject that does not load, or loads as no entity, is 404.
	 *
	 * @return void
	 */
	public function testASubjectThatDoesNotLoadIs404(): void {
		$this->invokableStep();
		$this->objects->method('find')->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('x')), null);
		$this->params(['subject' => ['uuid' => 'u', 'register' => 'r', 'schema' => 's']]);

		foreach ([0, 1] as $i) {
			$response = $this->controller->run(id: 'f1', nodeId: 'n2');
			$this->assertSame([404, ['error' => 'No such object.']], [$response->getStatus(), $response->getData()], (string)$i);
		}
	}

	/**
	 * Each queue refusal is a 409 with its own body, and nothing is executed.
	 *
	 * @return void
	 */
	public function testEachQueueRefusalIsA409(): void {
		$this->invokableStep();
		$this->permittedSubject();
		$lifecycle = new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_NOT_PUBLISHED, flowId: 'f1', state: 'draft');
		$deadEnd = new FlowDeadEnd(nodeIds: ['n9']);
		$unattributed = new FlowUnattributed(flowId: 'f1', trigger: 'direct-node');
		$this->runner->method('queueRun')->willReturnOnConsecutiveCalls(
			$this->throwException($lifecycle),
			$this->throwException($deadEnd),
			$this->throwException($unattributed)
		);
		$this->runner->expects($this->never())->method('runNode');

		$answers = [];
		for ($i = 0; $i < 3; $i++) {
			$response = $this->controller->run(id: 'f1', nodeId: 'n2');
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			[
				[409, ['error' => $lifecycle->getMessage(), 'reason' => $lifecycle->getReason(), 'lifecycleStatus' => 'draft', 'flowId' => 'f1']],
				[409, ['error' => $deadEnd->getMessage(), 'nodes' => ['n9']]],
				[409, ['error' => $unattributed->getMessage()]],
			],
			$answers
		);
	}

	/**
	 * A delegation refusal is a 409 with its message.
	 *
	 * @return void
	 */
	public function testADelegationRefusalIsA409(): void {
		$this->invokableStep();
		$this->permittedSubject();
		$refusal = $this->getMockBuilder(DelegationRefused::class)->disableOriginalConstructor()->getMock();
		$this->runner->method('queueRun')->willThrowException($refusal);

		$response = $this->controller->run(id: 'f1', nodeId: 'n2');

		$this->assertSame([409, ['error' => $refusal->getMessage()]], [$response->getStatus(), $response->getData()]);
	}

	/**
	 * When the flow no longer resolves, the run executes a one-node document; the caller's config goes in.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFlowExecutesTheMergedStepAlone(): void {
		$this->invokableStep();
		$object = $this->permittedSubject();
		$queued = new FlowRun();
		$this->runner->expects($this->once())->method('queueRun')
			->with('f1', $this->equalTo(new FlowRunQueueOptions(subject: ['uuid' => 'obj-1', 'register' => 'cases', 'schema' => 'case'], trigger: FlowRunService::TRIGGER_DIRECT_NODE, context: ['nodeId' => 'n2'], user: 'alice')))
			->willReturn($queued);
		$this->subjects->method('resolveFlow')->with('f1')->willReturn(null);
		$done = new FlowRun();
		$done->setStatus('completed');
		$this->runner->expects($this->once())->method('runNode')
			->with(
				new FlowRunPass(
					run: $queued,
					flow: ['id' => 'f1', 'nodes' => [['id' => 'n2', 'type' => 'merge', 'config' => ['a' => 1, 'b' => 2]]], 'edges' => []],
					subject: $object
				),
				['b' => 2]
			)
			->willReturn($done);

		$response = $this->controller->run(id: 'f1', nodeId: 'n2');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('completed', $response->getData()['status']);
	}
}
