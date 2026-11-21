<?php

/**
 * ExampleSetTaskDates refuses a fragment task whose dates are unreadable,
 * out of order, or in the future, and passes a consistent one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetDateValue
 */
class ExampleSetTaskDatesTest extends TestCase {
	/**
	 * A task with no dates at all is accepted.
	 *
	 * @return void
	 */
	public function testNoDatesIsAccepted(): void {
		$this->assertSame('', ExampleSetTaskDates::refusal(['title' => 'x']));
	}

	/**
	 * A consistent set of past dates is accepted.
	 *
	 * @return void
	 */
	public function testConsistentDatesAreAccepted(): void {
		$data = [
			'createdAt'   => '2020-01-01T00:00:00+00:00',
			'claimedAt'   => '2020-01-02T00:00:00+00:00',
			'completedAt' => '2020-01-03T00:00:00+00:00',
			'dueAt'       => '2020-02-01T00:00:00+00:00',
			'expiresAt'   => '2020-02-01T00:00:00+00:00',
		];
		$this->assertSame('', ExampleSetTaskDates::refusal($data));
	}

	/**
	 * Every date field is checked for being a date, and the first bad one is named.
	 *
	 * @return void
	 */
	public function testUnreadableDateIsRefusedPerField(): void {
		foreach (['dueAt', 'expiresAt', 'completedAt', 'claimedAt', 'createdAt'] as $field) {
			$this->assertSame(
				$field.' "not-a-date" is not a date',
				ExampleSetTaskDates::refusal([$field => 'not-a-date'])
			);
		}
	}

	/**
	 * An empty string is not a date either.
	 *
	 * @return void
	 */
	public function testEmptyDateIsRefused(): void {
		$this->assertSame('dueAt "" is not a date', ExampleSetTaskDates::refusal(['dueAt' => '']));
	}

	/**
	 * The format check runs before the order checks.
	 *
	 * @return void
	 */
	public function testFormatRefusalWinsOverOrderRefusal(): void {
		$data = [
			'dueAt'     => '2020-02-01',
			'expiresAt' => '2020-01-01',
			'createdAt' => 'garbage',
		];
		$this->assertSame('createdAt "garbage" is not a date', ExampleSetTaskDates::refusal($data));
	}

	/**
	 * Expiring before the due date is refused.
	 *
	 * @return void
	 */
	public function testExpiryBeforeDueIsRefused(): void {
		$this->assertSame(
			'expiresAt lies before dueAt: a task that dies before it is due is a configuration error',
			ExampleSetTaskDates::refusal(['dueAt' => '2020-02-01', 'expiresAt' => '2020-01-31'])
		);
	}

	/**
	 * Expiring after the due date passes; only one of the two is not compared.
	 *
	 * @return void
	 */
	public function testExpiryAfterDueOrAloneIsAccepted(): void {
		$this->assertSame('', ExampleSetTaskDates::refusal(['dueAt' => '2020-02-01', 'expiresAt' => '2020-02-02']));
		$this->assertSame('', ExampleSetTaskDates::refusal(['expiresAt' => '2000-01-01']));
	}

	/**
	 * A creation moment in the future is refused.
	 *
	 * @return void
	 */
	public function testCreatedInTheFutureIsRefused(): void {
		$this->assertSame(
			'createdAt "2999-01-01T00:00:00+00:00" lies in the future',
			ExampleSetTaskDates::refusal(['createdAt' => '2999-01-01T00:00:00+00:00'])
		);
	}

	/**
	 * Claimed before it was created is refused.
	 *
	 * @return void
	 */
	public function testClaimedBeforeCreatedIsRefused(): void {
		$this->assertSame(
			'claimedAt lies before createdAt: a task is not claimed before it exists',
			ExampleSetTaskDates::refusal(['createdAt' => '2020-01-05', 'claimedAt' => '2020-01-04'])
		);
	}

	/**
	 * Completed before it was created is refused.
	 *
	 * @return void
	 */
	public function testCompletedBeforeCreatedIsRefused(): void {
		$this->assertSame(
			'completedAt lies before createdAt: a task is not completed before it exists',
			ExampleSetTaskDates::refusal(['createdAt' => '2020-01-05', 'completedAt' => '2020-01-04'])
		);
	}

	/**
	 * Claimed or completed without a creation moment is not compared.
	 *
	 * @return void
	 */
	public function testNoCreatedAtSkipsOrderCheck(): void {
		$this->assertSame('', ExampleSetTaskDates::refusal(['claimedAt' => '1990-01-01', 'completedAt' => '1990-01-01']));
	}

	/**
	 * Moment reads a date string and returns null for anything else.
	 *
	 * @return void
	 */
	public function testMomentParsesStringsOnly(): void {
		$this->assertSame('2020-03-04', ExampleSetTaskDates::moment('2020-03-04T10:00:00+00:00')->format('Y-m-d'));
		$this->assertNull(ExampleSetTaskDates::moment(20200304));
		$this->assertNull(ExampleSetTaskDates::moment('   '));
		$this->assertNull(ExampleSetTaskDates::moment('nope'));
	}
}
