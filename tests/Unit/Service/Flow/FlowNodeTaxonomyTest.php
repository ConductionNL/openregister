<?php

/**
 * What kind of step a node is, and where an author finds it.
 *
 * 🔴 THE POINT OF THESE TESTS IS THAT UNDECLARED IS SURVIVABLE. On a measured
 * instance 43 of 64 step types come from apps in other repositories, on their
 * own release cycles. A node that declares neither must still register, still
 * appear in the catalogue, and say `serviceTask` / `other` — visibly, so its
 * owner can fix it, rather than being guessed at, which produces a wrong BPMN
 * element type nobody ever revisits because it looks answered.
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
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A node that declares nothing about itself beyond the base contract.
 *
 * Stands for every node in another repository that has not opted in.
 */
class UndeclaredNode implements IFlowNode {

	/**
	 * Constructor.
	 *
	 * @param string $id The type id.
	 */
	public function __construct(private readonly string $id = 'other.undeclared') {

	}//end __construct()

	/**
	 * The type id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return $this->id;
	}//end getId()

	/**
	 * The display name.
	 *
	 * @return string The name.
	 */
	public function getDisplayName(): string {
		return 'Undeclared';
	}//end getDisplayName()

	/**
	 * The description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return 'Declares no taxonomy.';
	}//end getDescription()

	/**
	 * The icon.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'icon.svg';
	}//end getIcon()

	/**
	 * Availability.
	 *
	 * @param int $scope The scope.
	 *
	 * @return bool Always true.
	 */
	public function isAvailableForScope(int $scope): bool {
		return true;
	}//end isAvailableForScope()

	/**
	 * Validation.
	 *
	 * @param array $config The config.
	 *
	 * @return void
	 */
	public function validateConfig(array $config): void {

	}//end validateConfig()

	/**
	 * Execution.
	 *
	 * @param array $items The items.
	 * @param array $config The config.
	 * @param array $context The context.
	 *
	 * @return array The items, untouched.
	 */
	public function execute(array $items, array $config, array $context): array {
		return $items;
	}//end execute()
}//end class

/**
 * A node that declares both, and a node that declares nonsense.
 */
class DeclaredNode extends UndeclaredNode implements IFlowNodeTaxonomy {

	/**
	 * Constructor.
	 *
	 * @param string $id   The type id.
	 * @param string $kind What it declares as its kind.
	 * @param string $cat  What it declares as its category.
	 */
	public function __construct(
		string $id = 'other.declared',
		private readonly string $kind = IFlowNodeTaxonomy::KIND_USER_TASK,
		private readonly string $cat = IFlowNodeTaxonomy::CATEGORY_HUMAN,
	) {
		parent::__construct($id);

	}//end __construct()

	/**
	 * The declared kind.
	 *
	 * @return string The kind.
	 */
	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * The declared category.
	 *
	 * @return string The category.
	 */
	public function getCategory(): string {
		return $this->cat;
	}//end getCategory()
}//end class

/**
 * Tests for the taxonomy the registry serves.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 * @uses \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent
 */
final class FlowNodeTaxonomyTest extends TestCase {

	/**
	 * A registry holding exactly the given nodes.
	 *
	 * @param array<int, IFlowNode> $nodes  The nodes to register.
	 * @param LoggerInterface|null  $logger A logger to observe, if any.
	 *
	 * @return FlowNodeRegistry The registry.
	 */
	private function registryOf(array $nodes, ?LoggerInterface $logger = null): FlowNodeRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($nodes): void {
				if (($event instanceof RegisterFlowNodesEvent) === false) {
					return;
				}

				foreach ($nodes as $node) {
					$event->registerNode($node);
				}
			}
		);

		return new FlowNodeRegistry($dispatcher, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end registryOf()

	/**
	 * One palette entry, by type id.
	 *
	 * @param FlowNodeRegistry $registry The registry.
	 * @param string           $id       The type.
	 *
	 * @return array<string, mixed>|null The entry.
	 */
	private function entry(FlowNodeRegistry $registry, string $id): ?array {
		foreach ($registry->palette(scope: IManager::SCOPE_ADMIN) as $entry) {
			if (($entry['id'] ?? null) === $id) {
				return $entry;
			}
		}

		return null;
	}//end entry()

	/**
	 * 🔴 A NODE DECLARING NEITHER IS STILL OFFERED, UNDER THE DEFAULTS.
	 *
	 * @return void
	 */
	public function testAnUndeclaredNodeIsStillOfferedAsServiceTaskAndOther(): void {
		$entry = $this->entry($this->registryOf([new UndeclaredNode()]), 'other.undeclared');

		$this->assertNotNull($entry, 'a node that declares no taxonomy must not vanish from the palette');
		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $entry['kind']);
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OTHER, $entry['category']);
	}//end testAnUndeclaredNodeIsStillOfferedAsServiceTaskAndOther()

	/**
	 * A node that declares both is served what it declared.
	 *
	 * @return void
	 */
	public function testADeclaredNodeIsServedItsOwnKindAndCategory(): void {
		$entry = $this->entry($this->registryOf([new DeclaredNode()]), 'other.declared');

		$this->assertSame(IFlowNodeTaxonomy::KIND_USER_TASK, $entry['kind']);
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_HUMAN, $entry['category']);
	}//end testADeclaredNodeIsServedItsOwnKindAndCategory()

	/**
	 * 🔴 A TYPO IS NOT SERVED. It falls back and says so in the log.
	 *
	 * A node declaring `userTsak` would otherwise carry its typo into a BPMN
	 * export, where it is an element type nothing recognises, and grow a
	 * palette group of one that no author asked for.
	 *
	 * @return void
	 */
	public function testAValueOutsideTheVocabularyIsRefusedAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$seen = [];
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$seen): void {
				$seen[] = $message;
			}
		);

		$registry = $this->registryOf(
			[new DeclaredNode('other.typo', 'userTsak', 'humann')],
			$logger
		);
		$entry = $this->entry($registry, 'other.typo');

		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $entry['kind']);
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OTHER, $entry['category']);
		$this->assertNotSame([], $seen, 'the refusal must be visible, or the typo is silent');
		$this->assertStringContainsString('userTsak', implode(' ', $seen));
	}//end testAValueOutsideTheVocabularyIsRefusedAndLogged()

	/**
	 * Two nodes of one kind can sit in different categories, and vice versa.
	 *
	 * The independence of the two axes is the whole design, so it is asserted
	 * rather than assumed.
	 *
	 * @return void
	 */
	public function testTheTwoAxesAreIndependent(): void {
		$registry = $this->registryOf(
			[
				new DeclaredNode('other.mail', IFlowNodeTaxonomy::KIND_SEND_TASK, IFlowNodeTaxonomy::CATEGORY_MESSAGING),
				new DeclaredNode('other.push', IFlowNodeTaxonomy::KIND_SEND_TASK, IFlowNodeTaxonomy::CATEGORY_MESSAGING),
				new DeclaredNode('other.ask', IFlowNodeTaxonomy::KIND_USER_TASK, IFlowNodeTaxonomy::CATEGORY_HUMAN),
				new DeclaredNode('other.await', IFlowNodeTaxonomy::KIND_RECEIVE_TASK, IFlowNodeTaxonomy::CATEGORY_HUMAN),
			]
		);

		// One kind, one category: both send steps are found in the same place.
		$this->assertSame('messaging', $this->entry($registry, 'other.mail')['category']);
		$this->assertSame('messaging', $this->entry($registry, 'other.push')['category']);

		// One category, two kinds: the pair an author chooses between stays
		// together, and each keeps its own semantics.
		$this->assertSame('human', $this->entry($registry, 'other.ask')['category']);
		$this->assertSame('human', $this->entry($registry, 'other.await')['category']);
		$this->assertSame('userTask', $this->entry($registry, 'other.ask')['kind']);
		$this->assertSame('receiveTask', $this->entry($registry, 'other.await')['kind']);
	}//end testTheTwoAxesAreIndependent()

	/**
	 * The category order is fixed, and `other` is last.
	 *
	 * Registration order depends on which apps are installed and in what order
	 * their listeners fire, so a palette ordered by registration reorders
	 * itself when an unrelated app is enabled.
	 *
	 * @return void
	 */
	public function testTheCategoryOrderIsFixedAndOtherIsLast(): void {
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_TRIGGERS, IFlowNodeTaxonomy::CATEGORIES[0]);
		$this->assertSame(
			IFlowNodeTaxonomy::CATEGORY_OTHER,
			IFlowNodeTaxonomy::CATEGORIES[(count(IFlowNodeTaxonomy::CATEGORIES) - 1)]
		);
		$this->assertCount(
			count(array_unique(IFlowNodeTaxonomy::CATEGORIES)),
			IFlowNodeTaxonomy::CATEGORIES,
			'a repeated category would render one palette group twice'
		);
	}//end testTheCategoryOrderIsFixedAndOtherIsLast()

	/**
	 * The kinds are exactly BPMN's, with nothing of our own added.
	 *
	 * The vocabulary's whole value is that an interchange already speaks it. A
	 * local synonym would have to be mapped back on export, and the mapping is
	 * the part that rots.
	 *
	 * @return void
	 */
	public function testTheKindVocabularyIsBpmnsAndClosed(): void {
		$this->assertSame(
			[
				'businessRuleTask',
				'event',
				'gateway',
				'manualTask',
				'receiveTask',
				'scriptTask',
				'sendTask',
				'serviceTask',
				'subProcess',
				'userTask',
			],
			$this->sorted(IFlowNodeTaxonomy::KINDS)
		);
	}//end testTheKindVocabularyIsBpmnsAndClosed()

	/**
	 * A sorted copy, so the assertion above does not depend on declaration order.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<int, string> Sorted.
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}//end sorted()
}//end class
