<?php

/**
 * Unit tests for ExternalRecipientPolicy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\ExternalRecipientPolicy;
use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\ExternalRecipientPolicy
 */
final class ExternalRecipientPolicyTest extends TestCase {

	/**
	 * An invalid address is refused before the mode is consulted.
	 *
	 * @return void
	 */
	public function testAnInvalidAddressIsRefusedInEveryMode(): void {
		foreach ([FlowMessagingService::EXTERNAL_ANY, FlowMessagingService::EXTERNAL_NONE, FlowMessagingService::EXTERNAL_OBJECT] as $mode) {
			$policy = new ExternalRecipientPolicy(mode: $mode, onItem: ['not-an-address' => true]);
			self::assertSame(FlowMessagingService::REFUSED_INVALID_ADDRESS, $policy->refusalFor('not-an-address'), $mode);
		}
	}//end testAnInvalidAddressIsRefusedInEveryMode()

	/**
	 * Mode "none" refuses every valid external address.
	 *
	 * @return void
	 */
	public function testModeNoneRefusesAValidAddress(): void {
		$policy = new ExternalRecipientPolicy(mode: FlowMessagingService::EXTERNAL_NONE, onItem: ['a@example.org' => true]);

		self::assertSame(FlowMessagingService::REFUSED_EXTERNAL_OFF, $policy->refusalFor('a@example.org'));
	}//end testModeNoneRefusesAValidAddress()

	/**
	 * Mode "object" allows only addresses on the item, matched case-insensitively.
	 *
	 * @return void
	 */
	public function testModeObjectAllowsOnlyAddressesOnTheItem(): void {
		$policy = new ExternalRecipientPolicy(mode: FlowMessagingService::EXTERNAL_OBJECT, onItem: ['a@example.org' => true]);

		self::assertSame('', $policy->refusalFor('a@example.org'));
		self::assertSame('', $policy->refusalFor('A@Example.ORG'));
		self::assertSame(FlowMessagingService::REFUSED_NOT_ON_ITEM, $policy->refusalFor('b@example.org'));
	}//end testModeObjectAllowsOnlyAddressesOnTheItem()

	/**
	 * Mode "any" allows any valid address.
	 *
	 * @return void
	 */
	public function testModeAnyAllowsAnyValidAddress(): void {
		$policy = new ExternalRecipientPolicy(mode: FlowMessagingService::EXTERNAL_ANY, onItem: []);

		self::assertSame('', $policy->refusalFor('someone@example.org'));
	}//end testModeAnyAllowsAnyValidAddress()
}//end class
