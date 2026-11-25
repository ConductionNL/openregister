<?php

/**
 * Recording that a notification rule reached nobody, without making a storm of it.
 *
 * 🔴 A RULE THAT RESOLVES TO NOBODY USED TO `continue`. No log, no counter, no
 * complaint. And declared groups ship EMPTY on purpose across this fleet — an
 * empty group denies everyone except admins and object owners, which is the
 * right default — so on a fresh install a rule addressed to a declared group
 * resolves to zero recipients and silently does nothing. Every annotation added
 * on top of that reaches nobody, and reports the same success it would report
 * if it had reached everybody.
 *
 * 🔴 BUT AN EMPTY GROUP ON A QUIET INSTANCE IS LEGITIMATE, so this must not
 * become an error. A sweep over four hundred objects with one unstaffed group
 * would otherwise write four hundred warnings, and a log nobody can read is the
 * same silence with more noise in front of it.
 *
 * So it is recorded ONCE PER RULE per run, at warning, with a stable marker and
 * the rule's own name. One unstaffed rule is one line whatever the sweep's size,
 * and `report()` hands back the whole set so a caller can surface it somewhere a
 * person actually looks.
 *
 * The counts also go to a `RuleReachLedger`, which outlives this process, so the
 * administrator's notification settings read them from another request. The
 * ledger is written on a rule's first occurrence and again each time its count
 * doubles, and once more when the recorder is released: a storm of four hundred
 * costs nine writes, not four hundred.
 *
 * ⚠️ IT DOES NOT REDIRECT THE MESSAGE. A fallback recipient — administrators,
 * say — was the obvious alternative and is the wrong one. It would mail every
 * misconfigured rule to the same people until they stop reading any of it, and
 * some of these messages carry case content addressed to a group chosen
 * precisely because it may see that case. Making the silence visible is a
 * smaller change than deciding on somebody's behalf who may read a notification.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Notification
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Psr\Log\LoggerInterface;

/**
 * Notes which rules reached nobody, once each, and hands the set back.
 */
class RuleReachRecorder {

	/**
	 * The marker a log search looks for.
	 *
	 * @var string
	 */
	public const MARKER = '[notification] rule reached nobody';

	/**
	 * Rules already recorded this run, so one rule is one line.
	 *
	 * @var array<string, int>
	 */
	private array $reachedNobody = [];

	/**
	 * Counts not yet written to the ledger, per rule.
	 *
	 * @var array<string, true>
	 */
	private array $unwritten = [];

	/**
	 * Wire the recorder.
	 *
	 * @param LoggerInterface  $logger Where the one line per rule goes.
	 * @param ?RuleReachLedger $ledger Where the counts outlive this process; none keeps them in memory only.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?RuleReachLedger $ledger = null,
	) {
	}//end __construct()

	/**
	 * Write what is still unwritten when the recorder is released.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function __destruct() {
		$this->flush();
	}//end __destruct()

	/**
	 * Note that this rule reached nobody for one object.
	 *
	 * @param string $ruleId     The rule.
	 * @param string $objectUuid The object it was evaluated for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
	 */
	public function reachedNobody(string $ruleId, string $objectUuid = ''): void {
		$rule = self::ruleName(ruleId: $ruleId);

		$seen = ($this->reachedNobody[$rule] ?? 0);
		$this->reachedNobody[$rule] = ($seen + 1);
		$this->persistOnDoubling(rule: $rule, count: ($seen + 1));

		if ($seen > 0) {
			// Already said for this rule in this run. Counting continues; the
			// log does not, because four hundred identical lines is the same
			// silence with noise in front of it.
			return;
		}

		$this->logger->warning(
			self::MARKER,
			[
				'rule' => $rule,
				'object' => $objectUuid,
				'why' => 'the rule resolved to no recipients, so nothing was sent and nobody was told. '
					.'A declared group that is empty resolves to nobody, which is the default state of a '
					.'newly provisioned group.',
			]
		);
	}//end reachedNobody()

	/**
	 * The rule's name as it is counted and logged; a blank one is "(unnamed rule)".
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return string
	 */
	private static function ruleName(string $ruleId): string {
		$rule = trim($ruleId);
		if ($rule === '') {
			return '(unnamed rule)';
		}

		return $rule;
	}//end ruleName()

	/**
	 * Note that this rule did reach somebody, so a later run can tell the
	 * difference between a rule that is quiet and one that is unstaffed.
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
	 */
	public function reachedSomebody(string $ruleId): void {
		$rule = self::ruleName(ruleId: $ruleId);
		if (isset($this->reachedNobody[$rule]) === true) {
			// It reached nobody earlier in this same run: the record stands.
			return;
		}

		try {
			$this->ledger?->forget(rule: $rule);
		} catch (\Throwable $error) {
			$this->logger->debug(self::MARKER.': could not clear the record', ['rule' => $rule, 'error' => $error->getMessage()]);
		}
	}//end reachedSomebody()

	/**
	 * Write every count not yet written to the ledger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function flush(): void {
		if ($this->ledger === null || $this->unwritten === []) {
			return;
		}

		$counts = array_intersect_key($this->reachedNobody, $this->unwritten);
		$this->unwritten = [];
		$this->write(counts: $counts);
	}//end flush()

	/**
	 * Write the rule's count now when it is a power of two, else mark it unwritten.
	 *
	 * @param string $rule  The rule.
	 * @param int    $count Its count in this run.
	 *
	 * @return void
	 */
	private function persistOnDoubling(string $rule, int $count): void {
		if ($this->ledger === null) {
			return;
		}

		if (($count & ($count - 1)) !== 0) {
			$this->unwritten[$rule] = true;
			return;
		}

		unset($this->unwritten[$rule]);
		$this->write(counts: [$rule => $count]);
	}//end persistOnDoubling()

	/**
	 * Hand counts to the ledger; a failing store never fails the dispatch.
	 *
	 * @param array<string, int> $counts Occurrences per rule.
	 *
	 * @return void
	 */
	private function write(array $counts): void {
		try {
			$this->ledger?->record(counts: $counts, now: time());
		} catch (\Throwable $error) {
			$this->logger->debug(self::MARKER.': could not store the record', ['error' => $error->getMessage()]);
		}
	}//end write()

	/**
	 * Every rule that reached nobody this run, with how often.
	 *
	 * Returned rather than only logged, so a caller can put it on a screen. A
	 * finding that exists only in a log file is findable by whoever already
	 * suspects it.
	 *
	 * This is the report of THIS process only. The administrator's settings
	 * page reads `RuleReachLedger::report()`, which the counts are written to
	 * and which outlives the request that dispatched.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
	 */
	public function report(): array {
		$rules = [];
		foreach ($this->reachedNobody as $rule => $count) {
			$rules[] = ['rule' => $rule, 'occurrences' => $count];
		}

		return [
			'rulesReachingNobody' => $rules,
			'ruleCount' => count($rules),
			// The total is kept apart from the rule count: one rule failing
			// four hundred times and four hundred rules failing once are very
			// different problems.
			'occurrences' => array_sum($this->reachedNobody),
			'needsAPerson' => ($rules !== []),
		];
	}//end report()

	/**
	 * Forget what this run recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md
	 */
	public function reset(): void {
		$this->flush();
		$this->reachedNobody = [];
	}//end reset()
}//end class
