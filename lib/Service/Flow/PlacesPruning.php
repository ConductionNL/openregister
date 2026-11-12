<?php

/**
 * OpenRegister Places Pruning
 *
 * The places one routing decision withdrew tokens from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The place names a pruning cleared, and the transition whose exit decision did it.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */
final class PlacesPruning {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $places       The place names the pruning cleared.
	 * @param string             $byTransition The transition whose exit decision did it.
	 */
	public function __construct(
		public readonly array $places,
		public readonly string $byTransition,
	) {
	}//end __construct()
}//end class
