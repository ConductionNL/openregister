<?php

/**
 * OpenRegister MergeRun
 *
 * The state one merge execution carries from step to step inside MergeService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\MergeInstruction;

/**
 * One merge in progress: the instruction, the pair, both payloads as they are
 * rewritten, the pre-merge snapshot and what the relink and survivor steps produced.
 *
 * Built and used only inside MergeService.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
 */
final class MergeRun {

	/**
	 * The losing object's payload, rewritten as the merge proceeds.
	 *
	 * @var array<string, mixed>
	 */
	public array $fromData;

	/**
	 * The survivor's payload, rewritten as the merge proceeds.
	 *
	 * @var array<string, mixed>
	 */
	public array $intoData;

	/**
	 * The pre-merge snapshot, plus the reverse-FK moves once relinked.
	 *
	 * @var array<string, mixed>
	 */
	public array $snapshot = [];

	/**
	 * How many source records the relink moved.
	 *
	 * @var integer
	 */
	public int $relinkedCount = 0;

	/**
	 * The resolver overrides a decision map produced, or null when there was none.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $overrides = null;

	/**
	 * The saved survivor, once it is saved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $survivor = null;

	/**
	 * Start a run on a loaded pair.
	 *
	 * @param MergeInstruction $instruction Which object merges into which, why and by whom.
	 * @param MergePair        $pair        Both objects, the survivor's schema and the merge annotation.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
	 */
	public function __construct(
		public readonly MergeInstruction $instruction,
		public readonly MergePair $pair,
	) {
		$this->fromData = ($pair->fromObject->getObject() ?? []);
		$this->intoData = ($pair->intoObject->getObject() ?? []);
	}//end __construct()

	/**
	 * A status setting of the merge annotation, or its default.
	 *
	 * @param string $key     The annotation key (`statusField`, `survivorStatus`, `mergedStatus`).
	 * @param string $default The default when the annotation leaves it out.
	 *
	 * @return string The setting.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.3
	 */
	public function setting(string $key, string $default): string {
		return (string)($this->pair->config[$key] ?? $default);
	}//end setting()
}//end class
