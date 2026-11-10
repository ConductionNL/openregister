<?php

/**
 * TaskAuditEntry: one fact recorded in a flow task's audit without moving the task.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The task, the audited action, the acting identity and what is recorded.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */
final class TaskAuditEntry {
	/**
	 * Constructor.
	 *
	 * @param string      $uuid   The task.
	 * @param string      $action The audited action name.
	 * @param string|null $actor  The run's acting identity.
	 * @param string      $reason What is being recorded.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $action,
		public readonly ?string $actor,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
