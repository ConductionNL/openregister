<?php

/**
 * OpenRegister PropertyRbacRules
 *
 * What a property-level RBAC verdict is decided by: the condition matcher for
 * `match` expressions and the resolver of the lifecycle state's field rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;

/**
 * The rule collaborators of PropertyRbacHandler, wired by DI autowiring.
 */
final class PropertyRbacRules {
	/**
	 * Bundle the services.
	 *
	 * @param ConditionMatcher       $conditionMatcher Condition matcher for match expressions.
	 * @param StateFieldRuleResolver $stateFieldRules  Resolver for the lifecycle state's field rules.
	 */
	public function __construct(
		public readonly ConditionMatcher $conditionMatcher,
		public readonly StateFieldRuleResolver $stateFieldRules,
	) {
	}//end __construct()
}//end class
