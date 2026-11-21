<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The vocabularies the notification code reads from lower contracts stay the
 * ones the Processes classes define theirs as: the timer units, the rejecting
 * task outcomes, and the two ports the Processes services answer.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Notifications;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\Notifications\ObjectWatchersInterface;
use OCA\OpenRegister\Kernel\Contracts\Notifications\TimerUnits;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Task\TaskState;
use OCA\OpenRegister\Service\Task\TaskStateRules;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NotificationsOwnedVocabularyTest extends TestCase {
	public function testTheTimerUnitsAreUnchanged(): void {
		$this->assertSame(['hours', 'businessDays', 'calendarDays'], TimerUnits::UNITS);
		$this->assertSame(TimerUnits::UNITS, SlaCalculatorInterface::UNITS);
		$this->assertSame('hours', SlaCalculatorInterface::UNIT_HOURS);
		$this->assertSame('businessDays', SlaCalculatorInterface::UNIT_BUSINESS_DAYS);
		$this->assertSame('calendarDays', SlaCalculatorInterface::UNIT_CALENDAR_DAYS);
	}

	public function testTheRejectingOutcomesAreUnchanged(): void {
		$this->assertSame(['rejected', 'returned', 'declined', 'denied'], TaskInterface::REJECTING_OUTCOMES);
		$this->assertSame(TaskInterface::REJECTING_OUTCOMES, TaskState::REJECTING_OUTCOMES);
	}

	/**
	 * The dispatcher's task-link rule matches the task state rule for every outcome spelling.
	 */
	public function testTheDispatcherRuleAgreesWithTheTaskStateRule(): void {
		$dispatcherRule = static fn (string $outcome): bool => in_array(strtolower(trim($outcome)), TaskInterface::REJECTING_OUTCOMES, true);
		foreach (['rejected', ' Returned ', 'DECLINED', 'denied', 'approved', '', 'reject', 'done'] as $outcome) {
			$this->assertSame(TaskStateRules::isRejectingOutcome(outcome: $outcome), $dispatcherRule($outcome), $outcome);
		}
	}

	public function testTheWatcherServiceAnswersTheWatcherPort(): void {
		$this->assertTrue(is_a(WatcherServiceInterface::class, ObjectWatchersInterface::class, true));
	}
}
