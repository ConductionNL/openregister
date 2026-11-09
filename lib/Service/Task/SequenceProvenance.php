<?php

/**
 * The flow run and node an approval sequence was started from.
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
 * The flow run and node an approval sequence was started from (optional provenance).
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final readonly class SequenceProvenance {

	/**
	 * @param string|null $runUuid The flow run.
	 * @param string|null $nodeId  The node within it.
	 */
	public function __construct(
		public ?string $runUuid = null,
		public ?string $nodeId = null,
	) {
	}//end __construct()
}//end class
