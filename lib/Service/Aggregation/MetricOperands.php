<?php

/**
 * OpenRegister MetricOperands
 *
 * The two known operands of one binary operator in a derived-metric expression.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * The left and right operand MetricExpressionEvaluator applies one operator to,
 * once neither side is null.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class MetricOperands {

	/**
	 * Constructor.
	 *
	 * @param float $lhs The left operand.
	 * @param float $rhs The right operand.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly float $lhs,
		public readonly float $rhs,
	) {
	}//end __construct()
}//end class
