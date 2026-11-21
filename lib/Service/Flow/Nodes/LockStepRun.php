<?php

/**
 * One firing of a lock step: what it received, how it is configured, the run it belongs to and its resume slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;

/**
 * One firing of {@see LockObjectNode}.
 *
 * A run object (ADR-011 Rule 3): built by `execute()` once the configuration
 * is valid and the resume slot is present, read by the node's private helpers,
 * and never handed outside the node.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */
final class LockStepRun {

	/**
	 * Name the firing.
	 *
	 * @param array<int, mixed>    $items   The incoming items.
	 * @param array<string, mixed> $config  The step configuration.
	 * @param array<string, mixed> $context The run context.
	 * @param FlowNodeResumeState  $resume  This node's resume slot.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
		public readonly array $context,
		public readonly FlowNodeResumeState $resume,
	) {
	}//end __construct()
}//end class
