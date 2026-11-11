<?php

/**
 * The durable queue, the dispatcher that flushes it, and the logger.
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

use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use Psr\Log\LoggerInterface;

/**
 * The durable queue, the dispatcher that flushes it, and the logger.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class QueueFlushDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param QueuedNotificationMapper $queuedMapper Durable queue mapper.
	 * @param AnnotationNotificationDispatcher $dispatcher Notification dispatcher (flush entry point).
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		public QueuedNotificationMapper $queuedMapper,
		public AnnotationNotificationDispatcher $dispatcher,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
