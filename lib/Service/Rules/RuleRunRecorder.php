<?php

/**
 * OpenRegister RuleRunRecorder
 *
 * Writes one rule evaluation to the run log and folds it into the rule's
 * summary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunSummary\RuleEvaluation;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place an evaluation becomes a row.
 *
 * FAILING TO LOG MUST NEVER FAIL THE SAVE. The recorder is called from inside
 * the save pipeline, on a path that has already decided what it is going to do.
 * A log row is an explanation of that decision, so a database that will not
 * take the row costs the explanation and nothing else: every write here is
 * caught and reported to the PSR logger. The inverse, a save that fails because
 * its audit of itself failed, is the outage this catch exists to prevent.
 *
 * THE LOG CAN BE SWITCHED OFF. A rule that fires on every object save writes a
 * row per save. That is the point on an instance that wants to know why a flow
 * did not run, and it is unwanted weight on an instance importing four hundred
 * thousand rows. The app config key turns the detail rows off; the summary is
 * kept either way, because it is one row per rule and it is what the inventory
 * reads.
 *
 * NOT `final`, and only so that the save-path listeners' unit tests can hand
 * one a double and assert what a save recorded without standing a database up
 * behind it. There is meant to be exactly one implementation in production.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class RuleRunRecorder implements RuleRunRecorderInterface {

	/**
	 * The app the settings live under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * App-config key: whether the detail log is written at all.
	 *
	 * @var string
	 */
	public const CONFIG_ENABLED = 'rule_run_log_enabled';

	/**
	 * App-config key: how many days of detail rows are kept.
	 *
	 * @var string
	 */
	public const CONFIG_RETENTION_DAYS = 'rule_run_retention_days';

	/**
	 * Default retention period for the detail rows, in days.
	 *
	 * @var integer
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param RuleRunStores $stores The rule run rows and their summaries.
	 * @param IUserSession $userSession Names the actor whose write was evaluated.
	 * @param IAppConfig $config Reads whether the detail log is on.
	 * @param LoggerInterface $logger Reports a log write that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleRunStores $stores,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one evaluation.
	 *
	 * @param RuleRunSubject $subject The rule evaluated and the object it was evaluated on.
	 * @param RuleTrace $trace The verdict and the operand that decided it.
	 * @param DateTime|null $moment The moment of evaluation; defaults to now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function record(RuleRunSubject $subject, RuleTrace $trace, ?DateTime $moment = null): void {
		$this->recordRun(run: $this->runOf(subject: $subject, moment: ($moment ?? new DateTime())), trace: $trace);
	}//end record()

	/**
	 * The run row for one evaluation, before its verdict is known.
	 *
	 * @param RuleRunSubject $subject The rule evaluated and the object it was evaluated on.
	 * @param DateTime       $moment  The moment of evaluation.
	 *
	 * @return RuleRun The row.
	 */
	private function runOf(RuleRunSubject $subject, DateTime $moment): RuleRun {
		$run = new RuleRun();
		$run->setRuleId($subject->ruleId);
		$run->setSchemaSlug($subject->schemaSlug);
		$run->setRegisterSlug($subject->registerSlug);
		$run->setObjectUuid($subject->objectUuid);
		$run->setCreated($moment);

		return $run;
	}//end runOf()

	/**
	 * Update the summary and, when the detail log is on, write the run row.
	 *
	 * @param RuleRun   $run   The evaluation.
	 * @param RuleTrace $trace The verdict and the operand that decided it.
	 *
	 * @return void
	 */
	private function recordRun(RuleRun $run, RuleTrace $trace): void {
		$this->recordSummary(run: $run, trace: $trace);
		if ($this->detailEnabled() === true) {
			$this->recordDetail(run: $run, trace: $trace);
		}
	}//end recordRun()

	/**
	 * Update the rule's summary row: its last run and, on an error, its last error.
	 *
	 * @param RuleRun $run The evaluation's identity and moment.
	 * @param RuleTrace $trace The verdict and the operand that decided it.
	 *
	 * @return void
	 */
	private function recordSummary(RuleRun $run, RuleTrace $trace): void {
		try {
			$this->stores->summaries->record(
				run: $run,
				evaluation: new RuleEvaluation(verdict: $trace->getVerdict(), moment: $run->getCreated(), error: $this->errorOf(trace: $trace))
			);
		} catch (Throwable $e) {
			$this->warn(what: 'update the summary for rule ' . $run->getRuleId(), failure: $e);
		}
	}//end recordSummary()

	/**
	 * The error a summary keeps: the engine's sentence, on an error verdict only.
	 *
	 * @param RuleTrace $trace The verdict and its message.
	 *
	 * @return string|null The error, or null when the rule did not error.
	 */
	private function errorOf(RuleTrace $trace): ?string {
		if ($trace->getVerdict() === RuleVocabulary::VERDICT_ERROR) {
			return $trace->getMessage();
		}

		return null;
	}//end errorOf()

	/**
	 * Write the detail row: the verdict, the deciding operand and the actor.
	 *
	 * @param RuleRun $run The evaluation's identity and moment.
	 * @param RuleTrace $trace The verdict and the operand that decided it.
	 *
	 * @return void
	 */
	private function recordDetail(RuleRun $run, RuleTrace $trace): void {
		try {
			$run->setVerdict($trace->getVerdict());
			$run->setOperand($this->cut(value: $trace->getOperand()));
			$run->setOperandValue($this->cut(value: $trace->getOperandValue()));
			$run->setMessage($trace->getMessage());
			$run->setActor($this->userSession->getUser()?->getUID());

			$this->stores->runs->insert($run);
		} catch (Throwable $e) {
			$this->warn(what: 'write a run row for rule ' . $run->getRuleId(), failure: $e);
		}
	}//end recordDetail()

	/**
	 * Report a log write that failed, without letting it reach the save.
	 *
	 * @param string    $what    What could not be done for which rule, in words.
	 * @param Throwable $failure Why.
	 *
	 * @return void
	 */
	private function warn(string $what, Throwable $failure): void {
		$this->logger->warning(
			'[RuleRunRecorder] Could not ' . $what . ': ' . $failure->getMessage(),
			['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warn()

	/**
	 * Whether the detail log is switched on for this instance.
	 *
	 * @return bool True when evaluations write detail rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function detailEnabled(): bool {
		$configured = trim($this->config->getValueString(self::APP_ID, self::CONFIG_ENABLED, ''));
		if ($configured === '') {
			return true;
		}

		return in_array(strtolower($configured), ['0', 'false', 'no', 'off'], true) === false;
	}//end detailEnabled()

	/**
	 * How many days of detail rows this instance keeps.
	 *
	 * @return int The retention period in days, always at least one.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function retentionDays(): int {
		$configured = trim($this->config->getValueString(self::APP_ID, self::CONFIG_RETENTION_DAYS, ''));
		if ($configured === '' || ctype_digit($configured) === false) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		return max(1, (int)$configured);
	}//end retentionDays()

	/**
	 * Cut a string to the width its column actually has.
	 *
	 * The operand and its value are both `varchar(255)`. A dotted path through
	 * a deep object can be longer than that, and a driver that truncates
	 * silently would store a path that looks real and is not.
	 *
	 * @param string|null $value The string to store.
	 *
	 * @return string|null The string, cut to fit, or null.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function cut(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		if (mb_strlen($value) <= 255) {
			return $value;
		}

		return mb_substr($value, 0, 254) . '…';
	}//end cut()
}//end class
