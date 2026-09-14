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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\RuleRun;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
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
 */
class RuleRunRecorder {

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
	 * @param RuleRunMapper $runs The detail log.
	 * @param RuleRunSummaryMapper $summaries Last run and last error per rule.
	 * @param IUserSession $userSession Names the actor whose write was evaluated.
	 * @param IAppConfig $config Reads whether the detail log is on.
	 * @param LoggerInterface $logger Reports a log write that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleRunMapper $runs,
		private readonly RuleRunSummaryMapper $summaries,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one evaluation.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string $schemaSlug The slug of the schema the rule is declared on.
	 * @param RuleTrace $trace The verdict and the operand that decided it.
	 * @param string|null $objectUuid The object evaluated, when there was one.
	 * @param string|null $registerSlug The register the object lives in.
	 * @param DateTime|null $at The moment of evaluation; defaults to now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function record(
		string $ruleId,
		string $schemaSlug,
		RuleTrace $trace,
		?string $objectUuid = null,
		?string $registerSlug = null,
		?DateTime $at = null,
	): void {
		$moment = ($at ?? new DateTime());

		try {
			$this->summaries->record(
				ruleId: $ruleId,
				schemaSlug: $schemaSlug,
				verdict: $trace->getVerdict(),
				at: $moment,
				error: ($trace->getVerdict() === RuleVocabulary::VERDICT_ERROR ? $trace->getMessage() : null)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RuleRunRecorder] Could not update the summary for rule ' . $ruleId . ': ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}

		if ($this->detailEnabled() === false) {
			return;
		}

		try {
			$run = new RuleRun();
			$run->setRuleId($ruleId);
			$run->setSchemaSlug($schemaSlug);
			$run->setRegisterSlug($registerSlug);
			$run->setObjectUuid($objectUuid);
			$run->setVerdict($trace->getVerdict());
			$run->setOperand($this->cut(value: $trace->getOperand()));
			$run->setOperandValue($this->cut(value: $trace->getOperandValue()));
			$run->setMessage($trace->getMessage());
			$run->setActor($this->userSession->getUser()?->getUID());
			$run->setCreated($moment);

			$this->runs->insert($run);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RuleRunRecorder] Could not write a run row for rule ' . $ruleId . ': ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()

	/**
	 * Whether the detail log is switched on for this instance.
	 *
	 * @return bool True when evaluations write detail rows.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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
