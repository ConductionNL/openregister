<?php

/**
 * OpenRegister RuleCeilingService
 *
 * Counts what a rule run would touch and refuses the whole run above the
 * ceiling the rule declares, before the first write.
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
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * One gate, asked before anything is written.
 *
 * D-4: a ceiling that stops half way through leaves a partial mutation, which
 * is worse than the runaway it prevents. So this class takes a count, not an
 * iterator, and it is called with the whole selection resolved. It cannot be
 * asked "may I write the next one", because that question has no answer that
 * leaves the register consistent.
 *
 * A rule that declares no ceiling is not bounded here. That is deliberate and
 * it is what keeps the change backwards compatible: the instance-wide bulk job
 * ceiling still applies to a replay, and a schema that declares nothing behaves
 * exactly as it did (task 5.3).
 *
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class RuleCeilingService {

	/**
	 * The refusal code a run above the ceiling carries.
	 *
	 * @var string
	 */
	public const CODE_CEILING_EXCEEDED = 'rule-ceiling-exceeded';

	/**
	 * Refuse the run when the selection is larger than the rule's ceiling.
	 *
	 * @param RuleDescriptor $rule The rule whose ceiling is being applied.
	 * @param int $count The number of objects the run would touch, counted already.
	 *
	 * @return void
	 *
	 * @throws RuleCeilingException When the count is above the declared ceiling.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function assert(RuleDescriptor $rule, int $count): void {
		$ceiling = $rule->getMaxObjects();
		if ($ceiling === null || $count <= $ceiling) {
			return;
		}

		throw new RuleCeilingException(
			message: sprintf(
				'Rule "%s" allows at most %d objects in one run, and this selection holds %d. '
				. 'Nothing was written. Narrow the selection, or raise maxObjects on the rule.',
				$rule->getId(),
				$ceiling,
				$count
			),
			ruleId: $rule->getId(),
			ceiling: $ceiling,
			count: $count
		);
	}//end assert()

	/**
	 * Whether a run of this size is within the rule's ceiling.
	 *
	 * The read beside {@see self::assert()}, for a surface that wants to show
	 * the refusal rather than raise it. Both answer from the same comparison,
	 * so a preview cannot say "this fits" about a run the ceiling then refuses.
	 *
	 * @param RuleDescriptor $rule The rule whose ceiling is being applied.
	 * @param int $count The number of objects the run would touch.
	 *
	 * @return bool True when the run is allowed to proceed.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function allows(RuleDescriptor $rule, int $count): bool {
		$ceiling = $rule->getMaxObjects();

		return ($ceiling === null || $count <= $ceiling);
	}//end allows()
}//end class
