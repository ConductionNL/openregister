<?php

/**
 * One position of an approval chain, numbered within the chain.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * One position of an approval chain: its snapshot, its 1-based index and the chain's length.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final readonly class ChainPosition {

	/**
	 * @param array<string, mixed> $snapshot The position as declared (role, order, outcomes).
	 * @param int                  $index    Its 1-based place in the chain.
	 * @param int                  $count    The number of positions in the chain.
	 */
	public function __construct(
		public array $snapshot,
		public int $index,
		public int $count,
	) {
	}//end __construct()

	/**
	 * The position's declared order, else its index.
	 *
	 * @return int The order.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function order(): int {
		return (int)($this->snapshot['order'] ?? $this->index);
	}//end order()
}//end class
