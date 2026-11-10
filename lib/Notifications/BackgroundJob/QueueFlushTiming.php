<?php

/**
 * When a queued notification is due: its rule's digest config, the delivery window and the digest schedule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;

/**
 * When a queued notification is due: its rule's digest config, the delivery window and the digest schedule.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class QueueFlushTiming {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper (rule digest config).
	 * @param NotificationDeliveryWindowService $windowService Delivery-window resolver + evaluator.
	 * @param DigestScheduleEvaluator $digestEvaluator Live digest-schedule evaluator.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public NotificationDeliveryWindowService $windowService,
		public DigestScheduleEvaluator $digestEvaluator,
	) {
	}//end __construct()
}//end class
