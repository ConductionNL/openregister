<?php

/**
 * OpenRegister webhook dispatch services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatterInterface;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCacheInterface;
use OCP\BackgroundJob\IJobList;

/**
 * How a webhook is dispatched: the job list for async delivery, the
 * CloudEvent formatter and the interception-flag cache (both optional).
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhookDispatchServices {
	/**
	 * Bundle the services.
	 *
	 * @param IJobList                               $jobList             Background job list for async delivery
	 * @param CloudEventFormatterInterface|null      $cloudEventFormatter CloudEvent formatter (optional)
	 * @param WebhookInterceptionCacheInterface|null $interceptionCache   Interception-flag cache (optional)
	 */
	public function __construct(
		public readonly IJobList $jobList,
		public readonly ?CloudEventFormatterInterface $cloudEventFormatter = null,
		public readonly ?WebhookInterceptionCacheInterface $interceptionCache = null,
	) {
	}//end __construct()
}//end class
