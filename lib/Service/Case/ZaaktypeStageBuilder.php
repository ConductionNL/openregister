<?php

/**
 * OpenRegister ZaaktypeStageBuilder
 *
 * One zaaktype status as a case-plan stage: a placeholder human item and the
 * milestone, chained after the previous stage's milestone.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * Builds the stage node a {@see ZaaktypeCaseSkeletonMapper} produces for each status.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */
final class ZaaktypeStageBuilder {

	/**
	 * The next status as a stage holding a placeholder human item and the milestone.
	 *
	 * @param array<string, mixed>  $status The statustype.
	 * @param ZaaktypeSkeletonDraft $draft  The skeleton being built.
	 *
	 * @return array<string, mixed> The stage node.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	public function stageFor(array $status, ZaaktypeSkeletonDraft $draft): array {
		$number = count($draft->items) + 1;
		$names  = $this->stageNames(label: (string)($status['omschrijving'] ?? ('status ' . $number)), number: $number);

		return [
			'key' => $names['stage'],
			'type' => CaseItem::TYPE_STAGE,
			'name' => $names['label'],
			'required' => true,
			'entryCriteria' => self::stageEntry(names: $names, draft: $draft),
			'children' => [self::taskFor(names: $names, draft: $draft), self::milestoneFor(names: $names)],
		];
	}//end stageFor()

	/**
	 * The label and the stage, task and milestone keys of one status.
	 *
	 * @param string $label  The status label.
	 * @param int    $number Its position in the sequence, from 1.
	 *
	 * @return array{label: string, stage: string, task: string, milestone: string} The names.
	 */
	private function stageNames(string $label, int $number): array {
		$slug = $this->slug(text: $label);
		return [
			'label' => $label,
			'stage' => sprintf('fase-%d-%s', $number, $slug),
			'task' => sprintf('behandel-%d-%s', $number, $slug),
			'milestone' => sprintf('status-%d-%s', $number, $slug),
		];
	}//end stageNames()

	/**
	 * The stage's entry criteria: waiting for the previous milestone, or none for the first stage.
	 *
	 * @param array{label: string, stage: string, task: string, milestone: string} $names The names.
	 * @param ZaaktypeSkeletonDraft                                                $draft The skeleton being built.
	 *
	 * @return array<int, array<string, mixed>> The entry criteria.
	 */
	private static function stageEntry(array $names, ZaaktypeSkeletonDraft $draft): array {
		$previous = $draft->lastMilestoneKey();
		if ($previous === null) {
			return [];
		}

		return [['id' => $names['stage'] . ':entry', 'on' => ['event' => 'case.item.completed', 'item' => $previous]]];
	}//end stageEntry()

	/**
	 * The placeholder human item of a stage.
	 *
	 * @param array{label: string, stage: string, task: string, milestone: string} $names The names.
	 * @param ZaaktypeSkeletonDraft                                                $draft The skeleton being built.
	 *
	 * @return array<string, mixed> The human item node.
	 */
	private static function taskFor(array $names, ZaaktypeSkeletonDraft $draft): array {
		$task = [
			'key' => $names['task'],
			'type' => CaseItem::TYPE_HUMAN_TASK,
			'name' => sprintf('Behandelen: %s', $names['label']),
			'required' => true,
			'doorlooptijd' => $draft->terms['doorlooptijd'],
			'servicenorm' => $draft->terms['servicenorm'],
		];
		if ($draft->behandelaar !== null) {
			$task['candidateRole'] = $draft->behandelaar;
		}

		return $task;
	}//end taskFor()

	/**
	 * The milestone of a stage, entered when the stage's human item completes.
	 *
	 * @param array{label: string, stage: string, task: string, milestone: string} $names The names.
	 *
	 * @return array<string, mixed> The milestone node.
	 */
	private static function milestoneFor(array $names): array {
		return [
			'key' => $names['milestone'],
			'type' => CaseItem::TYPE_MILESTONE,
			'name' => $names['label'],
			'required' => true,
			'entryCriteria' => [
				['id' => $names['milestone'] . ':entry', 'on' => ['event' => 'case.item.completed', 'item' => $names['task']]],
			],
		];
	}//end milestoneFor()

	/**
	 * A key-safe slug of a label.
	 *
	 * @param string $text The label.
	 *
	 * @return string Lower-case letters, digits and dashes.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function slug(string $text): string {
		$slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
		if ($slug === '') {
			return 'status';
		}

		return substr($slug, 0, 60);
	}//end slug()
}//end class
