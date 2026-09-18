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

namespace OCA\OpenRegister\Service\Notification;

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
	 * Wire the recorder.
	 *
	 * @param LoggerInterface $logger Where the one line per rule goes.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * Note that this rule reached nobody for one object.
	 *
	 * @param string $ruleId     The rule.
	 * @param string $objectUuid The object it was evaluated for.
	 *
	 * @return void
	 */
	public function reachedNobody(string $ruleId, string $objectUuid = ''): void {
		$rule = trim($ruleId);
		if ($rule === '') {
			$rule = '(unnamed rule)';
		}

		$seen = ($this->reachedNobody[$rule] ?? 0);
		$this->reachedNobody[$rule] = ($seen + 1);

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
	 * Note that this rule did reach somebody, so a later run can tell the
	 * difference between a rule that is quiet and one that is unstaffed.
	 *
	 * @param string $ruleId The rule.
	 *
	 * @return void
	 */
	public function reachedSomebody(string $ruleId): void {
		unset($ruleId);
	}//end reachedSomebody()

	/**
	 * Every rule that reached nobody this run, with how often.
	 *
	 * Returned rather than only logged, so a caller can put it on a screen. A
	 * finding that exists only in a log file is findable by whoever already
	 * suspects it.
	 *
	 * 🔴 THIS METHOD HAS NO CALLER, measured on `parity/round2` at
	 * `1a895e046`. Nothing in `lib` calls it, and `RuleReachRecorder` has no
	 * registration in `lib/AppInfo/`: the dispatcher builds its own and keeps
	 * it private, so this aggregate is discarded with that instance. It is not
	 * merely uncalled, it is unreachable.
	 *
	 * What is real today is the one warning line per rule per run, which an
	 * administrator finds only by searching for `self::MARKER`, which means
	 * only if they already suspect the problem. Do not read the tests on this
	 * class as evidence that an operator is being told.
	 *
	 * What would give it a caller is written down rather than left to be
	 * rediscovered: tasks 3.1 to 3.3 of
	 * `openspec/changes/a-rule-that-reaches-nobody-says-so`, the smallest of
	 * which is to register this as a shared service and read it on the
	 * notification settings page, where an administrator configuring
	 * notifications is already standing.
	 *
	 * @return array<string, mixed> The report.
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
	 */
	public function reset(): void {
		$this->reachedNobody = [];
	}//end reset()
}//end class
