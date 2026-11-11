<?php

/**
 * OutboundTransportRunTest.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\HeldDeliveryRun;
use OCA\OpenRegister\Notifications\Service\Notification\OutboundTransportRun;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientDelivery;
use OCA\OpenRegister\Notifications\Service\Notification\RuleFiring;
use PHPUnit\Framework\TestCase;

/**
 * The run objects the dispatcher's transport and hold steps share.
 */
class OutboundTransportRunTest extends TestCase {
	private function firing(): RuleFiring {
		return new RuleFiring('rule-1', [], new ObjectEntity(), new Schema(), [], 'object_created');
	}

	public function testATransportKeepsItsHandlerAndConfig(): void {
		$firing = $this->firing();
		$run    = new OutboundTransportRun($firing, ['handler' => 'Tag', 'config' => ['a' => 1]]);

		$this->assertSame($firing, $run->firing);
		$this->assertSame('Tag', $run->handler);
		$this->assertSame(['a' => 1], $run->config);
	}

	public function testAMissingOrNonArrayConfigReadsAsEmpty(): void {
		$this->assertSame([], (new OutboundTransportRun($this->firing(), ['handler' => 'Tag']))->config);
		$this->assertSame([], (new OutboundTransportRun($this->firing(), ['handler' => 'Tag', 'config' => 'x']))->config);
		$this->assertSame('', (new OutboundTransportRun($this->firing(), []))->handler);
	}

	public function testAHeldDeliveryRunCarriesItsFiringAndDelivery(): void {
		$firing   = $this->firing();
		$delivery = new RecipientDelivery('alice');
		$run      = new HeldDeliveryRun($firing, $delivery);

		$this->assertSame($firing, $run->firing);
		$this->assertSame($delivery, $run->delivery);
	}
}
