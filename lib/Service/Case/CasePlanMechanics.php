<?php

/**
 * OpenRegister Case Plan Mechanics
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

/**
 * How a plan moves: the one transition path, the bounded cascade, the entry criteria and the definition compiler.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final readonly class CasePlanMechanics {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CasePlanStateMachine $machine The one transition path.
	 * @param CasePlanCascade $cascade The bounded fixpoint.
	 * @param CaseSentryEvaluator $sentries Entry criteria for the enableable query.
	 * @param CasePlanDefinition $definitions Validates and compiles definitions.
	 */
	public function __construct(
		public CasePlanStateMachine $machine,
		public CasePlanCascade $cascade,
		public CaseSentryEvaluator $sentries,
		public CasePlanDefinition $definitions,
	) {
	}//end __construct()
}//end class
