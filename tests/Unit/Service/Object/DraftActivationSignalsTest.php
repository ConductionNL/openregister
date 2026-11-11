<?php

declare(strict_types=1);

/**
 * DraftActivationSignals bundle test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\DraftActivationSignals;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The bundle hands back exactly the services it was given.
 */
final class DraftActivationSignalsTest extends TestCase {
	/**
	 * Every service is carried as given.
	 *
	 * @return void
	 */
	public function testCarriesTheServicesAsGiven(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$time = $this->createMock(ITimeFactory::class);
		$bundle = new DraftActivationSignals(events: $events, time: $time);

		$this->assertSame($events, $bundle->events);
		$this->assertSame($time, $bundle->time);
	}//end testCarriesTheServicesAsGiven()
}//end class
