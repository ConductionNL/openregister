<?php

/**
 * Golden test: the three trigger nodes' surface, validation and pass-through.
 *
 * Recorded on the code before the trigger nodes shared a base class.
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

use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins the trigger nodes' observable behaviour.
 */
final class TriggerNodesGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Configs every trigger node is validated against.
	 *
	 * @return array<string, array> Label => config.
	 */
	private function configs(): array {
		return [
			'empty' => [],
			'next-null' => ['next' => null],
			'next-good' => ['next' => 'list'],
			'next-bad' => ['next' => 'later'],
			'event-unknown' => ['event' => 'object.moved', 'register' => 'r', 'schema' => 's'],
			'no-register' => ['event' => 'object.created', 'schema' => 's'],
			'no-schema' => ['event' => 'object.updated', 'register' => 'r'],
			'good-object' => ['event' => 'object.deleted', 'register' => 'r', 'schema' => 's'],
			'bad-scope' => ['event' => 'object.created', 'register' => 'r', 'schema' => 's', 'scope' => 'x'],
			'good-scope' => ['event' => 'object.created', 'register' => 'r', 'schema' => 's', 'scope' => ['property' => 'p', 'value' => 'v']],
			'cron-short' => ['cron' => '* * *', 'runAs' => 'alice'],
			'cron-no-runas' => ['cron' => '0 * * * *'],
			'cron-unknown-user' => ['cron' => '0 * * * *', 'runAs' => 'mallory'],
			'cron-good' => ['cron' => ' 0 * * * * ', 'runAs' => ' alice '],
		];
	}//end configs()

	/**
	 * The surface, validation and pass-through of every trigger node match the recording.
	 *
	 * @return void
	 */
	public function testTriggerNodesMatchTheirRecording(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null
		);
		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$nodes = [
			new TriggerManualNode($l10n, $urls),
			new TriggerObjectNode($l10n, $urls),
			new TriggerScheduleNode(new FlowNodePresentation($l10n, $urls), $users),
		];

		$items = [['json' => ['a' => 1]]];
		$actual = [];
		foreach ($nodes as $node) {
			$surface = $this->surface(node: $node, configs: $this->configs());
			$surface['execute'] = $node->execute($items, ['x' => 1], ['runId' => 1]);
			$surface['events'] = defined(get_class($node) . '::EVENTS') ? constant(get_class($node) . '::EVENTS') : null;
			$actual[] = $surface;
		}

		$this->assertGolden(name: 'TriggerNodes', actual: $actual);
	}//end testTriggerNodesMatchTheirRecording()
}//end class
