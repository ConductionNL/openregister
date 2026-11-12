<?php

/**
 * Flow oversight registration adds the kill switch, then the organisation halt.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\FlowOversightRegistrationListener;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheckInterface;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltCheckInterface;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Both checks are registered in order on the oversight event, and on no other.
 */
class FlowOversightRegistrationListenerBehaviourTest extends TestCase {
	public function testRegistersKillSwitchThenOrganisationHalt(): void {
		$killSwitch = $this->createMock(KillSwitchCheckInterface::class);
		$halt       = $this->createMock(OrganisationHaltCheckInterface::class);
		$registered = [];
		$registry   = $this->createMock(FlowOversightRegistry::class);
		$registry->expects($this->exactly(2))->method('register')
			->willReturnCallback(function (IFlowOversightCheck $check) use (&$registered): void {
				$registered[] = $check;
			});

		(new FlowOversightRegistrationListener($killSwitch, $halt))->handle(new RegisterFlowOversightEvent($registry));

		$this->assertSame([$killSwitch, $halt], $registered);
	}

	public function testAnyOtherEventRegistersNothing(): void {
		$foreign = new class extends Event {
			/** @var list<IFlowOversightCheck> */
			public array $checks = [];

			public function registerCheck(IFlowOversightCheck $check): void {
				$this->checks[] = $check;
			}
		};

		(new FlowOversightRegistrationListener(
			$this->createMock(KillSwitchCheckInterface::class),
			$this->createMock(OrganisationHaltCheckInterface::class)
		))->handle($foreign);

		$this->assertSame([], $foreign->checks);
	}
}
