<?php

/**
 * OpenRegister CaseEnsureRun
 *
 * One convergent bring-over in progress: what every inserted row is built
 * from, the history its audits come from, the rows already there, and which
 * keys were created or kept.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * The state of one CasePlanEnsurer::ensure() call; it never leaves the ensurer.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
final class CaseEnsureRun {

	/**
	 * Keys of the items this run created, in order.
	 *
	 * @var array<int, string>
	 */
	public array $created = [];

	/**
	 * Keys of the items this run found and kept, in order.
	 *
	 * @var array<int, string>
	 */
	public array $kept = [];

	/**
	 * Constructor.
	 *
	 * @param CasePlanInsertion       $insertion The anchor, the validated settings and the acting identity every new row is built from.
	 * @param CaseEnsuredHistory      $history   The recorded history, by item key.
	 * @param array<string, CaseItem> $existing  The rows already on the object, by item key.
	 */
	public function __construct(
		public readonly CasePlanInsertion $insertion,
		public readonly CaseEnsuredHistory $history,
		public readonly array $existing,
	) {
	}//end __construct()
}//end class
