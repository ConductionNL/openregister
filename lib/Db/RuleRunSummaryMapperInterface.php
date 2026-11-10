<?php

/**
 * RuleRunSummaryMapperInterface: what callers outside the Db component use of RuleRunSummaryMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The methods of RuleRunSummaryMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to RuleRunSummaryMapper.
 */
interface RuleRunSummaryMapperInterface {

	/**
	 * Every summary for one schema, keyed by rule id.
	 *
	 * The inventory renders every rule on a schema at once, so it loads every
	 * summary in ONE read rather than one per entry.
	 *
	 * @param string $schemaSlug The schema's slug.
	 *
	 * @return array<string, RuleRunSummary> Summaries keyed by rule id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findBySchema(string $schemaSlug): array;

	/**
	 * The rules whose last evaluation left an error, most recent first.
	 *
	 * NARROWED IN SQL RATHER THAN IN THE READER, and the reason is not
	 * tidiness. Ordering every rule by `last_error_at DESC` and filtering
	 * afterwards is wrong on Postgres, where a descending sort puts NULLs
	 * FIRST: the rules that have never errored would fill the page and push
	 * the ones an administrator has to act on off the end, silently, while
	 * MySQL put them last and the same code looked correct. A `WHERE` that
	 * removes them is the same answer on both.
	 *
	 * One row per rule, so this table is as long as the instance has rules
	 * rather than as long as it has evaluations.
	 *
	 * @param int $limit How many summaries to return.
	 *
	 * @return array<int, RuleRunSummary> The summaries.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function findHoldingAnError(int $limit = 50): array;

	/**
	 * Record one evaluation against a rule's summary.
	 *
	 * Creates the row on the rule's first evaluation and updates it after. An
	 * error keeps its own pair of columns, so the last error survives a
	 * thousand successful runs and is still there when an administrator asks
	 * why a rule stopped being trusted.
	 *
	 * THROTTLED, BECAUSE THIS RUNS ON EVERY SAVE. A rule that fires on every
	 * object save would otherwise cost one UPDATE per save per rule, and an
	 * import of four hundred thousand objects would spend most of its time
	 * rewriting a timestamp nobody is watching change. So a repeat of the same
	 * verdict inside the throttle window is not written: `lastRun` is then
	 * accurate to the window rather than to the second, which is the resolution
	 * "has this rule run in ninety days" actually needs. A CHANGED verdict and
	 * an error are always written, because those are the two things an
	 * administrator is reading the summary for.
	 *
	 * @param string $ruleId The derived rule id.
	 * @param string $schemaSlug The schema the rule is declared on.
	 * @param string $verdict The verdict reached.
	 * @param DateTime $moment When the evaluation happened.
	 * @param string|null $error The message, when the verdict was an error.
	 *
	 * @return RuleRunSummary The stored summary, written or not.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function record(
		string $ruleId,
		string $schemaSlug,
		string $verdict,
		DateTime $moment,
		?string $error = null,
	): RuleRunSummary;
}//end interface
