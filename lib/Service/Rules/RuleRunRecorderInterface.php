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
	 * @param string        $ruleId       The derived rule id.
	 * @param string        $schemaSlug   The slug of the schema the rule is declared on.
	 * @param RuleTrace     $trace        The verdict and the operand that decided it.
	 * @param string|null   $objectUuid   The object evaluated, when there was one.
	 * @param string|null   $registerSlug The register the object lives in.
	 * @param DateTime|null $moment       The moment of evaluation; defaults to now.
	 *
	 * @return void
	 */
	public function record(
		string $ruleId,
		string $schemaSlug,
		RuleTrace $trace,
		?string $objectUuid = null,
		?string $registerSlug = null,
		?DateTime $moment = null,
	): void;
}//end interface
