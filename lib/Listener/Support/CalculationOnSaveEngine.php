<?php

/**
 * OpenRegister CalculationOnSaveEngine
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;

/**
 * What the save-time calculation pass evaluates with: the payload it builds,
 * the evaluator, and the rule run log each verdict goes to.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationOnSaveEngine {
	/**
	 * Bundle the services.
	 *
	 * @param CalculationEvaluatorInterface $evaluator      Expression evaluator.
	 * @param CalculationPayloadBuilder     $payloadBuilder Shared @self/@ref/@aggregate payload prep.
	 * @param RuleRunRecorderInterface      $ruleRuns       Records each calculation's verdict for the rule inventory.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly CalculationEvaluatorInterface $evaluator,
		public readonly CalculationPayloadBuilder $payloadBuilder,
		public readonly RuleRunRecorderInterface $ruleRuns,
	) {
	}//end __construct()
}//end class
