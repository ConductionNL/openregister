<?php

/**
 * Golden test: the two human-task nodes' palette surface, form and validation.
 *
 * Recorded on the code before their config forms were built through
 * TaskFormFields, so a green run proves the editor and the preflight see
 * exactly the fields, key order and refusals they saw before.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskServices;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins the human-task nodes' observable palette and validation behaviour.
 */
final class HumanTaskNodesGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Configs both nodes are validated against.
	 *
	 * @return array<string, array> Label => config.
	 */
	private function configs(): array {
		$base = ['title' => 'Approve {{ name }}', 'assignee' => 'alice'];
		return [
			'empty' => [],
			'minimal' => $base,
			'no-title' => ['assignee' => 'alice'],
			'candidates' => ['title' => 't', 'candidates' => ['group:staff']],
			'bad-performer' => $base + ['performerType' => 'robot'],
			'agent' => ['title' => 't', 'performerType' => 'agent', 'prompt' => 'do it'],
			'bad-routing' => $base + ['routingStrategy' => 'coin-flip'],
			'bad-timeout' => $base + ['onTimeout' => 'explode'],
			'good-timeout' => $base + ['onTimeout' => 'skip', 'expiresAt' => '+3 days'],
			'bad-reject' => $base + ['onReject' => 'shrug'],
			'bad-heartbeat' => $base + ['heartbeatMinutes' => 'often'],
			'zero-heartbeat' => $base + ['heartbeatMinutes' => 0],
			'bad-advance' => $base + ['advance' => 'lots'],
			'all-advance' => $base + ['advance' => 'all'],
			'outcomes' => $base + ['outcomes' => 'approved, rejected', 'outcomeKey' => 'review'],
			'bad-outcomes' => $base + ['outcomes' => 5],
			'upload' => $base + ['uploadRequired' => true, 'uploadMaxFiles' => 3, 'uploadAcceptedTypes' => 'application/pdf, docx', 'uploadMaxSizeMb' => 5],
			'bad-upload' => $base + ['uploadMaxFiles' => 'many', 'uploadMaxSizeMb' => -1],
			'party' => ['title' => 't', 'partyRole' => 'initiator'],
			'priority' => $base + ['priority' => 'urgent'],
			'form-fields' => $base + ['formKind' => 'fields', 'formFields' => 'reason*'],
			'form-unknown' => $base + ['formKind' => 'paper'],
			'sla' => $base + ['sla' => 'P3D', 'calendar' => 'nl', 'ladder' => [['after' => 'P1D', 'action' => 'remind']]],
			'purpose' => $base + ['purpose' => 'case-handling', 'legalEffect' => true, 'onExpiry' => 'escalate'],
		];
	}//end configs()

	/**
	 * The palette surface, form and validation outcomes of both nodes match the recording.
	 *
	 * @return void
	 */
	public function testHumanTaskNodesMatchTheirRecording(): void {
		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$forms = new TaskFormReader(
			$this->createMock(SchemaMapper::class),
			$this->createMock(TransitionEngine::class),
			$this->createMock(IAppManager::class),
			$l10n
		);
		$nodes = [
			new UserTaskNode(
				new UserTaskServices($this->createMock(FlowTaskBridge::class), $forms, $this->createMock(FlowTimerService::class)),
				new FlowNodePresentation($l10n, $urls)
			),
			new PortalTaskNode(
				new PortalTaskServices($this->createMock(FlowTaskBridge::class), $this->createMock(PortalPartyResolver::class), $this->createMock(PortalTaskDeliveryService::class)),
				new FlowNodePresentation($l10n, $urls)
			),
		];

		$actual = [];
		foreach ($nodes as $node) {
			$actual[] = $this->surface(node: $node, configs: $this->configs());
		}

		$this->assertGolden(name: 'HumanTaskNodes', actual: $actual);
	}//end testHumanTaskNodesMatchTheirRecording()
}//end class
