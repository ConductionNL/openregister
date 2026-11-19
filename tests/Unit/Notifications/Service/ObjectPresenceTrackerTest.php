<?php

/**
 * The presence register, answering the object API's Core port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectPresenceInterface;
use OCA\OpenRegister\Notifications\NotificationsWiring;
use OCA\OpenRegister\Notifications\Service\ObjectPresenceTracker;
use OCA\OpenRegister\Notifications\Service\PresenceBeat;
use OCA\OpenRegister\Notifications\Service\PresenceLookup;
use OCA\OpenRegister\Notifications\Service\PresenceServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each port method reaches the register with the same reader, object and exclusion.
 */
class ObjectPresenceTrackerTest extends TestCase {

	/**
	 * @var PresenceServiceInterface&MockObject
	 */
	private PresenceServiceInterface&MockObject $presence;

	/**
	 * @var ObjectPresenceTracker
	 */
	private ObjectPresenceTracker $tracker;

	/**
	 * Build the tracker over a mocked register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->presence = $this->createMock(PresenceServiceInterface::class);
		$this->tracker  = new ObjectPresenceTracker(presence: $this->presence);
	}//end setUp()

	/**
	 * A beat that arrives answers true, with the reader and object passed on.
	 *
	 * @return void
	 */
	public function testABeatThatArrivesAnswersTrue(): void {
		$this->presence->expects($this->once())
			->method('heartbeat')
			->with($this->callback(fn (PresenceBeat $beat): bool => $beat->userId === 'alice' && $beat->objectUuid === 'uuid-1'))
			->willReturn(['arrived' => true, 'presence' => null]);

		$this->assertTrue($this->tracker->beat(userId: 'alice', objectUuid: 'uuid-1'));
	}//end testABeatThatArrivesAnswersTrue()

	/**
	 * A renewal answers false.
	 *
	 * @return void
	 */
	public function testARenewalAnswersFalse(): void {
		$this->presence->method('heartbeat')->willReturn(['arrived' => false, 'presence' => null]);

		$this->assertFalse($this->tracker->beat(userId: 'alice', objectUuid: 'uuid-1'));
	}//end testARenewalAnswersFalse()

	/**
	 * A departure is passed on and its answer returned.
	 *
	 * @return void
	 */
	public function testADepartureIsPassedOn(): void {
		$this->presence->expects($this->once())->method('depart')->with('alice', 'uuid-1')->willReturn(true);

		$this->assertTrue($this->tracker->depart(userId: 'alice', objectUuid: 'uuid-1'));
	}//end testADepartureIsPassedOn()

	/**
	 * The present readers are looked up with the exclusion, and without one by default.
	 *
	 * @return void
	 */
	public function testThePresentReadersAreLookedUpWithTheExclusion(): void {
		$this->presence->expects($this->exactly(2))
			->method('present')
			->willReturnCallback(
				fn (PresenceLookup $lookup): array => [['objectUuid' => $lookup->objectUuid, 'except' => $lookup->exceptUser]]
			);

		$this->assertSame([['objectUuid' => 'uuid-1', 'except' => 'alice']], $this->tracker->present(objectUuid: 'uuid-1', exceptUser: 'alice'));
		$this->assertSame([['objectUuid' => 'uuid-1', 'except' => '']], $this->tracker->present(objectUuid: 'uuid-1'));
	}//end testThePresentReadersAreLookedUpWithTheExclusion()

	/**
	 * Notifications binds the Core port to the tracker, and both name the same beat interval.
	 *
	 * @return void
	 */
	public function testNotificationsBindsThePortToTheTracker(): void {
		$this->assertSame(ObjectPresenceTracker::class, NotificationsWiring::BINDINGS[ObjectPresenceInterface::class]);
		$this->assertSame(ObjectPresenceInterface::BEAT_SECONDS, PresenceServiceInterface::BEAT_SECONDS);
	}//end testNotificationsBindsThePortToTheTracker()
}//end class
