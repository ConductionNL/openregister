<?php

/**
 * The refusal the bulk job executor records when the actor may not write an object.
 *
 * The executor decides these refusals itself, before any action runs: the
 * object is not visible to the actor, its schema does not resolve, or the
 * schema's update rule does not include the actor. The outcome is always
 * refused, never skipped, and the rule is named (D-3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use OCA\OpenRegister\BulkAction\BulkActionOutcomeInterface;
use OCA\OpenRegister\Db\BulkJobMember;

/**
 * A refused outcome naming the rule that refused the write.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkJobRefusal implements BulkActionOutcomeInterface {
	/**
	 * Constructor.
	 *
	 * @param string $rule The rule that refused the write.
	 */
	public function __construct(
		private readonly string $rule,
	) {
	}//end __construct()

	/**
	 * The outcome name.
	 *
	 * @return string Always BulkJobMember::OUTCOME_REFUSED.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getOutcome(): string {
		return BulkJobMember::OUTCOME_REFUSED;
	}//end getOutcome()

	/**
	 * The rule that refused the write.
	 *
	 * @return string The rule.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getReason(): string {
		return $this->rule;
	}//end getReason()

	/**
	 * A refusal never counts as a write.
	 *
	 * @return bool Always false.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function isApplied(): bool {
		return false;
	}//end isApplied()
}//end class
