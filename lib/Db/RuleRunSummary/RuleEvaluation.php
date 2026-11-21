<?php

/**
 * RuleEvaluation: one evaluation of a rule, as its summary records it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\RuleRunSummary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\RuleRunSummary;

use DateTime;

/**
 * The verdict a rule reached, when, and the error message when it failed.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleEvaluation {

	/**
	 * Constructor.
	 *
	 * @param string      $verdict The verdict reached.
	 * @param DateTime    $moment  When the evaluation happened.
	 * @param string|null $error   The message, when the verdict was an error.
	 */
	public function __construct(
		public readonly string $verdict,
		public readonly DateTime $moment,
		public readonly ?string $error=null,
	) {
	}//end __construct()

	/**
	 * Whether the evaluation carries an error message.
	 *
	 * @return bool
	 */
	public function isError(): bool {
		return ($this->error !== null && $this->error !== '');
	}//end isError()
}//end class
