<?php

/**
 * A condition that could not be evaluated, which is a refusal and not a false.
 *
 * 🔴 IT HAS TO BE AN EXCEPTION, NOT A `false`. A rules engine that answers
 * false for a condition it could not resolve fails OPEN one negation later:
 * `{"not": {"$condition": "spoedeisend"}}` with `spoedeisend` unresolvable
 * would evaluate to true and the rule would fire on everything. ADR-005 says a
 * rule that cannot be evaluated is a refusal, never a silent pass, and a
 * boolean return type has nowhere to put that.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use RuntimeException;
use Throwable;

/**
 * Raised when a condition cannot be resolved at evaluation time.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
class ConditionRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $conditionName What could not be resolved.
	 * @param string         $why           Why it could not.
	 * @param Throwable|null $previous      Previous exception.
	 */
	public function __construct(
		private readonly string $conditionName,
		private readonly string $why,
		?Throwable $previous = null,
	) {
		parent::__construct(
			message: sprintf('[Rules] condition "%s" could not be resolved: %s', $conditionName, $why),
			code: 422,
			previous: $previous
		);
	}//end __construct()

	/**
	 * The condition the run log should name.
	 *
	 * @return string The name.
	 */
	public function getConditionName(): string {
		return $this->conditionName;
	}//end getConditionName()

	/**
	 * Why it could not be resolved, for the run log.
	 *
	 * @return string The reason.
	 */
	public function getWhy(): string {
		return $this->why;
	}//end getWhy()
}//end class
