<?php

/**
 * How the scheduled notification scan fires, deduplicates and reports.
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

use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * How the scheduled notification scan fires, deduplicates and reports.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class ScheduledNotificationDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param AnnotationNotificationDispatcher $dispatcher Notification dispatcher.
	 * @param NotificationDedupeStateMapper $dedupeMapper Per-object dedup state mapper.
	 * @param IAppConfig $appConfig App config for tunable retention window.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		public AnnotationNotificationDispatcher $dispatcher,
		public NotificationDedupeStateMapper $dedupeMapper,
		public IAppConfig $appConfig,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
