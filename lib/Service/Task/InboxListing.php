<?php

/**
 * What one inbox listing shares across its rows.
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTimeInterface;

/**
 * The subject contexts, the moment and the open blockers one inbox listing
 * reads once and every row of it shares.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
 */
final class InboxListing {
	/**
	 * The listing.
	 *
	 * @param array<string, mixed> $subjects Subject contexts by object uuid.
	 * @param DateTimeInterface $now The moment the due fields are derived at.
	 * @param array<int, string> $openBlockers The uuids of the open blockers.
	 */
	public function __construct(
		public readonly array $subjects,
		public readonly DateTimeInterface $now,
		public readonly array $openBlockers,
	) {
	}//end __construct()
}//end class
