<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerWebhookNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The incoming-webhook trigger refuses a config without an existing acting identity.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerWebhookNode
 * @uses   \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class TriggerWebhookNodeTest extends TestCase {

	/**
	 * The node, with `betalingen` the one existing account.
	 *
	 * @return TriggerWebhookNode
	 */
	private function node(): TriggerWebhookNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'betalingen' ? $this->createMock(IUser::class) : null));

		return new TriggerWebhookNode(new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class)), $users);
	}//end node()

	public function testItIsTheWebhookTriggerType(): void {
		$this->assertSame('openregister.trigger-webhook', $this->node()->getId());
		$this->assertSame(['runAs', 'token', 'rotateSecret'], $this->node()->configKeys());
	}//end testItIsTheWebhookTriggerType()

	public function testAnExistingActingIdentityIsAccepted(): void {
		$this->node()->validateConfig(['runAs' => 'betalingen']);
		$this->addToAssertionCount(1);
	}//end testAnExistingActingIdentityIsAccepted()

	public function testAMissingActingIdentityIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must carry a "runAs"');

		$this->node()->validateConfig(['runAs' => '  ']);
	}//end testAMissingActingIdentityIsRefused()

	public function testAnUnknownActingIdentityIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"nobody", which is not an existing user account');

		$this->node()->validateConfig(['runAs' => 'nobody']);
	}//end testAnUnknownActingIdentityIsRefused()

	public function testTheFormNeverAsksForTheSecret(): void {
		$keys = array_column($this->node()->configForm(), 'key');

		$this->assertSame(['runAs', 'rotateSecret'], $keys);
	}//end testTheFormNeverAsksForTheSecret()
}//end class
