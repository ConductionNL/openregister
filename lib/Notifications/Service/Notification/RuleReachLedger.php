<?php

/**
 * The persisted record of which notification rules reached nobody.
 *
 * `RuleReachRecorder` counts per process: a dispatch runs in the request or
 * background job that saved an object, and the administrator's settings page
 * is served by another request, which would read an empty report. This ledger
 * keeps the counts across processes, in one lazily loaded app config value, so
 * the page can say which rules reached nobody, how often in their latest run
 * and when that last happened.
 *
 * A rule that later reaches somebody is dropped, so the list names the rules
 * that are unstaffed now rather than every rule that ever was.
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
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and writes the per-rule reach record kept in app config.
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */
class RuleReachLedger {

	/**
	 * The app config key holding the record.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'notification_rule_reach';

	/**
	 * The most rules kept, so a misconfigured import cannot grow the value without bound.
	 *
	 * @var integer
	 */
	public const MAX_RULES = 200;

	/**
	 * Wire the ledger.
	 *
	 * @param IAppConfig $appConfig Where the record is kept.
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * Store this process's counts for the rules it saw reach nobody.
	 *
	 * The count stored is the one of the latest run that saw the rule: a run
	 * reports its own total, so a later write from the same run replaces the
	 * earlier, smaller one rather than adding to it.
	 *
	 * @param array<string, int> $counts Occurrences per rule in this run.
	 * @param int                $now    The time of the write, as a unix timestamp.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function record(array $counts, int $now): void {
		if ($counts === []) {
			return;
		}

		$rules = $this->stored();
		foreach ($counts as $rule => $count) {
			$rules[(string) $rule] = [
				'occurrences' => max(1, (int) $count),
				'lastSeen'    => $now,
			];
		}

		$this->store(rules: self::newestFirst(rules: $rules));
	}//end record()

	/**
	 * Drop a rule that reached somebody, so it no longer reads as unstaffed.
	 *
	 * Writes only when the rule is on record: this runs for every rule that
	 * reached anybody, and the value is read from the app config cache.
	 *
	 * @param string $rule The rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function forget(string $rule): void {
		$rules = $this->stored();
		if (array_key_exists($rule, $rules) === false) {
			return;
		}

		unset($rules[$rule]);
		$this->store(rules: $rules);
	}//end forget()

	/**
	 * The rules on record, newest first, in the shape the settings page reads.
	 *
	 * @return array{
	 *     rulesReachingNobody: list<array{rule: string, occurrences: int, lastSeen: string}>,
	 *     ruleCount: int,
	 *     occurrences: int,
	 *     needsAPerson: bool
	 * }
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	public function report(): array {
		$list  = [];
		$total = 0;
		foreach (self::newestFirst(rules: $this->stored()) as $rule => $entry) {
			$list[] = [
				'rule'        => (string) $rule,
				'occurrences' => $entry['occurrences'],
				'lastSeen'    => gmdate('Y-m-d\TH:i:s\Z', $entry['lastSeen']),
			];
			$total += $entry['occurrences'];
		}

		return [
			'rulesReachingNobody' => $list,
			'ruleCount'           => count($list),
			// Kept apart from the rule count: one rule failing four hundred
			// times and four hundred rules failing once are different problems.
			'occurrences'         => $total,
			'needsAPerson'        => ($list !== []),
		];
	}//end report()

	/**
	 * The stored record, with malformed entries left out.
	 *
	 * @return array<string, array{occurrences: int, lastSeen: int}>
	 */
	private function stored(): array {
		$raw   = $this->appConfig->getValueArray(AppIdentity::APP_ID, self::CONFIG_KEY, [], true);
		$rules = [];
		foreach ($raw as $rule => $entry) {
			if (is_string($rule) === false || is_array($entry) === false) {
				continue;
			}

			$rules[$rule] = [
				'occurrences' => max(1, (int) ($entry['occurrences'] ?? 1)),
				'lastSeen'    => (int) ($entry['lastSeen'] ?? 0),
			];
		}

		return $rules;
	}//end stored()

	/**
	 * Write the record, keeping at most MAX_RULES of the newest.
	 *
	 * @param array<string, array{occurrences: int, lastSeen: int}> $rules The record, newest first.
	 *
	 * @return void
	 */
	private function store(array $rules): void {
		$this->appConfig->setValueArray(
			AppIdentity::APP_ID,
			self::CONFIG_KEY,
			array_slice($rules, 0, self::MAX_RULES, true),
			true
		);
	}//end store()

	/**
	 * Order the record by last seen, newest first; equal times keep their order.
	 *
	 * @param array<string, array{occurrences: int, lastSeen: int}> $rules The record.
	 *
	 * @return array<string, array{occurrences: int, lastSeen: int}>
	 */
	private static function newestFirst(array $rules): array {
		uasort(
			$rules,
			static fn (array $left, array $right): int => ($right['lastSeen'] <=> $left['lastSeen'])
		);

		return $rules;
	}//end newestFirst()
}//end class
