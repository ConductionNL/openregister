<?php

/**
 * Unit tests for the dispatcher's acting-identity scoping.
 *
 * The claim under test: a CONTRIBUTED node executes inside the run's `runAs`
 * identity without the contributing app writing a wrapper — and the refusals
 * hold. dossiq shipped three broken nodes (plus a handler) before it built
 * that wrapper itself; these tests are what make the fourth consumer's
 * version unnecessary.
 *
 * The boundary tests matter as much as the wrap: the engine's own nodes
 * already scope themselves (with node-specific wording and skip-when
 * semantics), so wrapping them again would change the ambient identity of
 * nodes that deliberately run bare — and a dispatcher built by hand with no
 * scope (the flow tester, the node unit tests) must keep dispatching.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
 */

declare(strict_types=1);

namespace Unit\Service\Flow {

	use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
	use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
	use OCA\OpenRegister\Service\Flow\FlowRunService;
	use OCA\OpenRegister\Service\Flow\IFlowNode;
	use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
	use OCA\OpenRegister\Service\ObjectService;
	use OCP\IUser;
	use OCP\IUserManager;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	class RegistryStepDispatcherRunAsTest extends TestCase {

		/**
		 * A dispatcher resolving every type to the given node.
		 *
		 * @param IFlowNode $node The node the registry answers with.
		 * @param FlowRunAsScope|null $scope The identity scope, when the test has one.
		 *
		 * @return RegistryStepDispatcher The dispatcher under test.
		 */
		private function dispatcher(IFlowNode $node, ?FlowRunAsScope $scope): RegistryStepDispatcher {
			$registry = $this->createMock(FlowNodeRegistry::class);
			$registry->method('get')->willReturn($node);

			return new RegistryStepDispatcher(registry: $registry, scope: $scope);
		}//end dispatcher()

		/**
		 * A REAL scope over a stub object service that records who it acted as.
		 *
		 * @param string $uid The uid that resolves.
		 * @param boolean $enabled Whether that account is enabled.
		 * @param string|null $scopedAs Receives the uid runAs() was entered with.
		 *
		 * @return FlowRunAsScope The scope.
		 */
		private function scope(string $uid, bool $enabled, ?string &$scopedAs): FlowRunAsScope {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn($enabled);

			$manager = $this->createMock(IUserManager::class);
			$manager->method('get')->willReturnCallback(
				static function (string $asked) use ($uid, $user): ?IUser {
					if ($asked === $uid) {
						return $user;
					}

					return null;
				}
			);

			$objects = $this->createMock(ObjectService::class);
			$objects->method('runAs')->willReturnCallback(
				static function (IUser $user, callable $operation) use (&$scopedAs): mixed {
					$scopedAs = $user->getUID();

					return $operation();
				}
			);

			return new FlowRunAsScope(userManager: $manager, objectService: $objects);
		}//end scope()

		/**
		 * THE SEAM ITSELF: a contributed node's work runs inside
		 * `ObjectService::runAs()` scoped to the run's identity — the node wrote
		 * no wrapper and does not know one exists.
		 */
		public function testAContributedNodesWorkExecutesAsTheRunOwner(): void {
			$node = new ContributedRecordingNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'alice', enabled: true, scopedAs: $scopedAs));

			$out = $dispatcher->dispatch(
				['id' => 'n1', 'type' => 'dossiq.write'],
				[['case' => 1]],
				[FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']
			);

			$this->assertSame('alice', $scopedAs, 'the node must have run inside runAs(alice)');
			$this->assertTrue($node->executedInsideScope, 'the WORK, not just the dispatch, must sit inside the scope');
			$this->assertSame([['case' => 1, 'wrote' => true]], $out);
		}//end testAContributedNodesWorkExecutesAsTheRunOwner()

		/**
		 * 🔴 An identity that resolves to nobody refuses the step LOUDLY — the
		 * silent alternative is the node writing as whoever the ambient session
		 * carries, which under the worker is nobody and on a request is somebody
		 * else.
		 */
		public function testAnUnresolvableIdentityRefusesLoudly(): void {
			$node = new ContributedRecordingNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'alice', enabled: true, scopedAs: $scopedAs));

			try {
				$dispatcher->dispatch(
					['id' => 'n1', 'type' => 'dossiq.write'],
					[],
					[FlowRunService::RUN_AS_CONTEXT_KEY => 'ghost']
				);
				$this->fail('Expected the step to be refused.');
			} catch (RuntimeException $refused) {
				$this->assertStringContainsString('ghost', $refused->getMessage());
			}

			$this->assertFalse($node->executedInsideScope, 'a refused step must not have executed');
		}//end testAnUnresolvableIdentityRefusesLoudly()

		/**
		 * 🔴 A DISABLED account refuses too: a run parked for weeks must not
		 * resume with the rights of somebody who has since been offboarded.
		 */
		public function testADisabledIdentityRefusesLoudly(): void {
			$node = new ContributedRecordingNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'former', enabled: false, scopedAs: $scopedAs));

			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessageMatches('/disabled/');

			$dispatcher->dispatch(
				['id' => 'n1', 'type' => 'dossiq.write'],
				[],
				[FlowRunService::RUN_AS_CONTEXT_KEY => 'former']
			);
		}//end testADisabledIdentityRefusesLoudly()

		/**
		 * A run that names NO identity runs the node bare — the interactive
		 * path, where the ambient session already answers the permission checks.
		 */
		public function testNoIdentityRunsTheNodeBare(): void {
			$node = new ContributedRecordingNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'alice', enabled: true, scopedAs: $scopedAs));

			$dispatcher->dispatch(['id' => 'n1', 'type' => 'dossiq.write'], [], []);

			$this->assertNull($scopedAs, 'with no runAs there is nothing to scope to');
			$this->assertTrue($node->executedInsideScope);
		}//end testNoIdentityRunsTheNodeBare()

		/**
		 * THE BOUNDARY: the engine's own nodes scope themselves — with their own
		 * validation wording and skip-when semantics — and are NOT wrapped, even
		 * when the run names an identity the scope would refuse. Were the
		 * dispatcher wrapping them, this dispatch would throw on the
		 * unresolvable uid; instead the node runs and handles its own identity.
		 */
		public function testAnEngineOwnedNodeIsNotWrapped(): void {
			$node = new \OCA\OpenRegister\Tests\Unit\Service\Flow\EngineNamespacedRecordingNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'alice', enabled: true, scopedAs: $scopedAs));

			$dispatcher->dispatch(
				['id' => 'n1', 'type' => 'openregister.write'],
				[],
				[FlowRunService::RUN_AS_CONTEXT_KEY => 'ghost']
			);

			$this->assertNull($scopedAs, 'an engine-owned node manages its own identity');
			$this->assertTrue($node->executedInsideScope);
		}//end testAnEngineOwnedNodeIsNotWrapped()

		/**
		 * The ESCAPE HATCH: a contributed node declaring IFlowSelfScopedNode
		 * runs bare and takes on the obligations the interface documents.
		 */
		public function testASelfScopedContributedNodeIsNotWrapped(): void {
			$node = new SelfScopedContributedNode();
			$scopedAs = null;

			$dispatcher = $this->dispatcher(node: $node, scope: $this->scope(uid: 'alice', enabled: true, scopedAs: $scopedAs));

			$dispatcher->dispatch(
				['id' => 'n1', 'type' => 'dossiq.system'],
				[],
				[FlowRunService::RUN_AS_CONTEXT_KEY => 'ghost']
			);

			$this->assertNull($scopedAs);
			$this->assertTrue($node->executedInsideScope);
		}//end testASelfScopedContributedNodeIsNotWrapped()

		/**
		 * A dispatcher built by hand with NO scope — the flow tester, the node
		 * unit tests — keeps dispatching bare. The harness's existing contract,
		 * unchanged; every production construction site supplies a scope.
		 */
		public function testAScopelessDispatcherRunsBare(): void {
			$node = new ContributedRecordingNode();

			$dispatcher = $this->dispatcher(node: $node, scope: null);

			$dispatcher->dispatch(
				['id' => 'n1', 'type' => 'dossiq.write'],
				[],
				[FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']
			);

			$this->assertTrue($node->executedInsideScope);
		}//end testAScopelessDispatcherRunsBare()
	}//end class

	/**
	 * A contributed node: any class OUTSIDE `OCA\OpenRegister\`. Records that
	 * (and with what) it executed, standing in for a leaf app's write node.
	 */
	class ContributedRecordingNode implements IFlowNode {

		/**
		 * Whether execute() ran.
		 *
		 * @var boolean
		 */
		public bool $executedInsideScope = false;

		public function getId(): string {
			return 'dossiq.write';
		}//end getId()

		public function getDisplayName(): string {
			return 'Contributed write';
		}//end getDisplayName()

		public function getDescription(): string {
			return 'A leaf app node that writes.';
		}//end getDescription()

		public function getIcon(): string {
			return 'icon-edit';
		}//end getIcon()

		public function isAvailableForScope(int $scope): bool {
			return true;
		}//end isAvailableForScope()

		public function validateConfig(array $config): void {
		}//end validateConfig()

		public function execute(array $items, array $config, array $context): array {
			$this->executedInsideScope = true;

			return array_map(
				static function (array $item): array {
					$item['wrote'] = true;

					return $item;
				},
				$items
			);
		}//end execute()
	}//end class

	/**
	 * The escape hatch declared: still contributed, but self-scoped.
	 */
	class SelfScopedContributedNode extends ContributedRecordingNode implements \OCA\OpenRegister\Service\Flow\IFlowSelfScopedNode {

	}//end class
}//end namespace

namespace OCA\OpenRegister\Tests\Unit\Service\Flow {

	/**
	 * A node under `OCA\OpenRegister\` — the dispatcher must treat it as
	 * engine-owned and leave its identity handling to the node itself.
	 */
	class EngineNamespacedRecordingNode extends \Unit\Service\Flow\ContributedRecordingNode {

	}//end class
}//end namespace
