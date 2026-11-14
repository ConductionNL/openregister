<?php

/**
 * OpenRegister's own metrics provider: every provider-backed gauge at once.
 *
 * The observability engine resolves ONE IMetricsProvider per app and runs it
 * for each `provider` descriptor in the manifest. OpenRegister has more than
 * one gauge the declarative kinds cannot express, so this class joins them
 * under that one alias, in a fixed order, and the manifest keeps a single
 * `provider` descriptor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;

/**
 * Joins the task and webhook gauges under OpenRegister's provider alias.
 *
 * @spec openspec/changes/production-observability
 */
final class OpenRegisterMetricsProvider implements IMetricsProvider {

	/**
	 * Constructor.
	 *
	 * @param TaskMetricsProviderInterface $tasks    The overdue-task gauge.
	 * @param WebhookRetryMetricsProvider  $webhooks The webhook retry queue gauge.
	 */
	public function __construct(
		private readonly TaskMetricsProviderInterface $tasks,
		private readonly WebhookRetryMetricsProvider $webhooks,
	) {
	}//end __construct()

	/**
	 * The samples of every joined provider, tasks first.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function metrics(): array {
		return [...$this->tasks->metrics(), ...$this->webhooks->metrics()];
	}//end metrics()
}//end class
