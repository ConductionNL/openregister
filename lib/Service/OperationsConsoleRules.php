<?php

/**
 * The rule runs the operations console lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapperInterface;

/**
 * The rules engine's run log and per-rule summaries the operations console reads.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
final class OperationsConsoleRules {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RuleRunMapper        $ruleRuns      The rules engine's run log.
	 * @param RuleRunSummaryMapperInterface $ruleSummaries One row per rule, with its last error.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function __construct(
		public readonly RuleRunMapper $ruleRuns,
		public readonly RuleRunSummaryMapperInterface $ruleSummaries,
	) {
	}//end __construct()
}//end class
