<?php

declare(strict_types=1);

/**
 * A notification rule that reaches nobody says so.
 *
 * 🔴 IT USED TO `continue` IN SILENCE. `AnnotationNotificationDispatcher` had
 * `if (count($recipients) === 0) { continue; }` — no log, no counter, no
 * complaint. And declared groups ship EMPTY on purpose across this fleet: an
 * empty group denies everyone except admins and object owners, which is the
 * right default. So on a fresh install a correctly written rule addressed to a
 * declared group resolves to nobody and reports exactly what it would report
 * having reached everybody.
 *
 * 🔴 BUT AN EMPTY GROUP ON A QUIET INSTANCE IS LEGITIMATE. A sweep over four
 * hundred objects with one unstaffed group must not write four hundred
 * warnings: a log nobody can read is the same silence with noise in front of it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
 */

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\RuleReachRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for RuleReachRecorder.
 */
class RuleReachRecorderTest extends TestCase {

	/**
	 * A logger that keeps what it was told.
	 *
	 * @return object The logger double.
	 */
	private function logger(): object {
		return new class implements LoggerInterface {
			/** @var array<int, array<string, mixed>> */
			public array $warnings = [];

			public function emergency($message, array $context = []): void {
			}

			public function alert($message, array $context = []): void {
			}

			public function critical($message, array $context = []): void {
			}

			public function error($message, array $context = []): void {
			}

			public function warning($message, array $context = []): void {
				$this->warnings[] = ['message' => (string)$message, 'context' => $context];
			}

			public function notice($message, array $context = []): void {
			}

			public function info($message, array $context = []): void {
			}

			public function debug($message, array $context = []): void {
			}

			public function log($level, $message, array $context = []): void {
			}
		};
	}//end logger()

	/**
	 * 🔴 A RULE THAT REACHED NOBODY IS SAID ONCE, with the rule named.
	 *
	 * @return void
	 */
	public function testARuleThatReachedNobodyIsReported(): void {
		$logger = $this->logger();
		$recorder = new RuleReachRecorder(logger: $logger);

		$recorder->reachedNobody(ruleId: 'intakeReadingFailed', objectUuid: 'obj-1');

		$this->assertCount(1, $logger->warnings);
		$this->assertSame(RuleReachRecorder::MARKER, $logger->warnings[0]['message']);
		$this->assertSame('intakeReadingFailed', $logger->warnings[0]['context']['rule']);
	}//end testARuleThatReachedNobodyIsReported()

	/**
	 * 🔴 AND FOUR HUNDRED OBJECTS PRODUCE ONE LINE, NOT FOUR HUNDRED. A log
	 * nobody can read is the same silence with noise in front of it.
	 *
	 * @return void
	 */
	public function testASweepOverManyObjectsSaysItOnce(): void {
		$logger = $this->logger();
		$recorder = new RuleReachRecorder(logger: $logger);

		for ($i = 0; $i < 400; $i++) {
			$recorder->reachedNobody(ruleId: 'intakeReadingFailed', objectUuid: 'obj-'.$i);
		}

		$this->assertCount(1, $logger->warnings, 'one unstaffed rule is one line whatever the sweep size');
		$this->assertSame(400, $recorder->report()['occurrences']);
	}//end testASweepOverManyObjectsSaysItOnce()

	/**
	 * Two different rules are two lines, so one rule's noise does not hide
	 * another rule's problem.
	 *
	 * @return void
	 */
	public function testTwoRulesAreTwoLines(): void {
		$logger = $this->logger();
		$recorder = new RuleReachRecorder(logger: $logger);

		$recorder->reachedNobody(ruleId: 'ruleA');
		$recorder->reachedNobody(ruleId: 'ruleB');

		$this->assertCount(2, $logger->warnings);
		$this->assertSame(2, $recorder->report()['ruleCount']);
	}//end testTwoRulesAreTwoLines()

	/**
	 * 🔴 THE RULE COUNT AND THE OCCURRENCE COUNT ARE KEPT APART. One rule
	 * failing four hundred times and four hundred rules failing once are very
	 * different problems.
	 *
	 * @return void
	 */
	public function testTheRuleCountAndTheOccurrenceCountAreSeparate(): void {
		$recorder = new RuleReachRecorder(logger: $this->logger());

		$recorder->reachedNobody(ruleId: 'ruleA');
		$recorder->reachedNobody(ruleId: 'ruleA');
		$recorder->reachedNobody(ruleId: 'ruleB');

		$report = $recorder->report();

		$this->assertSame(2, $report['ruleCount']);
		$this->assertSame(3, $report['occurrences']);
	}//end testTheRuleCountAndTheOccurrenceCountAreSeparate()

	/**
	 * The report is returned as well as logged, so a caller can put it on a
	 * screen. A finding that exists only in a log file is findable by whoever
	 * already suspects it.
	 *
	 * @return void
	 */
	public function testTheReportIsReturnedNotOnlyLogged(): void {
		$recorder = new RuleReachRecorder(logger: $this->logger());
		$recorder->reachedNobody(ruleId: 'intakeReadingFailed');

		$report = $recorder->report();

		$this->assertTrue($report['needsAPerson']);
		$this->assertSame('intakeReadingFailed', $report['rulesReachingNobody'][0]['rule']);
	}//end testTheReportIsReturnedNotOnlyLogged()

	/**
	 * A run where every rule reached somebody claims nobody is needed.
	 *
	 * @return void
	 */
	public function testAHealthyRunNeedsNobody(): void {
		$recorder = new RuleReachRecorder(logger: $this->logger());

		$this->assertFalse($recorder->report()['needsAPerson']);
		$this->assertSame(0, $recorder->report()['ruleCount']);
	}//end testAHealthyRunNeedsNobody()

	/**
	 * An unnamed rule is still reported, under a name somebody can search for,
	 * rather than being dropped for having no id.
	 *
	 * @return void
	 */
	public function testAnUnnamedRuleIsStillReported(): void {
		$logger = $this->logger();
		$recorder = new RuleReachRecorder(logger: $logger);

		$recorder->reachedNobody(ruleId: '   ');

		$this->assertCount(1, $logger->warnings);
		$this->assertSame('(unnamed rule)', $logger->warnings[0]['context']['rule']);
	}//end testAnUnnamedRuleIsStillReported()

	/**
	 * The warning explains why, because the reader is an administrator meeting
	 * an empty group for the first time, not the developer who wrote this.
	 *
	 * @return void
	 */
	public function testTheWarningExplainsWhyItReachedNobody(): void {
		$logger = $this->logger();
		$recorder = new RuleReachRecorder(logger: $logger);

		$recorder->reachedNobody(ruleId: 'ruleA');

		$this->assertStringContainsString('nothing was sent', $logger->warnings[0]['context']['why']);
		$this->assertStringContainsString('newly provisioned group', $logger->warnings[0]['context']['why']);
	}//end testTheWarningExplainsWhyItReachedNobody()
}//end class
