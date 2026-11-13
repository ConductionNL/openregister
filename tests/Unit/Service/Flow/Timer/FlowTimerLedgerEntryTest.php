<?php

/**
 * FlowTimerLedgerEntry: one ledger line, and the copies that add its fire moments and basis without changing the original.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerLedgerEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerLedgerEntry
 */
class FlowTimerLedgerEntryTest extends TestCase {

	/**
	 * A new entry carries its facts and no fire moments, basis or impact.
	 *
	 * @return void
	 */
	public function testANewEntryHasOnlyItsFacts(): void {
		$moment = new DateTimeImmutable('2026-11-12 09:00:00');
		$entry  = new FlowTimerLedgerEntry(type: 'suspended', actor: 'alice', reason: 'Waiting for documents', moment: $moment);

		$this->assertSame('suspended', $entry->type);
		$this->assertSame('alice', $entry->actor);
		$this->assertSame('Waiting for documents', $entry->reason);
		$this->assertSame($moment, $entry->moment);
		$this->assertNull($entry->priorFireAt());
		$this->assertNull($entry->newFireAt());
		$this->assertSame('', $entry->basis());
		$this->assertNull($entry->impact());

	}//end testANewEntryHasOnlyItsFacts()

	/**
	 * Fire moments go on a copy; the original keeps none.
	 *
	 * @return void
	 */
	public function testFireMomentsGoOnACopy(): void {
		$entry = new FlowTimerLedgerEntry(type: 'extended', actor: null, reason: 'Granted', moment: new DateTimeImmutable());
		$prior = new DateTime('2026-11-20 17:00:00');
		$next  = new DateTime('2026-11-27 17:00:00');

		$copy = $entry->withFireMoments(prior: $prior, next: $next);

		$this->assertNotSame($entry, $copy);
		$this->assertSame($prior, $copy->priorFireAt());
		$this->assertSame($next, $copy->newFireAt());
		$this->assertNull($entry->priorFireAt());
		$this->assertNull($entry->newFireAt());
		$this->assertSame('extended', $copy->type);
		$this->assertNull($copy->actor);

	}//end testFireMomentsGoOnACopy()

	/**
	 * The basis and impact go on a copy, keep earlier fire moments, and impact defaults to none.
	 *
	 * @return void
	 */
	public function testBasisGoesOnACopyAndKeepsTheFireMoments(): void {
		$prior = new DateTime('2026-11-20 17:00:00');
		$entry = (new FlowTimerLedgerEntry(type: 'resumed', actor: 'bob', reason: 'Back', moment: new DateTimeImmutable()))
			->withFireMoments(prior: $prior, next: null);

		$weighed = $entry->withBasis(basis: 'business-days', impact: 2.5);
		$plain   = $entry->withBasis(basis: 'calendar-days');

		$this->assertSame('business-days', $weighed->basis());
		$this->assertSame(2.5, $weighed->impact());
		$this->assertSame($prior, $weighed->priorFireAt());
		$this->assertNull($weighed->newFireAt());
		$this->assertSame('calendar-days', $plain->basis());
		$this->assertNull($plain->impact());
		$this->assertSame('', $entry->basis());

	}//end testBasisGoesOnACopyAndKeepsTheFireMoments()
}//end class
