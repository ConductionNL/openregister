<?php

/**
 * PortalTaskReminderInterface: queue a portal reminder for a task.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * The reminder message for a task, and the request that queues it for the portal.
 *
 * Bound to PortalTaskDeliveryService in ContractBindings.
 */
interface PortalTaskReminderInterface {

	/**
	 * Record a delivery request on every channel for a task's ask.
	 *
	 * One row per channel, so a mail outage and a portal outage are told
	 * apart. Never throws: each failure is logged with the task, and the
	 * rows that could be written are returned.
	 *
	 * @param TaskInterface $task The external task.
	 * @param string $kind `ask`, `re-ask` or `reminder`.
	 * @param array<string, mixed> $message What portaliq renders.
	 *
	 * @return array<int, PortalTaskDelivery> The rows written (possibly none).
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function request(TaskInterface $task, string $kind, array $message): array;

	/**
	 * The message an ask, re-ask or reminder carries to portaliq.
	 *
	 * Descriptors and the case anchor, never case data: portaliq reads the
	 * case itself through its subject-scoped readers (ADR-046 rule 3).
	 *
	 * @param TaskInterface $task The external task.
	 * @param string|null $reason The re-ask reason, when this is a re-ask.
	 *
	 * @return array<string, mixed> The message.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function messageFor(TaskInterface $task, ?string $reason = null): array;
}//end interface
