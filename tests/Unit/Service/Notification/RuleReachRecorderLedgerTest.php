<?php

/**
 * The recorder hands its counts to the ledger without a write per object.
 *
 * A storm over four hundred objects must not become four hundred config
 * writes, and must not leave the ledger at a count of one either. So the
 * recorder writes on a rule's first occurrence, again each time the count
 * doubles, and the remainder when it is flushed or released.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder
 */
class RuleReachRecorderLedgerTest extends TestCase {

	/**
	 * The counts handed to the ledger, one entry per write.
	 *
	 * @var list<array<string, int>>
	 */
	private array $written = [];

	/**
	 * The rules the ledger was told to forget.
	 *
	 * @var list<string>
	 */
	private array $forgotten = [];

	/**
	 * The ledger double.
	 *
	 * @var RuleReachLedger&MockObject
	 */
	private RuleReachLedger $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = $this->createMock(RuleReachLedger::class);
		$this->ledger->method('record')->willReturnCallback(
			function (array $counts, int $now): void {
				$this->written[] = $counts;
			}
		);
		$this->ledger->method('forget')->willReturnCallback(
			function (string $rule): void {
				$this->forgotten[] = $rule;
			}
		);
	}//end setUp()

	public function testTheFirstOccurrenceIsWrittenAtOnce(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);

		$recorder->reachedNobody(ruleId: 'case-assigned', objectUuid: 'o-1');

		$this->assertSame([['case-assigned' => 1]], $this->written);
	}//end testTheFirstOccurrenceIsWrittenAtOnce()

	public function testAStormIsWrittenOnEachDoublingNotOnEachObject(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);

		for ($i = 0; $i < 400; $i++) {
			$recorder->reachedNobody(ruleId: 'case-assigned', objectUuid: 'o-'.$i);
		}

		$this->assertSame([1, 2, 4, 8, 16, 32, 64, 128, 256], array_column($this->written, 'case-assigned'));
	}//end testAStormIsWrittenOnEachDoublingNotOnEachObject()

	public function testFlushWritesTheRemainderOnce(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);
		for ($i = 0; $i < 400; $i++) {
			$recorder->reachedNobody(ruleId: 'case-assigned');
		}

		$recorder->flush();
		$recorder->flush();

		$this->assertSame(['case-assigned' => 400], end($this->written));
		$this->assertCount(10, $this->written);
	}//end testFlushWritesTheRemainderOnce()

	public function testFlushAfterADoublingHasNothingToWrite(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedNobody(ruleId: 'a');

		$recorder->flush();

		$this->assertSame([['a' => 1], ['a' => 2]], $this->written);
	}//end testFlushAfterADoublingHasNothingToWrite()

	public function testReleasingTheRecorderWritesTheRemainder(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedNobody(ruleId: 'a');

		unset($recorder);

		$this->assertSame(['a' => 3], end($this->written));
	}//end testReleasingTheRecorderWritesTheRemainder()

	public function testARuleThatReachedSomebodyIsForgotten(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);

		$recorder->reachedSomebody(ruleId: ' case-assigned ');

		$this->assertSame(['case-assigned'], $this->forgotten);
	}//end testARuleThatReachedSomebodyIsForgotten()

	public function testARuleThatReachedNobodyEarlierInTheRunIsNotForgotten(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);
		$recorder->reachedNobody(ruleId: 'case-assigned');

		$recorder->reachedSomebody(ruleId: 'case-assigned');

		$this->assertSame([], $this->forgotten);
	}//end testARuleThatReachedNobodyEarlierInTheRunIsNotForgotten()

	public function testAFailingLedgerNeverFailsTheDispatch(): void {
		$ledger = $this->createMock(RuleReachLedger::class);
		$ledger->method('record')->willThrowException(new RuntimeException('config down'));
		$ledger->method('forget')->willThrowException(new RuntimeException('config down'));
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $ledger);

		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedSomebody(ruleId: 'b');

		$this->assertSame(1, $recorder->report()['occurrences']);
	}//end testAFailingLedgerNeverFailsTheDispatch()

	public function testWithoutALedgerTheCountsStayInMemory(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger());
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedSomebody(ruleId: 'b');
		$recorder->flush();

		$this->assertSame(1, $recorder->report()['ruleCount']);
		$this->assertSame([], $this->written);
	}//end testWithoutALedgerTheCountsStayInMemory()

	public function testResetWritesWhatTheRunCountedBeforeForgettingIt(): void {
		$recorder = new RuleReachRecorder(logger: new NullLogger(), ledger: $this->ledger);
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedNobody(ruleId: 'a');
		$recorder->reachedNobody(ruleId: 'a');

		$recorder->reset();

		$this->assertSame(['a' => 3], end($this->written));
		$this->assertSame(0, $recorder->report()['ruleCount']);
	}//end testResetWritesWhatTheRunCountedBeforeForgettingIt()
}//end class
