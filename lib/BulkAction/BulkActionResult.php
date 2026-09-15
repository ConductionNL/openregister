<?php

/**
 * What a bulk action did, or would do, to one object.
 *
 * The three outcomes are deliberately separate. Applied is a write. Skipped
 * is "the action does not apply here, and here is why". Refused is "the actor
 * may not write this object, and here is the rule that says so". Collapsing
 * refused into skipped hides a permission problem inside a business outcome,
 * and the operator reads "12 skipped" and moves on (D-3).
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
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

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\BulkJobMember;

/**
 * An immutable per-object outcome.
 */
final class BulkActionResult {
	/**
	 * Constructor.
	 *
	 * @param string $outcome One of the BulkJobMember::OUTCOME_* constants.
	 * @param string|null $reason Why the outcome is what it is.
	 */
	private function __construct(
		private readonly string $outcome,
		private readonly ?string $reason = null,
	) {
	}//end __construct()

	/**
	 * The action applies to this object, or has applied to it.
	 *
	 * @param string|null $reason An optional note.
	 *
	 * @return self The result.
	 */
	public static function applied(?string $reason = null): self {
		return new self(outcome: BulkJobMember::OUTCOME_APPLIED, reason: $reason);
	}//end applied()

	/**
	 * The action does not apply to this object, and says why.
	 *
	 * @param string $reason Why it does not apply.
	 *
	 * @return self The result.
	 */
	public static function skipped(string $reason): self {
		return new self(outcome: BulkJobMember::OUTCOME_SKIPPED, reason: $reason);
	}//end skipped()

	/**
	 * The actor may not write this object, and the rule is named.
	 *
	 * @param string $rule The rule that refused the write.
	 *
	 * @return self The result.
	 */
	public static function refused(string $rule): self {
		return new self(outcome: BulkJobMember::OUTCOME_REFUSED, reason: $rule);
	}//end refused()

	/**
	 * The write threw, and the message is kept.
	 *
	 * @param string $message The failure message.
	 *
	 * @return self The result.
	 */
	public static function failed(string $message): self {
		return new self(outcome: BulkJobMember::OUTCOME_FAILED, reason: $message);
	}//end failed()

	/**
	 * The outcome name.
	 *
	 * @return string One of the BulkJobMember::OUTCOME_* constants.
	 */
	public function getOutcome(): string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * The reason behind the outcome.
	 *
	 * @return string|null The reason, or null when the outcome needs none.
	 */
	public function getReason(): ?string {
		return $this->reason;
	}//end getReason()

	/**
	 * Whether this result counts as a write.
	 *
	 * @return bool True when the action applied.
	 */
	public function isApplied(): bool {
		return $this->outcome === BulkJobMember::OUTCOME_APPLIED;
	}//end isApplied()
}//end class
