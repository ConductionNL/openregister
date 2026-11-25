<?php

/**
 * The persisted record of rules that reached nobody.
 *
 * The recorder counts per process; the administrator's settings page is
 * served by another request. These tests pin what the ledger keeps between
 * the two: the latest run's count per rule, when it was last seen, newest
 * first, and a rule dropped once it reaches somebody.
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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger
 */
class RuleReachLedgerTest extends TestCase {

	/**
	 * What the app config double holds, per key.
	 *
	 * @var array<string, array<mixed>>
	 */
	private array $values = [];

	/**
	 * How many times the app config double was written.
	 *
	 * @var integer
	 */
	private int $writes = 0;

	public function testAnEmptyLedgerNeedsNobody(): void {
		$this->assertSame(
			['rulesReachingNobody' => [], 'ruleCount' => 0, 'occurrences' => 0, 'needsAPerson' => false],
			$this->ledger()->report()
		);
	}//end testAnEmptyLedgerNeedsNobody()

	public function testARecordedRuleIsReportedWithItsCountAndTime(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['case-assigned' => 3], now: 1700000000);

		$report = $ledger->report();

		$this->assertSame(
			[['rule' => 'case-assigned', 'occurrences' => 3, 'lastSeen' => '2023-11-14T22:13:20Z']],
			$report['rulesReachingNobody']
		);
		$this->assertSame(1, $report['ruleCount']);
		$this->assertSame(3, $report['occurrences']);
		$this->assertTrue($report['needsAPerson']);
	}//end testARecordedRuleIsReportedWithItsCountAndTime()

	public function testALaterWriteOfTheSameRunReplacesTheCountRatherThanAddingToIt(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['case-assigned' => 2], now: 100);
		$ledger->record(counts: ['case-assigned' => 4], now: 200);

		$this->assertSame(4, $ledger->report()['rulesReachingNobody'][0]['occurrences']);
		$this->assertSame(4, $ledger->report()['occurrences']);
	}//end testALaterWriteOfTheSameRunReplacesTheCountRatherThanAddingToIt()

	public function testTheRuleCountAndTheOccurrencesAreKeptApart(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['a' => 400], now: 100);
		$ledger->record(counts: ['b' => 1, 'c' => 1], now: 100);

		$report = $ledger->report();

		$this->assertSame(3, $report['ruleCount']);
		$this->assertSame(402, $report['occurrences']);
	}//end testTheRuleCountAndTheOccurrencesAreKeptApart()

	public function testTheNewestRuleComesFirst(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['old' => 1], now: 100);
		$ledger->record(counts: ['new' => 1], now: 300);
		$ledger->record(counts: ['middle' => 1], now: 200);

		$this->assertSame(['new', 'middle', 'old'], array_column($ledger->report()['rulesReachingNobody'], 'rule'));
	}//end testTheNewestRuleComesFirst()

	public function testTheRecordSurvivesIntoAnotherLedgerOverTheSameConfig(): void {
		$this->ledger()->record(counts: ['case-assigned' => 5], now: 100);

		// A second instance, as the settings request builds its own.
		$this->assertSame(5, $this->ledger()->report()['occurrences']);
	}//end testTheRecordSurvivesIntoAnotherLedgerOverTheSameConfig()

	public function testForgettingARuleDropsOnlyThatRule(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['a' => 1, 'b' => 2], now: 100);

		$ledger->forget(rule: 'a');

		$this->assertSame(['b'], array_column($ledger->report()['rulesReachingNobody'], 'rule'));
	}//end testForgettingARuleDropsOnlyThatRule()

	public function testForgettingARuleNotOnRecordWritesNothing(): void {
		$ledger = $this->ledger();
		$ledger->record(counts: ['a' => 1], now: 100);
		$writes = $this->writes;

		$ledger->forget(rule: 'never-recorded');

		$this->assertSame($writes, $this->writes);
	}//end testForgettingARuleNotOnRecordWritesNothing()

	public function testAnEmptyRunWritesNothing(): void {
		$this->ledger()->record(counts: [], now: 100);

		$this->assertSame(0, $this->writes);
	}//end testAnEmptyRunWritesNothing()

	public function testTheRecordIsCappedKeepingTheNewest(): void {
		for ($i = 0; $i < (RuleReachLedger::MAX_RULES + 5); $i++) {
			$this->ledger()->record(counts: ['rule-'.$i => 1], now: (1000 + $i));
		}

		$rules = array_column($this->ledger()->report()['rulesReachingNobody'], 'rule');

		$this->assertCount(RuleReachLedger::MAX_RULES, $rules);
		$this->assertSame('rule-'.(RuleReachLedger::MAX_RULES + 4), $rules[0]);
		$this->assertNotContains('rule-0', $rules);
	}//end testTheRecordIsCappedKeepingTheNewest()

	public function testMalformedStoredEntriesAreLeftOut(): void {
		$this->values[RuleReachLedger::CONFIG_KEY] = [
			'good' => ['occurrences' => 2, 'lastSeen' => 100],
			'broken' => 'not an entry',
			7 => ['occurrences' => 1, 'lastSeen' => 100],
		];

		$this->assertSame(['good'], array_column($this->ledger()->report()['rulesReachingNobody'], 'rule'));
	}//end testMalformedStoredEntriesAreLeftOut()

	public function testTheValueIsStoredLazily(): void {
		$lazy = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn([]);
		$config->expects($this->once())->method('setValueArray')->willReturnCallback(
			static function (string $app, string $key, array $value, bool $isLazy) use (&$lazy): bool {
				$lazy = [$app, $key, $isLazy];
				return true;
			}
		);

		(new RuleReachLedger(appConfig: $config))->record(counts: ['a' => 1], now: 100);

		$this->assertSame(['openregister', RuleReachLedger::CONFIG_KEY, true], $lazy);
	}//end testTheValueIsStoredLazily()

	/**
	 * A ledger over the in-memory app config of this test.
	 *
	 * @return RuleReachLedger
	 */
	private function ledger(): RuleReachLedger {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = []): array => ($this->values[$key] ?? $default)
		);
		$config->method('setValueArray')->willReturnCallback(
			function (string $app, string $key, array $value): bool {
				$this->values[$key] = $value;
				$this->writes++;
				return true;
			}
		);

		return new RuleReachLedger(appConfig: $config);
	}//end ledger()
}//end class
