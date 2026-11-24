<?php
/**
 * WebhookLogStatisticsInterface: the webhook delivery counts the dashboard shows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Search;

/**
 * Counts webhook log entries.
 *
 * The dashboard statistics (Search) ask; the webhook log mapper
 * (Integrations) answers and is bound to this contract.
 */
interface WebhookLogStatisticsInterface {

	/**
	 * Get statistics for a webhook
	 *
	 * @param int $webhookId Webhook ID (0 for all webhooks)
	 *
	 * @return int[] Statistics
	 *
	 * @psalm-return array{total: int, successful: int, failed: int}
	 *
	 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
	 */
	public function getStatistics(int $webhookId): array;
}//end interface
