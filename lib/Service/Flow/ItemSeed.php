<?php

/**
 * What a run's per-place item buffers are seeded from: the workflow and subject
 * holding the marking, the seed items, and the per-place items the last commit
 * stored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Symfony\Component\Workflow\Workflow;

/**
 * The inputs of seeding a run's per-place items.
 *
 * @spec openspec/specs/flow-merge/spec.md
 */
final class ItemSeed {
	/**
	 * Describe the seed.
	 *
	 * @param Workflow   $workflow The workflow.
	 * @param object     $subject  The subject holding the marking.
	 * @param array      $items    The seed items.
	 * @param array|null $stored   The per-place items persisted by the last commit, or null when none.
	 *
	 * @spec openspec/specs/flow-merge/spec.md
	 */
	public function __construct(
		public readonly Workflow $workflow,
		public readonly object $subject,
		public readonly array $items,
		public readonly ?array $stored = null,
	) {
	}//end __construct()
}//end class
