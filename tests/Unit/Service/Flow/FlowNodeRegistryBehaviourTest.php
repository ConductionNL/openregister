<?php

/**
 * Unit test for FlowNodeRegistry: which node types it refuses, what a palette
 * entry carries, how icons and log actions fail soft, renamed types, and the
 * role, end and trigger answers for unknown and blank types.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\IFlowEndNode;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCA\OpenRegister\Service\Flow\IFlowNodeRequiresRight;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeTaxonomyResolver
 * @uses   \OCA\OpenRegister\Service\Flow\FlowTaxonomyVocabulary
 * @uses   \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent
 */
class FlowNodeRegistryBehaviourTest extends TestCase {

	/**
	 * The event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mocked dispatcher and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A node of the given id, with the given extra interfaces.
	 *
	 * @param string             $id         The type id.
	 * @param array<int, string> $interfaces Extra interfaces the node implements.
	 *
	 * @return IFlowNode&MockObject
	 */
	private function node(string $id, array $interfaces=[]): IFlowNode&MockObject {
		$node = ($interfaces === [])
			? $this->createMock(IFlowNode::class)
			: $this->createMockForIntersectionOfInterfaces(array_merge([IFlowNode::class], $interfaces));
		$node->method('getId')->willReturn($id);
		$node->method('getDisplayName')->willReturn('Name of '.$id);
		$node->method('getDescription')->willReturn('About '.$id);
		$node->method('isAvailableForScope')->willReturn(true);
		return $node;
	}//end node()

	/**
	 * A registry with the given url generator.
	 *
	 * @param IURLGenerator|null $urls The url generator.
	 *
	 * @return FlowNodeRegistry
	 */
	private function registry(?IURLGenerator $urls=null): FlowNodeRegistry {
		return new FlowNodeRegistry(dispatcher: $this->dispatcher, logger: $this->logger, urls: $urls);
	}//end registry()

	/**
	 * The renamed types are the interface's map.
	 *
	 * @return void
	 */
	public function testRenamedTypesAreTheInterfacesMap(): void {
		$this->assertSame(FlowNodeRegistryInterface::RENAMED_TYPES, FlowNodeRegistry::renamedTypes());
		$this->assertSame('openregister.end', FlowNodeRegistry::renamedTypes()['openregister.stop']);
	}//end testRenamedTypesAreTheInterfacesMap()

	/**
	 * A node with an empty id is refused with a warning and not registered.
	 *
	 * @return void
	 */
	public function testANodeWithAnEmptyIdIsRefused(): void {
		$this->logger->expects($this->once())->method('warning')->with('[FlowNodeRegistry] Refusing a node type with an empty id');
		$registry = $this->registry();
		$registry->register(node: $this->node(id: ''));

		$this->assertSame([], $registry->all());
	}//end testANodeWithAnEmptyIdIsRefused()

	/**
	 * The first node of an id wins and a duplicate is refused with a warning.
	 *
	 * @return void
	 */
	public function testADuplicateIsRefusedAndTheFirstKept(): void {
		$first  = $this->node(id: 'x.a');
		$second = $this->node(id: 'x.a');
		$this->logger->expects($this->once())->method('warning')->with('[FlowNodeRegistry] Refusing a duplicate flow node type');
		$registry = $this->registry();
		$registry->register(node: $first);
		$registry->register(node: $second);

		$this->assertSame(['x.a' => $first], $registry->all());
	}//end testADuplicateIsRefusedAndTheFirstKept()

	/**
	 * Loading dispatches the registration event once, however often the registry is read.
	 *
	 * @return void
	 */
	public function testLoadingDispatchesOnce(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with($this->isInstanceOf(RegisterFlowNodesEvent::class));
		$registry = $this->registry();
		$registry->all();
		$registry->all(scope: 0);
		$this->assertSame([], $registry->palette());
	}//end testLoadingDispatchesOnce()

	/**
	 * A palette entry carries role, kind, category, aliases, config keys and form, and the node's icon.
	 *
	 * @return void
	 */
	public function testAPaletteEntryCarriesTheDeclarations(): void {
		$end = $this->node(id: 'openregister.end', interfaces: [IFlowEndNode::class, IFlowNodeConfigKeys::class, IFlowNodeConfigForm::class]);
		$end->method('getIcon')->willReturn('/icons/end.svg');
		$end->method('configKeys')->willReturn(['a' => 'reason']);
		$end->method('configForm')->willReturn(['f' => ['key' => 'reason']]);
		$registry = $this->registry();
		$registry->register(node: $end);

		$this->assertSame(
			[
				[
					'id'          => 'openregister.end',
					'displayName' => 'Name of openregister.end',
					'description' => 'About openregister.end',
					'icon'        => '/icons/end.svg',
					'role'        => 'end',
					'kind'        => 'serviceTask',
					'category'    => 'other',
					'aliases'     => ['openregister.stop'],
					'requiresRight' => null,
					'configKeys'  => ['reason'],
					'configForm'  => [['key' => 'reason']],
				],
			],
			$registry->palette()
		);
	}//end testAPaletteEntryCarriesTheDeclarations()

	/**
	 * A node that needs a right says which in its palette entry, as placed with no configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testAPaletteEntryCarriesTheRightTheNodeRequires(): void {
		$mail = $this->node(id: 'openregister.send-email', interfaces: [IFlowNodeRequiresRight::class]);
		$mail->expects($this->once())->method('requiredRight')->with([])->willReturn('flow.node.send-email');
		$registry = $this->registry();
		$registry->register(node: $mail);
		$registry->register(node: $this->node(id: 'openregister.set-fields'));

		$rights = array_column($registry->palette(), 'requiresRight', 'id');

		$this->assertSame(['openregister.send-email' => 'flow.node.send-email', 'openregister.set-fields' => null], $rights);
	}//end testAPaletteEntryCarriesTheRightTheNodeRequires()

	/**
	 * A node whose icon is missing is served the app icon and logged; without a url generator the icon is null.
	 *
	 * @return void
	 */
	public function testAMissingIconFallsBackToTheAppIcon(): void {
		$node = $this->node(id: 'x.step');
		$node->method('getIcon')->willThrowException(new RuntimeException('no such image'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('openregister', 'app-dark.svg')->willReturn('/apps/openregister/img/app-dark.svg');
		$this->logger->expects($this->exactly(3))->method('error');

		$registry = $this->registry(urls: $urls);
		$registry->register(node: $node);
		$this->assertSame('/apps/openregister/img/app-dark.svg', $registry->palette()[0]['icon']);
		$this->assertArrayNotHasKey('configKeys', $registry->palette()[0]);

		$bare = $this->registry();
		$bare->register(node: $node);
		$this->assertNull($bare->palette()[0]['icon']);
	}//end testAMissingIconFallsBackToTheAppIcon()

	/**
	 * A fallback icon that cannot be found either is null.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFallbackIconIsNull(): void {
		$node = $this->node(id: 'x.step');
		$node->method('getIcon')->willThrowException(new RuntimeException('no such image'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willThrowException(new RuntimeException('no app-dark'));

		$registry = $this->registry(urls: $urls);
		$registry->register(node: $node);
		$this->assertNull($registry->palette()[0]['icon']);
	}//end testAnUnresolvableFallbackIconIsNull()

	/**
	 * A node whose metadata throws is dropped from the palette with an error, and the others stay.
	 *
	 * @return void
	 */
	public function testANodeWhoseMetadataThrowsIsDropped(): void {
		$bad = $this->createMock(IFlowNode::class);
		$bad->method('getId')->willReturn('x.bad');
		$bad->method('isAvailableForScope')->willReturn(true);
		$bad->method('getDisplayName')->willThrowException(new RuntimeException('l10n missing'));
		$good = $this->node(id: 'x.good');
		$good->method('getIcon')->willReturn('i');
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('DROPPED the node "x.bad" from the palette: its metadata threw (l10n missing)')
		);
		$registry = $this->registry();
		$registry->register(node: $bad);
		$registry->register(node: $good);

		$this->assertSame(['x.good'], array_column($registry->palette(), 'id'));
	}//end testANodeWhoseMetadataThrowsIsDropped()

	/**
	 * The palette for a scope holds only the nodes available in it.
	 *
	 * @return void
	 */
	public function testTheScopeFiltersTheNodes(): void {
		$admin = $this->createMock(IFlowNode::class);
		$admin->method('getId')->willReturn('x.admin');
		$admin->method('isAvailableForScope')->willReturnCallback(fn (int $scope): bool => $scope === 0);
		$registry = $this->registry();
		$registry->register(node: $admin);

		$this->assertSame(['x.admin'], array_keys($registry->all(scope: 0)));
		$this->assertSame([], $registry->all(scope: 1));
	}//end testTheScopeFiltersTheNodes()

	/**
	 * Log actions come from a node that declares them; blank, unknown and undeclaring types have none.
	 *
	 * @return void
	 */
	public function testLogActionsComeOnlyFromADeclaringNode(): void {
		$logs = $this->node(id: 'x.logs', interfaces: [IFlowNodeLogActions::class]);
		$logs->method('logActions')->willReturnCallback(fn (array $entry): array => ['k' => ['label' => 'Open '.$entry['id']]]);
		$this->logger->expects($this->never())->method('warning');
		$registry = $this->registry();
		$registry->register(node: $logs);
		$registry->register(node: $this->node(id: 'x.plain'));

		$this->assertSame([['label' => 'Open e1']], $registry->logActions(entry: ['type' => ' x.logs ', 'id' => 'e1']));
		$this->assertSame([], $registry->logActions(entry: ['type' => '  ']));
		$this->assertSame([], $registry->logActions(entry: ['type' => 'x.none']));
		$this->assertSame([], $registry->logActions(entry: ['type' => 'x.plain']));
	}//end testLogActionsComeOnlyFromADeclaringNode()

	/**
	 * Log actions that throw are none, with a warning.
	 *
	 * @return void
	 */
	public function testFailingLogActionsAreNone(): void {
		$logs = $this->node(id: 'x.logs', interfaces: [IFlowNodeLogActions::class]);
		$logs->method('logActions')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')->with('[FlowNodeRegistry] A node\'s log actions failed: boom');
		$registry = $this->registry();
		$registry->register(node: $logs);

		$this->assertSame([], $registry->logActions(entry: ['type' => 'x.logs']));
	}//end testFailingLogActionsAreNone()

	/**
	 * A renamed type resolves to its new node with an info log; an unknown type is refused.
	 *
	 * @return void
	 */
	public function testARenamedTypeResolvesAndAnUnknownOneIsRefused(): void {
		$end = $this->node(id: 'openregister.end', interfaces: [IFlowEndNode::class]);
		$this->logger->expects($this->once())->method('info')->with($this->stringContains('"openregister.stop" was renamed to "openregister.end"'));
		$registry = $this->registry();
		$registry->register(node: $end);

		$this->assertSame($end, $registry->get(type: 'openregister.stop'));
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('No app provides the flow node type "x.nope".');
		$registry->get(type: 'x.nope');
	}//end testARenamedTypeResolvesAndAnUnknownOneIsRefused()

	/**
	 * End, trigger and role answer for registered types, and default for blank and unknown ones.
	 *
	 * @return void
	 */
	public function testEndTriggerAndRole(): void {
		$registry = $this->registry();
		$registry->register(node: $this->node(id: 'x.end', interfaces: [IFlowEndNode::class]));
		$registry->register(node: $this->node(id: 'x.start', interfaces: [IFlowTriggerNode::class]));
		$registry->register(node: $this->node(id: 'x.step'));

		$this->assertTrue($registry->isEnd(type: 'x.end'));
		$this->assertFalse($registry->isEnd(type: 'x.step'));
		$this->assertFalse($registry->isEnd(type: ' '));
		$this->assertFalse($registry->isEnd(type: 'x.nope'));
		$this->assertTrue($registry->isTrigger(type: 'x.start'));
		$this->assertFalse($registry->isTrigger(type: 'x.end'));
		$this->assertFalse($registry->isTrigger(type: ''));
		$this->assertFalse($registry->isTrigger(type: 'x.nope'));
		$this->assertSame('trigger', $registry->roleOf(type: 'x.start'));
		$this->assertSame('end', $registry->roleOf(type: 'x.end'));
		$this->assertSame('step', $registry->roleOf(type: ''));
		$this->assertSame('step', $registry->roleOf(type: 'x.nope'));
	}//end testEndTriggerAndRole()
}//end class
