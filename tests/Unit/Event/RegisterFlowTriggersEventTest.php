<?php

/**
 * RegisterFlowTriggersEvent keeps every contribution in the order it was added.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Event\RegisterFlowTriggersEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Event\RegisterFlowTriggersEvent
 */
class RegisterFlowTriggersEventTest extends TestCase {

	public function testAddedTriggersComeBackInOrder(): void {
		$event = new RegisterFlowTriggersEvent();
		$event->addTrigger('a.one', 'One', 'G');
		$event->addTrigger('b.two', 'Two', 'H');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame(
			[
				['id' => 'a.one', 'label' => 'One', 'group' => 'G'],
				['id' => 'b.two', 'label' => 'Two', 'group' => 'H'],
			],
			$event->getTriggers()
		);
	}//end testAddedTriggersComeBackInOrder()

	public function testANewEventHasNoTriggers(): void {
		$this->assertSame([], (new RegisterFlowTriggersEvent())->getTriggers());
	}//end testANewEventHasNoTriggers()
}//end class
