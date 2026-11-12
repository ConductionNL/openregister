<?php

/**
 * OpenRegister RematerialiseEvaluation
 *
 * The evaluator and reference pre-resolvers the rematerialise-calculations command computes with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command\Support;

use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolverInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\ReferenceResolverInterface;

/**
 * The evaluator and reference pre-resolvers the rematerialise-calculations command computes with.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class RematerialiseEvaluation {
	/**
	 * Bundle the services.
	 *
	 * @param CalculationEvaluatorInterface       $evaluator  Expression evaluator.
	 * @param ReferenceResolverInterface          $references Cross-object reference pre-resolver.
	 * @param AggregateReferenceResolverInterface $aggregates Aggregate-reference pre-resolver.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly CalculationEvaluatorInterface $evaluator,
		public readonly ReferenceResolverInterface $references,
		public readonly AggregateReferenceResolverInterface $aggregates,
	) {
	}//end __construct()
}//end class
