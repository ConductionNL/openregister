<?php

/**
 * ConditionTracerInterface: what callers outside the Service component ask of ConditionTracer.
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

/**
 * Tracing which operand decided a condition, as the lifecycle validation
 * listener uses it. Only the method that caller calls.
 */
interface ConditionTracerInterface {

	/**
	 * The verdict of a condition with the operand that decided it.
	 *
	 * @param ConditionJudgement $judgement The condition, the data it was evaluated against, the verdict the owning
	 *                                      engine reached and that engine's own sentence, when it has one.
	 *
	 * @return RuleTrace The verdict with its deciding operand, when one decided.
	 */
	public function trace(ConditionJudgement $judgement): RuleTrace;
}//end interface
