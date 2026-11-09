<?php

/**
 * FlowSend: a kill-switch skip is not a failure; any other undelivered outcome is, and says why.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;
use OCA\OpenRegister\Service\Flow\FlowSend;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowSend
 */
class FlowSendUndeliveredBehaviourTest extends TestCase {

	public function testAKillSwitchSkipIsCountedApartAndIsNoFailure(): void {
		$send = new FlowSend(channel: 'email', config: [], items: [], context: []);

		$send->undelivered(recipient: 'alice@example.org', outcome: 'kill-switch');
		$send->undelivered(recipient: 'bob@example.org', outcome: 'smtp-timeout');

		self::assertSame(['alice@example.org'], $send->outcomes['skippedByKillSwitch']);
		self::assertSame(['bob@example.org'], $send->outcomes['failed']);
		self::assertSame(['email to "bob@example.org" failed (smtp-timeout)'], $send->failures);
	}//end testAKillSwitchSkipIsCountedApartAndIsNoFailure()
}//end class
