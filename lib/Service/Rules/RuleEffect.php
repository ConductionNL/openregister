<?php

/**
 * OpenRegister RuleEffect
 *
 * What a rule does when it fires, when it fires, how many objects one run may touch and which objects it is about.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The behaviour half of a rule descriptor.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleEffect {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>                         $actions    What the rule does when it fires.
	 * @param mixed                                      $condition  The rule's condition, as the author declared it.
	 * @param int|null                                   $maxObjects The ceiling the rule declares for one run.
	 * @param array{property: string, value: string}|null $scope      The objects the rule is about, or null for all.
	 */
	public function __construct(
		public readonly array $actions = [],
		public readonly mixed $condition = null,
		public readonly ?int $maxObjects = null,
		public readonly ?array $scope = null,
	) {
	}//end __construct()
}//end class
