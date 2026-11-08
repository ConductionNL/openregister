<?php

/**
 * RuleRunRecorderInterface: what callers outside the Service component ask of RuleRunRecorder.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;

/**
 * Recording rule evaluations, as the rule listeners and the retention job use
 * it. Only the methods those callers call.
 */
interface RuleRunRecorderInterface {

	/**
	 * How many days rule runs are kept.
	 *
	 * @return integer The retention period in days, always at least one.
	 */
	public function retentionDays(): int;

	/**
	 * Record one rule evaluation.
	 *
	 * @param RuleRunSubject $subject The rule evaluated and the object it was evaluated on.
	 * @param RuleTrace      $trace   The verdict and the operand that decided it.
	 * @param DateTime|null  $moment  The moment of evaluation; defaults to now.
	 *
	 * @return void
	 */
	public function record(RuleRunSubject $subject, RuleTrace $trace, ?DateTime $moment = null): void;
}//end interface
