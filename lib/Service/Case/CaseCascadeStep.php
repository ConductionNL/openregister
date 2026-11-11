<?php

/**
 * One transition the case-plan cascade decided on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;

/**
 * The item, target state, cause, cause reference, actor and reason of one
 * cascade transition; the last four arrive together as a CaseStepCause. Each named constructor is one rule of the cascade.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final class CaseCascadeStep {

	/**
	 * Constructor.
	 *
	 * @param CaseItem      $item The row that moves.
	 * @param string        $to   The target state.
	 * @param CaseStepCause $why  The cause, cause reference, actor (null for the system) and reason.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function __construct(
		public readonly CaseItem $item,
		public readonly string $to,
		public readonly CaseStepCause $why,
	) {
	}//end __construct()

	/**
	 * A realisation that ended drives its item: completed -> completed,
	 * anything else -> terminated, cause `realisation`, ref the realisation.
	 *
	 * @param CaseItem $item    The realised row.
	 * @param string   $outcome How the realisation ended.
	 *
	 * @return self The step.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public static function realisationEnded(CaseItem $item, string $outcome): self {
		$kind = (string)$item->getRealisationKind();
		$ref = $item->getRealisationUuid();
		return new self(
			item: $item,
			to: $outcome,
			why: new CaseStepCause(
				cause: CaseItemAudit::CAUSE_REALISATION,
				causeRef: $ref,
				reason: sprintf("Realisation %s '%s' ended as '%s'.", $kind, (string)$ref, $outcome)
			)
		);
	}//end realisationEnded()

	/**
	 * An exit sentry terminates the item.
	 *
	 * @param CaseItem $item   The row.
	 * @param string   $sentry The firing sentry's id.
	 *
	 * @return self The step.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public static function exitFired(CaseItem $item, string $sentry): self {
		return new self(
			item: $item,
			to: CaseItem::STATE_TERMINATED,
			why: new CaseStepCause(
				cause: CaseItemAudit::CAUSE_SENTRY,
				causeRef: $sentry,
				reason: sprintf("Exit criterion '%s' fired.", $sentry)
			)
		);
	}//end exitFired()

	/**
	 * An entry sentry admits the item: a milestone completes at once, a work
	 * item becomes active.
	 *
	 * @param CaseItem $item   The row.
	 * @param string   $sentry The admitting sentry's id.
	 *
	 * @return self The step.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public static function entryFired(CaseItem $item, string $sentry): self {
		$target = CaseItem::STATE_ACTIVE;
		if ($item->getPlanItemType() === CaseItem::TYPE_MILESTONE) {
			$target = CaseItem::STATE_COMPLETED;
		}

		return new self(item: $item, to: $target, why: new CaseStepCause(cause: CaseItemAudit::CAUSE_SENTRY, causeRef: $sentry));
	}//end entryFired()

	/**
	 * An enabled item under an active parent starts.
	 *
	 * @param CaseItem    $item  The row.
	 * @param string|null $actor The identity that enabled it, when known.
	 *
	 * @return self The step.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public static function enabledStarts(CaseItem $item, ?string $actor): self {
		return new self(
			item: $item,
			to: CaseItem::STATE_ACTIVE,
			why: new CaseStepCause(cause: CaseItemAudit::CAUSE_USER, causeRef: 'enable', actor: $actor)
		);
	}//end enabledStarts()

	/**
	 * A stage whose completion rule holds completes.
	 *
	 * @param CaseItem $stage The stage.
	 *
	 * @return self The step.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public static function stageCompletes(CaseItem $stage): self {
		return new self(
			item: $stage,
			to: CaseItem::STATE_COMPLETED,
			why: new CaseStepCause(
				cause: CaseItemAudit::CAUSE_CASCADE,
				causeRef: 'children',
				reason: 'Every required child is terminal and no child is active.'
			)
		);
	}//end stageCompletes()
}//end class
