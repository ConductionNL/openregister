<?php

/**
 * Golden test: the object-category nodes' palette surface and validation.
 *
 * Recorded on the code before Transition, LinkObject, ObjectRead, ObjectWrite
 * and Loop shared ObjectNodeBase.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins the object nodes' observable palette and validation behaviour.
 */
final class ObjectNodesGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Configs every object node is validated against.
	 *
	 * @return array<string, array> Label => config.
	 */
	private function configs(): array {
		return [
			'empty' => [],
			'action' => ['action' => 'approve', 'data' => ['a' => 1]],
			'bad-data' => ['action' => 'approve', 'data' => 'x'],
			'blank-action' => ['action' => ' '],
			'handoff' => ['handoff' => 'h1'],
			'handoff-and-target' => ['handoff' => 'h1', 'register' => 'r', 'schema' => 's'],
			'target' => ['register' => 'r', 'schema' => 's', 'data' => ['x' => 1]],
			'target-bad-data' => ['register' => 'r', 'schema' => 's', 'data' => 3],
			'filters' => ['register' => 'r', 'schema' => 's', 'filters' => ['a' => 1], 'limit' => 10],
			'bad-limit' => ['register' => 'r', 'schema' => 's', 'limit' => 'all'],
			'write-mode' => ['register' => 'r', 'schema' => 's', 'mode' => 'upsert', 'match' => ['id']],
			'bad-mode' => ['register' => 'r', 'schema' => 's', 'mode' => 'shred'],
			'batch' => ['batchSize' => 5],
			'bad-batch' => ['batchSize' => 0],
		];
	}//end configs()

	/**
	 * The palette surface and validation outcomes of every object node match the recording.
	 *
	 * @return void
	 */
	public function testObjectNodesMatchTheirRecording(): void {
		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$users = $this->createMock(IUserManager::class);
		$nodes = [
			new TransitionNode(engine: $this->createMock(TransitionEngine::class), l10n: $l10n, urls: $urls),
			new LinkObjectNode(objects: $objects, handoffs: $this->createMock(HandoffService::class), l10n: $l10n, urls: $urls),
			new ObjectReadNode(objects: $objects, registers: $registers, schemas: $schemas, userManager: $users, l10n: $l10n, urls: $urls),
			new ObjectWriteNode(
				objects: $objects,
				registers: $registers,
				schemas: $schemas,
				userManager: $users,
				appConfig: $this->createMock(IAppConfig::class),
				l10n: $l10n,
				urls: $urls
			),
			new LoopNode(l10n: $l10n, urls: $urls),
		];

		$actual = [];
		foreach ($nodes as $node) {
			$actual[] = $this->surface(node: $node, configs: $this->configs());
		}

		$this->assertGolden(name: 'ObjectNodes', actual: $actual);
	}//end testObjectNodesMatchTheirRecording()
}//end class
