<?php

/**
 * Unit tests for ScheduledFilterDates: relative and absolute instants, durations, and their fail-closed refusals.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterClause;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterDates;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterDates
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterClause
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget
 */
final class ScheduledFilterDatesTest extends TestCase {

	/**
	 * What reached the debug log.
	 *
	 * @var array<int, array{message: string, context: array<string, mixed>}>
	 */
	private array $debugged = [];

	private function dates(): ScheduledFilterDates {
		$this->debugged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string $message, array $context = []): void {
			$this->debugged[] = ['message' => $message, 'context' => $context];
		});

		return new ScheduledFilterDates(logger: $logger);
	}

	private function clause(mixed $operand): ScheduledFilterClause {
		return new ScheduledFilterClause(
			['field' => 'dueDate', 'operator' => 'before', 'operand' => $operand],
			new ScheduledFilterTarget(objectData: [], now: new DateTimeImmutable('2026-11-13T12:00:00+00:00'))
		);
	}

	public function testNowIsTheTargetsOwnClock(): void {
		$this->assertSame('2026-11-13T12:00:00+00:00', $this->dates()->resolveInstant($this->clause('now'))->format('c'));
	}

	public function testAnIsoDurationIsAddedToNow(): void {
		$this->assertSame('2026-11-16T12:00:00+00:00', $this->dates()->resolveInstant($this->clause('P3D'))->format('c'));
	}

	public function testALeadingMinusSubtractsTheDuration(): void {
		$this->assertSame('2026-11-13T10:00:00+00:00', $this->dates()->resolveInstant($this->clause('-PT2H'))->format('c'));
	}

	public function testAnythingElseIsReadAsADate(): void {
		$this->assertSame('2026-12-01T00:00:00+00:00', $this->dates()->resolveInstant($this->clause('2026-12-01T00:00:00+00:00'))->format('c'));
	}

	public function testAnUnreadableOperandFailsClosed(): void {
		$dates = $this->dates();

		$this->assertNull($dates->resolveInstant($this->clause('not a date')));
		$this->assertSame('ScheduledFilterEvaluator: unparsable object date (fail-closed)', $this->debugged[0]['message']);
		$this->assertSame('dueDate', $this->debugged[0]['context']['field']);
	}

	public function testAMissingOrNonStringDateFailsClosed(): void {
		$dates = $this->dates();

		$this->assertNull($dates->parseDate(null, 'dueDate'));
		$this->assertNull($dates->parseDate('', 'dueDate'));
		$this->assertNull($dates->parseDate(20261201, 'dueDate'));
		$this->assertCount(3, $this->debugged);
		$this->assertSame(['field' => 'dueDate'], $this->debugged[2]['context']);
	}

	public function testADurationIsParsed(): void {
		$interval = $this->dates()->parseDuration($this->clause('P1DT6H'));

		$this->assertInstanceOf(DateInterval::class, $interval);
		$this->assertSame([1, 6], [$interval->d, $interval->h]);
	}

	public function testAnEmptyOrUnparsableDurationFailsClosed(): void {
		$dates = $this->dates();

		$this->assertNull($dates->parseDuration($this->clause('')));
		$this->assertSame('ScheduledFilterEvaluator: empty duration (fail-closed)', $this->debugged[0]['message']);
		$this->assertNull($dates->parseDuration($this->clause('three days')));
		$this->assertSame('ScheduledFilterEvaluator: unparsable duration (fail-closed)', $this->debugged[1]['message']);
		$this->assertSame(['field' => 'dueDate', 'operator' => 'before', 'value' => 'three days'], array_diff_key($this->debugged[1]['context'], ['error' => true]));
	}
}
