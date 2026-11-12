<?php

/**
 * Golden test: the three messaging nodes' surface, preview and send calls.
 *
 * Recorded on the code before the messaging nodes shared a base class, so a
 * green run proves the palette, the preflight and the engine see exactly what
 * they saw before.
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
 * @spec openspec/specs/flow-messaging-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\MessageSend;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use PHPUnit\Framework\TestCase;

/**
 * Pins the messaging nodes' observable behaviour.
 */
final class MessagingNodesGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Configs every messaging node is validated against.
	 *
	 * @return array<string, array> Label => config.
	 */
	private function configs(): array {
		return [
			'empty' => [],
			'blank-body' => ['recipients' => ['bob'], 'body' => '  ', 'message' => ' ', 'conversation' => 'tok'],
			'string-recipient' => ['recipients' => 'bob', 'body' => 'b', 'message' => 'm', 'conversation' => 'tok'],
			'blank-recipients' => ['recipients' => ['', ' ', 3], 'body' => 'b', 'message' => 'm', 'conversation' => 'tok'],
			'no-conversation' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm'],
			'bad-external' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm', 'externalRecipients' => 'Some'],
			'good-external' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm', 'externalRecipients' => 'ANY'],
			'bad-category' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm', 'messageCategory' => 'spam'],
			'array-category' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm', 'messageCategory' => ['x']],
			'good-category' => ['recipients' => ['bob'], 'body' => 'b', 'message' => 'm', 'messageCategory' => ' Reminder '],
		];
	}//end configs()

	/**
	 * The surface, preview and send call of every messaging node match the recording.
	 *
	 * @return void
	 */
	public function testMessagingNodesMatchTheirRecording(): void {
		$calls = [];
		$messaging = $this->createMock(FlowMessagingService::class);
		$messaging->method('preview')->willReturnCallback(
			static function (string $channel, MessageSend $step) use (&$calls): array {
				$calls[] = ['previewMessages', $channel, $step->config, $step->items, $step->context];
				return [['to' => 'bob'], ['to' => 'eve']];
			}
		);
		foreach (['email' => 'sendEmail', 'notify' => 'sendNotification', 'talk' => 'sendTalkMessage'] as $method => $send) {
			$messaging->method($method)->willReturnCallback(
				static function (MessageSend $step) use (&$calls, $send): array {
					$calls[] = [$send, $step->config, $step->items, $step->context, $step->stepName];
					return ['sent' => 1];
				}
			);
		}

		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$nodes = [
			new SendEmailNode(messaging: $messaging, presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)),
			new SendNotificationNode(messaging: $messaging, presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)),
			new SendTalkMessageNode(messaging: $messaging, presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)),
		];

		$items = [['json' => ['name' => 'a']], ['json' => ['name' => 'b']]];
		$config = ['recipients' => ['bob'], 'message' => 'hi'];
		$context = ['runId' => 7];
		$actual = [];
		foreach ($nodes as $node) {
			$calls = [];
			$surface = $this->surface(node: $node, configs: $this->configs());
			$surface['preview'] = $node->preview(new FlowNodeCall(items: $items, config: $config, context: $context));
			$surface['execute'] = $node->execute(new FlowNodeCall(items: $items, config: $config, context: $context));
			$surface['calls'] = $calls;
			$actual[] = $surface;
		}

		$this->assertGolden(name: 'MessagingNodes', actual: $actual);
	}//end testMessagingNodesMatchTheirRecording()
}//end class
