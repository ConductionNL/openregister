<?php

/**
 * OpenRegister Flow Run Frame
 *
 * The step a write is attributed to while a flow node runs.
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
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The executing run, the node being dispatched and the step's sequence number.
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */
final class FlowRunFrame {

	/**
	 * Constructor.
	 *
	 * @param string|null $runUuid  The uuid of the executing run, or null when the caller has no run.
	 * @param string      $nodeId   The id of the node being dispatched.
	 * @param integer     $sequence The step's sequence number within the run.
	 */
	public function __construct(
		public readonly ?string $runUuid,
		public readonly string $nodeId,
		public readonly int $sequence,
	) {
	}//end __construct()
}//end class
