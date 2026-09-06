<?php

/**
 * The three messaging step nodes: validation, pass-through, form floor, and
 * the palette boundary (exactly these three; no send-webhook).
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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Listener\FlowNodeRegistrationListener;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use UnexpectedValueException;

/**
 * Node-level behaviour of the messaging palette.
 */
class SendMessagingNodesTest extends TestCase {

	private FlowMessagingService&MockObject $messaging;

	private IL10N&MockObject $l10n;

	private IURLGenerator&MockObject $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->messaging = $this->createMock(FlowMessagingService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf(str_replace(['{example}', '{given}'], '%s', $text), $params)
		);
		$this->urls = $this->createMock(IURLGenerator::class);
	}//end setUp()

	/**
	 * All three nodes, keyed by their type id.
	 *
	 * @return array<string, object> The nodes.
	 */
	private function nodes(): array {
		return [
			SendNotificationNode::TYPE => new SendNotificationNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls),
			SendEmailNode::TYPE => new SendEmailNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls),
			SendTalkMessageNode::TYPE => new SendTalkMessageNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls),
		];
	}//end nodes()

	public function testItemsFlowThroughUnchanged(): void {
		$items = [
			FlowItems::item(json: ['name' => 'a']),
			FlowItems::item(json: ['name' => 'b']),
			FlowItems::item(json: ['name' => 'c']),
		];

		$config = [
			SendNotificationNode::TYPE => ['recipients' => ['bob'], 'message' => 'm'],
			SendEmailNode::TYPE => ['recipients' => ['bob'], 'subject' => 's', 'body' => 'b'],
			SendTalkMessageNode::TYPE => ['conversation' => 't', 'message' => 'm'],
		];

		foreach ($this->nodes() as $type => $node) {
			$out = $node->execute(items: $items, config: $config[$type], context: ['runAs' => 'alice']);
			// Sending is a side effect, not a transformation: the three items
			// come back IDENTICAL for downstream steps.
			$this->assertSame($items, $out, $type . ' must pass items through unchanged');
		}
	}//end testItemsFlowThroughUnchanged()

	public function testValidateConfigRefusesAnEmptyMessage(): void {
		$cases = [
			[new SendNotificationNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['recipients' => ['bob']]],
			[new SendEmailNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['recipients' => ['bob'], 'subject' => 's']],
			[new SendTalkMessageNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['conversation' => 'tok']],
		];

		foreach ($cases as [$node, $config]) {
			try {
				$node->validateConfig(config: $config);
				$this->fail(get_class($node) . ' must refuse an empty message');
			} catch (UnexpectedValueException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testValidateConfigRefusesAnEmptyMessage()

	public function testValidateConfigRefusesAnEmptyRecipientConfig(): void {
		$notification = new SendNotificationNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls);
		$email = new SendEmailNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls);
		$talk = new SendTalkMessageNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls);

		$refused = 0;
		foreach ([
			[$notification, ['message' => 'm', 'recipients' => []]],
			[$notification, ['message' => 'm', 'recipients' => ['   ']]],
			[$email, ['body' => 'b', 'recipients' => []]],
			[$talk, ['message' => 'm', 'conversation' => '  ']],
		] as [$node, $config]) {
			try {
				$node->validateConfig(config: $config);
			} catch (UnexpectedValueException $e) {
				$refused++;
				continue;
			}

			$this->fail(get_class($node) . ' must refuse an empty recipient/conversation config');
		}

		$this->assertSame(4, $refused, 'Every empty-recipient shape must be refused.');
	}//end testValidateConfigRefusesAnEmptyRecipientConfig()

	public function testValidateConfigAcceptsACompleteConfig(): void {
		foreach ([
			[new SendNotificationNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['recipients' => ['bob'], 'title' => 't', 'message' => 'm']],
			[new SendEmailNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['recipients' => '{{ assignee }}', 'subject' => 's', 'body' => 'b']],
			[new SendTalkMessageNode(messaging: $this->messaging, l10n: $this->l10n, urls: $this->urls), ['conversation' => '{{ token }}', 'message' => 'm']],
		] as [$node, $config]) {
			$node->validateConfig(config: $config);
		}

		$this->addToAssertionCount(3);
	}//end testValidateConfigAcceptsACompleteConfig()

	public function testEveryFormFieldWritesADeclaredConfigKey(): void {
		// The flow-node-config-forms floor: a form field over a key the node
		// ignores looks like it works and changes nothing.
		foreach ($this->nodes() as $type => $node) {
			$keys = $node->configKeys();
			$formKeys = array_map(static fn (array $field): string => (string)$field['key'], $node->configForm());
			$this->assertNotSame([], $formKeys, $type . ' must declare a form');
			foreach ($formKeys as $key) {
				$this->assertContains($key, $keys, $type . ' form field "' . $key . '" must be a declared config key');
			}
		}
	}//end testEveryFormFieldWritesADeclaredConfigKey()

	public function testThePaletteHasExactlyTheseThreeMessagingTypesAndNoWebhook(): void {
		// The three ids, from the nodes themselves.
		$this->assertSame(
			['openregister.send-email', 'openregister.send-notification', 'openregister.send-talk-message'],
			array_values(array_intersect(
				['openregister.send-email', 'openregister.send-notification', 'openregister.send-talk-message'],
				array_keys($this->nodes())
			))
		);

		// The registration listener constructor carries all three node types,
		// which is the wiring `handle()` registers.
		$ctor = (new ReflectionClass(FlowNodeRegistrationListener::class))->getConstructor();
		$paramTypes = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				$paramTypes[] = $type->getName();
			}
		}

		$this->assertContains(SendNotificationNode::class, $paramTypes);
		$this->assertContains(SendEmailNode::class, $paramTypes);
		$this->assertContains(SendTalkMessageNode::class, $paramTypes);

		// The boundary with ADR-094: outbound HTTP stays with OpenConnector.
		// No send-webhook node exists — not in the built-ins directory, and no
		// registered constructor parameter carries one.
		$nodesDir = dirname((new ReflectionClass(SendEmailNode::class))->getFileName());
		$this->assertSame([], glob($nodesDir . '/*Webhook*'), 'No webhook node may exist in the built-in palette');
		foreach ($paramTypes as $paramType) {
			$this->assertStringNotContainsString('Webhook', $paramType);
		}

		// Nor may activity or web-push be step types: activity is an audit
		// surface, web-push rides along with send-notification.
		$this->assertSame([], glob($nodesDir . '/*Activity*'));
		$this->assertSame([], glob($nodesDir . '/*WebPush*'));
	}//end testThePaletteHasExactlyTheseThreeMessagingTypesAndNoWebhook()
}//end class
