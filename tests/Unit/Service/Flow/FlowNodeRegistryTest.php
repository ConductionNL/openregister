<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Exception\FlowRunExpired;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowEndNode;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/** A node that tags each item it sees. */
class TaggingNode implements IFlowNode {
	public function __construct(
		private readonly string $id = 'test.tag',
		private readonly int $scope = IManager::SCOPE_ADMIN,
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getDisplayName(): string {
		return 'Tag';
	}

	public function getDescription(): string {
		return 'Tags items.';
	}

	public function getIcon(): string {
		return 'icon.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === $this->scope;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$json['taggedBy'] = $this->id;
			$json['label'] = (string)($config['label'] ?? '');
			$out[] = FlowItems::item(json: $json, binary: [], fromItemIndex: $index);
		}

		return $out;
	}
}

/**
 * A node that reliably outlives a one-second ceiling.
 *
 * It sleeps rather than reporting a fake duration, because what is under test is
 * the dispatcher measuring a real call.
 *
 * WAITS ON THE CLOCK, NOT ON ONE usleep() CALL. This was a single
 * `usleep(1_200_000)`, and the comment above it argued that 1.2s against a 1s
 * ceiling "buys enough margin that a loaded machine cannot make this flap".
 * That reasons about the wrong direction: load makes a sleep LONGER, which is
 * the safe way to be wrong. What actually happens is that `usleep()` can return
 * EARLY when a signal arrives — likely on a busy box with many child processes
 * — and then the node finishes inside its ceiling, the dispatcher is right not
 * to raise, and the test fails claiming the timeout is broken.
 *
 * Observed 2026-08-26: green in isolation three times over, red inside the full
 * suite while phpcs and phpmd were saturating the machine.
 *
 * Looping until hrtime() says the target has genuinely elapsed makes the node
 * outlive its ceiling whatever the sleep does.
 */
class SlowNode extends TaggingNode {
	public function __construct() {
		parent::__construct('test.slow');
	}

	public function execute(array $items, array $config, array $context): array {
		$deadline = (hrtime(true) + 1_200_000_000);
		while (hrtime(true) < $deadline) {
			$remaining = (int)(($deadline - hrtime(true)) / 1_000);
			if ($remaining > 0) {
				usleep($remaining);
			}
		}

		return $items;
	}
}

class FlowNodeRegistryTest extends TestCase {
	/**
	 * Build a registry whose contribution event registers the given nodes.
	 *
	 * @param array<IFlowNode> $nodes Nodes to contribute.
	 */
	private function registryWith(array $nodes, ?LoggerInterface $logger = null): FlowNodeRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$registry = new FlowNodeRegistry($dispatcher, ($logger ?? $this->createMock(LoggerInterface::class)));

		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($nodes): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					foreach ($nodes as $node) {
						$event->registerNode($node);
					}
				}
			}
		);

		return $registry;
	}

	public function testAnAppContributesANodeThroughTheEvent(): void {
		$registry = $this->registryWith([new TaggingNode()]);

		$this->assertArrayHasKey('test.tag', $registry->all());
	}

	public function testContributionIsCollectedOnlyOncePerRequest(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped');

		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$registry->all();
		$registry->all();
		$registry->palette();
	}

	/**
	 * Two apps claiming one type must not resolve by load order — whichever
	 * app's code ran would then depend on install order, which is a bug that
	 * only appears on someone else's instance.
	 */
	public function testADuplicateTypeIsRefusedRatherThanOverwriting(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('duplicate'), $this->anything());

		$first = new TaggingNode('test.tag');
		$second = new TaggingNode('test.tag');
		$registry = $this->registryWith([$first, $second], $logger);

		$this->assertSame($first, $registry->get('test.tag'));
	}

	public function testAnUnknownTypeThrowsRatherThanBeingSkipped(): void {
		$registry = $this->registryWith([]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/No app provides the flow node type "ghost.step"/');
		$registry->get('ghost.step');
	}

	public function testThePaletteIsNarrowedToTheRequestedScope(): void {
		$registry = $this->registryWith([
			new TaggingNode('admin.only', IManager::SCOPE_ADMIN),
			new TaggingNode('user.only', IManager::SCOPE_USER),
		]);

		$adminIds = array_column($registry->palette(IManager::SCOPE_ADMIN), 'id');
		$userIds = array_column($registry->palette(IManager::SCOPE_USER), 'id');

		$this->assertSame(['admin.only'], $adminIds);
		$this->assertSame(['user.only'], $userIds);
	}

	/**
	 * A flow stored before the rename still resolves, and says so out loud.
	 *
	 * The alias is the whole reason renaming a node id is safe: nine flows on
	 * the development instance referenced `openregister.stop`, and an export
	 * taken before the rename can be imported at any point in the future. A
	 * silent alias would hide how large that tail is, so resolving through it
	 * is logged.
	 *
	 * @return void
	 */
	public function testARenamedTypeStillResolvesThroughItsAliasAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with($this->stringContains('was renamed to'), $this->anything());

		$end = new TaggingNode('openregister.end');
		$registry = $this->registryWith([$end], $logger);

		$this->assertSame($end, $registry->get('openregister.stop'), 'a flow stored before the rename stopped resolving');
		$this->assertSame($end, $registry->get('openregister.end'));
	}

	/**
	 * The palette PUBLISHES the old ids, so an editor need not keep its own map.
	 *
	 * The engine resolving a renamed type is only half of it: an editor looks a
	 * stored flow's types up in this catalogue to draw them, so without the
	 * aliases a flow saved before the rename renders a raw type id where its
	 * name belongs. The engine runs it perfectly well and only the display
	 * breaks — the sort of half-failure nobody files.
	 *
	 * @return void
	 */
	public function testThePalettePublishesTheOldIdsAnEditorMayStillSee(): void {
		$registry = $this->registryWith([new TaggingNode('openregister.end')]);
		$entry = $registry->palette()[0];

		$this->assertSame(['openregister.stop'], $entry['aliases']);
	}

	public function testThePaletteCarriesTheMetadataAnEditorNeeds(): void {
		$registry = $this->registryWith([new TaggingNode()]);
		$entry = $registry->palette()[0];

		$this->assertSame(['id', 'displayName', 'description', 'icon', 'role', 'aliases'], array_keys($entry));
		$this->assertSame('Tag', $entry['displayName']);
		$this->assertSame([], $entry['aliases'], 'a node that was never renamed carries no aliases');

		// `role` is ALWAYS present, so an editor never has to infer it from the
		// id. A node that marks itself neither trigger nor end is a step.
		$this->assertSame('step', $entry['role']);
	}

	public function testThePaletteReportsTheRoleTheNodeDeclaresRatherThanItsId(): void {
		// Ids that give the naming convention nothing to match on: an editor
		// that inferred the role from the string would call both of these
		// steps.
		$trigger = new class implements IFlowNode, IFlowTriggerNode {
			public function getId(): string {
				return 'other.begins-here';
			}

			public function getDisplayName(): string {
				return 'Begins here';
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

		$end = new class implements IFlowNode, IFlowEndNode {
			public function getId(): string {
				return 'other.ends-here';
			}

			public function getDisplayName(): string {
				return 'Ends here';
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

		$registry = $this->registryWith([$trigger, $end]);
		$roles = [];
		foreach ($registry->palette() as $entry) {
			$roles[$entry['id']] = $entry['role'];
		}

		$this->assertSame('trigger', $roles['other.begins-here'], 'a contributed trigger node was not reported as one');
		$this->assertSame('end', $roles['other.ends-here'], 'a contributed end node was not reported as one');
		$this->assertSame('trigger', $registry->roleOf('other.begins-here'));
		$this->assertSame('end', $registry->roleOf('other.ends-here'));
		$this->assertSame('step', $registry->roleOf('not.registered.at.all'));
	}

	public function testTheDispatcherRoutesAStepToTheNodeThatOwnsItsType(): void {
		$registry = $this->registryWith([new TaggingNode()]);
		$dispatcher = new RegistryStepDispatcher($registry);

		$out = $dispatcher->dispatch(
			['type' => 'test.tag', 'config' => ['label' => 'hello']],
			[FlowItems::item(json: ['a' => 1])],
			[]
		);

		$this->assertSame('test.tag', $out[0]['json']['taggedBy']);
		$this->assertSame('hello', $out[0]['json']['label']);
		$this->assertSame(1, $out[0]['json']['a']);
	}

	/**
	 * An edge drawn purely to shape the graph carries no work. This is NOT
	 * leniency about unknown types — those still throw.
	 */
	public function testAStepWithNoTypePassesItemsThrough(): void {
		$dispatcher = new RegistryStepDispatcher($this->registryWith([]));
		$items = [FlowItems::item(json: ['a' => 1])];

		$this->assertSame($items, $dispatcher->dispatch([], $items, []));
	}

	public function testTheDispatcherPropagatesAnUnknownType(): void {
		$dispatcher = new RegistryStepDispatcher($this->registryWith([]));

		$this->expectException(UnexpectedValueException::class);
		$dispatcher->dispatch(['type' => 'ghost.step'], [], []);
	}

	/**
	 * A node with no `maxRuntimeSeconds` is not on a clock.
	 *
	 * The ceiling is opt-in per step: imposing a default on every node would
	 * fail slow-but-correct integrations that nobody asked to bound.
	 */
	public function testAStepWithNoCeilingIsNotTimed(): void {
		$dispatcher = new RegistryStepDispatcher($this->registryWith([new TaggingNode()]));

		$out = $dispatcher->dispatch(
			['type' => 'test.tag', 'config' => ['label' => 'x']],
			[FlowItems::item(json: [])],
			[]
		);

		$this->assertCount(1, $out);
	}

	/**
	 * A ceiling of zero means "no ceiling", matching the flow-level budget.
	 */
	public function testAZeroNodeCeilingIsNotEnforced(): void {
		$dispatcher = new RegistryStepDispatcher($this->registryWith([new TaggingNode()]));

		$out = $dispatcher->dispatch(
			['type' => 'test.tag', 'config' => ['label' => 'x', 'maxRuntimeSeconds' => 0]],
			[FlowItems::item(json: [])],
			[]
		);

		$this->assertCount(1, $out);
	}

	/**
	 * A step that overruns its own ceiling fails, naming itself.
	 *
	 * The per-node ceiling exists so one slow integration is answerable for
	 * itself, instead of only surfacing an hour later as the whole run expiring
	 * — at which point the log says the run ran out of time and not which of
	 * fifteen steps ate it.
	 *
	 * The node really does take longer than its ceiling — a second of wall clock
	 * rather than a stubbed duration, because the thing under test is the
	 * dispatcher timing a real call. A negative ceiling would not do: values at
	 * or below zero mean "unlimited", as they do for the flow budget.
	 */
	public function testAStepThatOverrunsItsCeilingIsStopped(): void {
		$dispatcher = new RegistryStepDispatcher($this->registryWith([new SlowNode()]));

		$this->expectException(FlowRunExpired::class);
		$this->expectExceptionMessageMatches('/test\.slow/');

		$dispatcher->dispatch(
			['type' => 'test.slow', 'config' => ['maxRuntimeSeconds' => 1]],
			[FlowItems::item(json: [])],
			[]
		);
	}
}
