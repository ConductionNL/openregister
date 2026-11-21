<?php

/**
 * The rows one flow-run commit writes together.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;

/**
 * The run, stream, claim and step mappers of one commit transaction.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */
final readonly class FlowRunCommitRows {
	/**
	 * Bundle the mappers.
	 *
	 * @param FlowRunMapperInterface     $runs    The run rows (locked for update).
	 * @param FlowStreamMapperInterface  $streams The stream rows.
	 * @param FlowClaimMapperInterface   $claims  The claim rows.
	 * @param FlowRunStepMapperInterface $steps   The step rows.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function __construct(
		public FlowRunMapperInterface $runs,
		public FlowStreamMapperInterface $streams,
		public FlowClaimMapperInterface $claims,
		public FlowRunStepMapperInterface $steps,
	) {
	}//end __construct()
}//end class
