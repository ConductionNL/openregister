<?php

/**
 * The node catalogue offers the rule-parity nodes to the authoring palette.
 *
 * The palette is what an author picks steps from, and a node that is
 * registered but missing from it cannot be placed in a flow from the editor.
 * These run the real nodes through the real registry, so a node whose palette
 * metadata throws (and is therefore dropped) fails here rather than in a
 * browser.
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
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\MappingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeTaxonomyResolver
 * @uses   \OCA\OpenRegister\Service\Flow\FlowTaxonomyVocabulary
 * @uses   \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\MapNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeBase
 */
class FlowNodeCatalogueParityNodesTest extends TestCase {

	/**
	 * A registry whose contribution event registers the real map and audit-trail nodes.
	 *
	 * @return FlowNodeRegistry The registry.
	 */
	private function registry(): FlowNodeRegistry {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);

		$nodes = [
			new MapNode($l10n, $urls, $this->createMock(MappingService::class), $this->createMock(MappingMapperInterface::class)),
			new AuditTrailNode(
				objects: $this->createMock(ObjectOperationsInterface::class),
				userManager: $this->createMock(IUserManager::class),
				presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)
			),
		];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($nodes): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					foreach ($nodes as $node) {
						$event->registerNode($node);
					}
				}
			}
		);

		return new FlowNodeRegistry($dispatcher, $logger, $urls);
	}//end registry()

	/**
	 * The palette entry for one type, keyed by id.
	 *
	 * @param int $scope The workflow scope.
	 *
	 * @return array<string, array<string, mixed>> Entries by id.
	 */
	private function palette(int $scope): array {
		return array_column($this->registry()->palette(scope: $scope), null, 'id');
	}//end palette()

	public function testTheMapNodeIsOfferedInBothScopesWithItsConfigKey(): void {
		foreach ([IManager::SCOPE_ADMIN, IManager::SCOPE_USER] as $scope) {
			$palette = $this->palette(scope: $scope);
			$this->assertArrayHasKey('openregister.map', $palette, 'the authoring palette does not offer the map node');
			$this->assertSame('Map', $palette['openregister.map']['displayName']);
			$this->assertSame('step', $palette['openregister.map']['role']);
			$this->assertSame(['mapping'], $palette['openregister.map']['configKeys']);
			$this->assertSame('/core/img/actions/rename.svg', $palette['openregister.map']['icon']);
		}
	}

	public function testTheAuditTrailNodeIsOfferedInBothScopesWithItsConfigKeys(): void {
		foreach ([IManager::SCOPE_ADMIN, IManager::SCOPE_USER] as $scope) {
			$palette = $this->palette(scope: $scope);
			$this->assertArrayHasKey('openregister.audit-trail', $palette, 'the authoring palette does not offer the audit-trail node');
			$this->assertSame('Read audit trail', $palette['openregister.audit-trail']['displayName']);
			$this->assertSame('step', $palette['openregister.audit-trail']['role']);
			$this->assertSame(['object', 'entry', 'output'], $palette['openregister.audit-trail']['configKeys']);
		}
	}
}
