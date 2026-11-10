<?php

/**
 * OpenRegister apply rule services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;

/**
 * The rule inventory and calculation evaluator the apply-rule action finds and replays a rule with.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final readonly class ApplyRuleServices {
	/**
	 * Bundle the services.
	 *
	 * @param RuleInventoryServiceInterface $inventory Finds the declared rule.
	 * @param CalculationEvaluatorInterface $evaluator Evaluates the rule's expression.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public RuleInventoryServiceInterface $inventory,
		public CalculationEvaluatorInterface $evaluator,
	) {
	}//end __construct()
}//end class
