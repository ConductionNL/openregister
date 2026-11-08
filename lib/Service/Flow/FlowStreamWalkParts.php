<?php

/**
 * The persistence collaborators a FlowStreamWalk claims and commits through.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowStreamMapperInterface;

/**
 * The claim protocol, the commit path and the stream rows of one stream walk.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamWalkParts {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowPlaceClaims  $claims       The claim protocol.
	 * @param FlowRunCommit    $commit       The commit path.
	 * @param FlowStreamMapperInterface $streamMapper The stream rows.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly FlowPlaceClaims $claims,
		public readonly FlowRunCommit $commit,
		public readonly FlowStreamMapperInterface $streamMapper,
	) {
	}//end __construct()
}//end class
