<?php

/**
 * Unit tests for the history predicate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Service\Search\HistoryPredicate;
use PHPUnit\Framework\TestCase;

class HistoryPredicateTest extends TestCase {

	/**
	 * A query with no history filter narrows nothing, so the ordinary list is
	 * byte-identical to what it was.
	 *
	 * @return void
	 */
	public function testAQueryWithoutAHistoryFilterNarrowsNothing(): void {
		$predicate = HistoryPredicate::parse(['_search' => 'x', '_limit' => 20]);

		$this->assertFalse($predicate->narrows());
		$this->assertSame([], $predicate->properties());
		$this->assertSame([], $predicate->unparsed());
	}//end testAQueryWithoutAHistoryFilterNarrowsNothing()

	/**
	 * `_was_ever[status]=bezwaar` reads as one property and one value.
	 *
	 * @return void
	 */
	public function testWasEverReadsThePropertyAndValue(): void {
		$predicate = HistoryPredicate::parse(['_was_ever' => ['status' => 'bezwaar']]);

		$this->assertTrue($predicate->narrows());
		$this->assertSame(['status' => 'bezwaar'], $predicate->wasEver());
		$this->assertSame(['status'], $predicate->properties());
	}//end testWasEverReadsThePropertyAndValue()

	/**
	 * A period reads from `after,before` and from the named form.
	 *
	 * @return void
	 */
	public function testChangedBetweenReadsBothPeriodForms(): void {
		$commaForm = HistoryPredicate::parse(
			['_changed_between' => ['status' => '2026-01-01,2026-06-30']]
		);
		$namedForm = HistoryPredicate::parse(
			['_changed_between' => ['status' => ['after' => '2026-01-01', 'before' => '2026-06-30']]]
		);

		$this->assertSame(
			$commaForm->changedBetween()['status']['after']->format('Y-m-d'),
			$namedForm->changedBetween()['status']['after']->format('Y-m-d')
		);
		$this->assertSame('2026-06-30', $commaForm->changedBetween()['status']['before']->format('Y-m-d'));
	}//end testChangedBetweenReadsBothPeriodForms()

	/**
	 * A period whose end precedes its start does not read. Accepted, it would
	 * match nothing and look like a search with no hits.
	 *
	 * @return void
	 */
	public function testABackwardsPeriodDoesNotRead(): void {
		$predicate = HistoryPredicate::parse(
			['_changed_between' => ['status' => '2026-06-30,2026-01-01']]
		);

		$this->assertFalse($predicate->narrows());
		$this->assertSame(['_changed_between[status]'], $predicate->unparsed());
	}//end testABackwardsPeriodDoesNotRead()

	/**
	 * A property name that is not one never reaches the projection query.
	 *
	 * @return void
	 */
	public function testAPropertyNameThatIsNotOneIsRefusedBeforeItTravels(): void {
		$predicate = HistoryPredicate::parse(
			['_was_ever' => ['status; DROP TABLE x' => 'bezwaar', '' => 'x']]
		);

		$this->assertFalse($predicate->narrows());
		$this->assertCount(2, $predicate->unparsed());
	}//end testAPropertyNameThatIsNotOneIsRefusedBeforeItTravels()

	/**
	 * An empty value does not read: "was ever nothing" is not a question.
	 *
	 * @return void
	 */
	public function testAnEmptyValueDoesNotRead(): void {
		$predicate = HistoryPredicate::parse(['_was_ever' => ['status' => '']]);

		$this->assertFalse($predicate->narrows());
		$this->assertSame(['_was_ever[status]'], $predicate->unparsed());
	}//end testAnEmptyValueDoesNotRead()

	/**
	 * A property with no projection earns a refusal that NAMES it.
	 *
	 * This is the whole of requirement 2.4: answered instead, the filter would
	 * return an empty page, which reads as "no case was ever in bezwaar" when
	 * the truth is that the instance records no history for that property.
	 *
	 * @return void
	 */
	public function testAnUnprojectedPropertyIsRefusedByName(): void {
		$predicate = HistoryPredicate::parse(['_was_ever' => ['behandelaar' => 'anna']]);

		$refusal = $predicate->refusalFor(['status']);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('behandelaar', (string)$refusal);
	}//end testAnUnprojectedPropertyIsRefusedByName()

	/**
	 * A projected property is not refused.
	 *
	 * Paired with the refusal above on purpose: a refusal that fires for
	 * everything would pass the test above on its own.
	 *
	 * @return void
	 */
	public function testAProjectedPropertyIsNotRefused(): void {
		$predicate = HistoryPredicate::parse(['_was_ever' => ['status' => 'bezwaar']]);

		$this->assertNull($predicate->refusalFor(['status', 'fase']));
	}//end testAProjectedPropertyIsNotRefused()

	/**
	 * The response's account of the filter reports both what read and what did
	 * not, so a result nobody expected carries its own reason.
	 *
	 * @return void
	 */
	public function testTheSerialisedPredicateReportsWhatReadAndWhatDidNot(): void {
		$predicate = HistoryPredicate::parse(
			[
				'_was_ever' => ['status' => 'bezwaar', 'bad name!' => 'x'],
				'_changed_between' => ['status' => '2026-01-01,2026-06-30'],
			]
		);

		$serialised = $predicate->jsonSerialize();

		$this->assertSame(['status' => 'bezwaar'], $serialised['wasEver']);
		$this->assertArrayHasKey('status', $serialised['changedBetween']);
		$this->assertSame(['_was_ever[bad name!]'], $serialised['unparsed']);
	}//end testTheSerialisedPredicateReportsWhatReadAndWhatDidNot()
}//end class
