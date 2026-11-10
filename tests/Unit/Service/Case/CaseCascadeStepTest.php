<?php

/**
 * Pins the six fields each named constructor of CaseCascadeStep produces.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Service\Case\CaseCascadeStep;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of the cascade yields the same item, target, cause, cause
 * reference, actor and reason.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseCascadeStep
 * @covers \OCA\OpenRegister\Service\Case\CaseStepCause
 * @uses \OCA\OpenRegister\Db\CaseItem
 */
final class CaseCascadeStepTest extends TestCase {

	/**
	 * A plan-item row of the given type.
	 *
	 * @param string $type The plan-item type.
	 *
	 * @return CaseItem The row.
	 */
	private static function row(string $type): CaseItem {
		$row = new CaseItem();
		$row->setPlanItemType($type);
		return $row;
	}//end row()

	/**
	 * The five fields of a step beside its item, by name.
	 *
	 * @param CaseCascadeStep $step The step.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private static function fields(CaseCascadeStep $step): array {
		return [
			'to' => $step->to,
			'cause' => $step->why->cause,
			'causeRef' => $step->why->causeRef,
			'actor' => $step->why->actor,
			'reason' => $step->why->reason,
		];
	}//end fields()

	/**
	 * An ended realisation drives the item to the outcome, naming the realisation.
	 *
	 * @return void
	 */
	public function testRealisationEnded(): void {
		$row = self::row(type: CaseItem::TYPE_HUMAN_TASK);
		$row->setRealisationKind(CaseItem::REALISATION_TASK);
		$row->setRealisationUuid('task-1');

		$step = CaseCascadeStep::realisationEnded(item: $row, outcome: CaseItem::STATE_COMPLETED);

		$this->assertSame($row, $step->item);
		$this->assertSame(
			[
				'to' => CaseItem::STATE_COMPLETED,
				'cause' => CaseItemAudit::CAUSE_REALISATION,
				'causeRef' => 'task-1',
				'actor' => null,
				'reason' => "Realisation task 'task-1' ended as 'completed'.",
			],
			self::fields(step: $step)
		);
	}//end testRealisationEnded()

	/**
	 * An exit sentry terminates, naming the sentry.
	 *
	 * @return void
	 */
	public function testExitFired(): void {
		$row  = self::row(type: CaseItem::TYPE_HUMAN_TASK);
		$step = CaseCascadeStep::exitFired(item: $row, sentry: 'withdrawn');

		$this->assertSame($row, $step->item);
		$this->assertSame(
			[
				'to' => CaseItem::STATE_TERMINATED,
				'cause' => CaseItemAudit::CAUSE_SENTRY,
				'causeRef' => 'withdrawn',
				'actor' => null,
				'reason' => "Exit criterion 'withdrawn' fired.",
			],
			self::fields(step: $step)
		);
	}//end testExitFired()

	/**
	 * An entry sentry activates a work item and completes a milestone.
	 *
	 * @return void
	 */
	public function testEntryFired(): void {
		$work      = CaseCascadeStep::entryFired(item: self::row(type: CaseItem::TYPE_HUMAN_TASK), sentry: 'go');
		$milestone = CaseCascadeStep::entryFired(item: self::row(type: CaseItem::TYPE_MILESTONE), sentry: 'reached');

		$this->assertSame(
			['to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'go', 'actor' => null, 'reason' => null],
			self::fields(step: $work)
		);
		$this->assertSame(
			['to' => CaseItem::STATE_COMPLETED, 'cause' => CaseItemAudit::CAUSE_SENTRY, 'causeRef' => 'reached', 'actor' => null, 'reason' => null],
			self::fields(step: $milestone)
		);
	}//end testEntryFired()

	/**
	 * An enabled item starts as a user transition, carrying the actor.
	 *
	 * @return void
	 */
	public function testEnabledStarts(): void {
		$withActor    = CaseCascadeStep::enabledStarts(item: self::row(type: CaseItem::TYPE_HUMAN_TASK), actor: 'alice');
		$withoutActor = CaseCascadeStep::enabledStarts(item: self::row(type: CaseItem::TYPE_HUMAN_TASK), actor: null);

		$this->assertSame(
			['to' => CaseItem::STATE_ACTIVE, 'cause' => CaseItemAudit::CAUSE_USER, 'causeRef' => 'enable', 'actor' => 'alice', 'reason' => null],
			self::fields(step: $withActor)
		);
		$this->assertNull($withoutActor->why->actor);
	}//end testEnabledStarts()

	/**
	 * A stage completes by the cascade rule.
	 *
	 * @return void
	 */
	public function testStageCompletes(): void {
		$stage = self::row(type: CaseItem::TYPE_STAGE);
		$step  = CaseCascadeStep::stageCompletes(stage: $stage);

		$this->assertSame($stage, $step->item);
		$this->assertSame(
			[
				'to' => CaseItem::STATE_COMPLETED,
				'cause' => CaseItemAudit::CAUSE_CASCADE,
				'causeRef' => 'children',
				'actor' => null,
				'reason' => 'Every required child is terminal and no child is active.',
			],
			self::fields(step: $step)
		);
	}//end testStageCompletes()
}//end class
