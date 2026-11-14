<?php

/**
 * Unit test for FlowNodeRightsGuard: which step needs which right, and who may save it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightRefused;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeRequiresRight;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The guard's decisions.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRightsGuard
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeRightRefused
 */
final class FlowNodeRightsGuardTest extends TestCase {

	/**
	 * The registered step types.
	 *
	 * @var FlowNodeRegistryInterface&MockObject
	 */
	private FlowNodeRegistryInterface&MockObject $nodes;

	/**
	 * Whether a user holds a right.
	 *
	 * @var FlowAccessInterface&MockObject
	 */
	private FlowAccessInterface&MockObject $access;

	/**
	 * The administrator's map.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The author.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->nodes = $this->createMock(FlowNodeRegistryInterface::class);
		$this->access = $this->createMock(FlowAccessInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The guard under test.
	 *
	 * @return FlowNodeRightsGuard
	 */
	private function guard(): FlowNodeRightsGuard {
		return new FlowNodeRightsGuard(nodes: $this->nodes, access: $this->access, appConfig: $this->appConfig);
	}//end guard()

	/**
	 * The administrator's stored map, as JSON.
	 *
	 * @param string $json The stored value.
	 *
	 * @return void
	 */
	private function storedMap(string $json): void {
		$this->appConfig->method('getValueString')->with('openregister', 'flow_node_rights', '{}')->willReturn($json);
	}//end storedMap()

	/**
	 * A node declaring a right, keyed by the config it answers for.
	 *
	 * @param array<string, string|null> $rights The right per json-encoded config.
	 *
	 * @return IFlowNode&MockObject
	 */
	private function declaringNode(array $rights): IFlowNode&MockObject {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeRequiresRight::class]);
		$node->method('requiredRight')->willReturnCallback(
			static fn (array $config): ?string => ($rights[json_encode($config)] ?? null)
		);
		return $node;
	}//end declaringNode()

	/**
	 * A node's own declaration decides when the administrator said nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testANodesOwnDeclarationIsTheRight(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->with('openregister.send-email')->willReturn($this->declaringNode(['[]' => 'flow.node.send-email']));

		$this->assertSame('flow.node.send-email', $this->guard()->rightFor(type: 'openregister.send-email', config: []));
	}//end testANodesOwnDeclarationIsTheRight()

	/**
	 * A node that declares nothing, and an unregistered type, need no right.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testAnUndeclaredOrUnknownStepNeedsNoRight(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturnCallback(
			function (string $type): IFlowNode {
				if ($type === 'openregister.set-fields') {
					return $this->createMock(IFlowNode::class);
				}

				throw new UnexpectedValueException('no such node');
			}
		);

		$this->assertNull($this->guard()->rightFor(type: 'openregister.set-fields', config: []));
		$this->assertNull($this->guard()->rightFor(type: 'someapp.unknown', config: []));
	}//end testAnUndeclaredOrUnknownStepNeedsNoRight()

	/**
	 * The administrator's map wins over the node, and lifts a declaration with null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testTheAdministratorsMapWinsAndCanLift(): void {
		$this->storedMap('{"openregister.send-email":null,"openconnector.source-call":"flow.node.source-call"}');
		// The node is never consulted for a type the administrator decided.
		$this->nodes->expects($this->never())->method('get');
		$guard = $this->guard();

		$this->assertNull($guard->rightFor(type: 'openregister.send-email', config: []));
		$this->assertSame('flow.node.source-call', $guard->rightFor(type: 'openconnector.source-call', config: []));
	}//end testTheAdministratorsMapWinsAndCanLift()

	/**
	 * A malformed stored map is read as empty, so the nodes' own rights still apply.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testAMalformedMapDoesNotLiftANodesRight(): void {
		$this->storedMap('["not","an","object"]');
		$this->nodes->method('get')->willReturn($this->declaringNode(['[]' => 'flow.node.send-email']));

		$this->assertSame('flow.node.send-email', $this->guard()->rightFor(type: 'openregister.send-email', config: []));
	}//end testAMalformedMapDoesNotLiftANodesRight()

	/**
	 * The right can depend on the step's configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testTheRightCanDependOnTheConfiguration(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturn(
			$this->declaringNode([json_encode(['operation' => 'delete']) => 'flow.node.object-delete'])
		);
		$guard = $this->guard();

		$this->assertSame('flow.node.object-delete', $guard->rightFor(type: 'openregister.object-write', config: ['operation' => 'delete']));
		$this->assertNull($guard->rightFor(type: 'openregister.object-write', config: ['operation' => 'update']));
	}//end testTheRightCanDependOnTheConfiguration()

	/**
	 * A document whose steps need no right, or whose rights the author holds, is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testAnAuthorHoldingTheRightMaySave(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturn($this->declaringNode(['[]' => 'flow.node.send-email']));
		$this->access->expects($this->once())->method('may')->with($this->user, 'flow.node.send-email')->willReturn(true);

		$this->guard()->assertMayAuthor(
			user: $this->user,
			// Two steps of the same type ask once: the answer is cached per right.
			document: ['nodes' => [['type' => 'openregister.send-email'], ['type' => 'openregister.send-email']]]
		);
	}//end testAnAuthorHoldingTheRightMaySave()

	/**
	 * A step whose right the author lacks refuses, naming the type, the step and the right.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testAStepWithoutItsRightIsRefused(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturn($this->declaringNode(['[]' => 'flow.node.send-email']));
		$this->access->method('may')->willReturn(false);

		try {
			$this->guard()->assertMayAuthor(
				user: $this->user,
				document: ['nodes' => [['type' => 'openregister.send-email', 'label' => 'Tell the applicant']]]
			);
			$this->fail('a step whose right the author lacks must be refused');
		} catch (FlowNodeRightRefused $refused) {
			$this->assertSame('openregister.send-email', $refused->nodeType);
			$this->assertSame('Tell the applicant', $refused->step);
			$this->assertSame('flow.node.send-email', $refused->right);
			$this->assertStringContainsString('flow.node.send-email', $refused->getMessage());
			$this->assertSame(
				['error' => $refused->getMessage(), 'nodeType' => 'openregister.send-email', 'step' => 'Tell the applicant', 'right' => 'flow.node.send-email'],
				$refused->body()
			);
		}
	}//end testAStepWithoutItsRightIsRefused()

	/**
	 * The STORED document counts too: changing only the name of a powerful flow is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testTheStoredDocumentIsCheckedAsWell(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturnCallback(
			function (string $type): IFlowNode {
				if ($type === 'openregister.send-email') {
					return $this->declaringNode(['[]' => 'flow.node.send-email']);
				}

				return $this->createMock(IFlowNode::class);
			}
		);
		$this->access->method('may')->willReturn(false);

		$this->expectException(FlowNodeRightRefused::class);
		$this->guard()->assertMayAuthor(
			user: $this->user,
			document: ['nodes' => [['type' => 'openregister.set-fields']], 'name' => 'A new name'],
			stored: ['nodes' => [['type' => 'openregister.send-email', 'id' => 'n2']]]
		);
	}//end testTheStoredDocumentIsCheckedAsWell()

	/**
	 * A step still carried on an edge is checked as well.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function testAStepOnAnEdgeIsCheckedToo(): void {
		$this->storedMap('{}');
		$this->nodes->method('get')->willReturn($this->declaringNode(['[]' => 'flow.node.send-email']));
		$this->access->method('may')->willReturn(false);

		$this->expectException(FlowNodeRightRefused::class);
		$this->guard()->assertMayAuthor(user: $this->user, document: ['edges' => [['type' => 'openregister.send-email']]]);
	}//end testAStepOnAnEdgeIsCheckedToo()

	/**
	 * The palette says which right each entry needs and locks the ones this caller lacks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testThePaletteIsLockedForACallerWithoutTheRight(): void {
		$this->storedMap('{"openconnector.source-call":"flow.node.source-call"}');
		$this->access->method('may')->willReturnCallback(
			static fn (IUser $user, string $action): bool => ($action === 'flow.node.send-email')
		);

		$palette = $this->guard()->annotatePalette(
			user: $this->user,
			palette: [
				['id' => 'openregister.send-email', 'requiresRight' => 'flow.node.send-email'],
				['id' => 'openconnector.source-call', 'requiresRight' => null],
				['id' => 'openregister.set-fields', 'requiresRight' => null],
			]
		);

		$this->assertSame(
			[
				['id' => 'openregister.send-email', 'requiresRight' => 'flow.node.send-email'],
				['id' => 'openconnector.source-call', 'requiresRight' => 'flow.node.source-call', 'locked' => true],
				['id' => 'openregister.set-fields', 'requiresRight' => null],
			],
			$palette
		);
	}//end testThePaletteIsLockedForACallerWithoutTheRight()

	/**
	 * The node rights in use: the registered nodes' declarations plus the administrator's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testTheNodeRightsCarryTheirDescriptionAndTypes(): void {
		$this->storedMap('{"openconnector.source-call":"flow.node.source-call","openregister.send-talk-message":null}');
		$this->nodes->method('palette')->willReturn(
			[['id' => 'openregister.send-email'], ['id' => 'openregister.send-talk-message'], ['id' => 'openregister.set-fields']]
		);
		$this->nodes->method('get')->willReturnCallback(
			function (string $type): IFlowNode {
				if ($type === 'openregister.set-fields') {
					return $this->createMock(IFlowNode::class);
				}

				$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeRequiresRight::class]);
				$node->method('declaredRights')->willReturn(['flow.node.send-email' => 'Send e-mail.']);
				return $node;
			}
		);

		$rights = $this->guard()->nodeRights();

		// The Talk node's own declaration is gone: the administrator lifted it.
		$this->assertSame(
			[
				'flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']],
				'flow.node.source-call' => [
					'description' => 'Use the step "openconnector.source-call" in a flow (set by an administrator).',
					'nodeTypes' => ['openconnector.source-call'],
				],
			],
			$rights
		);
	}//end testTheNodeRightsCarryTheirDescriptionAndTypes()

	/**
	 * The administrator's map is stored as an object, so an empty one is not a JSON list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testTheMapIsStoredAsAnObject(): void {
		$this->appConfig->expects($this->once())->method('setValueString')->with('openregister', 'flow_node_rights', '{}');

		$this->guard()->setAdminMap(map: []);
	}//end testTheMapIsStoredAsAnObject()
}//end class
