<?php

/**
 * How the task sweep fires, deduplicates, remembers its last run and reports.
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
 * How the task sweep fires, deduplicates, remembers its last run and reports.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-deadline-notifications-filter-on-the-derived-predicate
 */
final readonly class TaskNotificationDelivery {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationDedupeStateMapper $dedupe Per-(rule, task) fire state.
	 * @param AnnotationNotificationDispatcher $dispatcher The one dispatcher.
	 * @param IAppConfig $appConfig Last-fire timestamps per rule.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		public NotificationDedupeStateMapper $dedupe,
		public AnnotationNotificationDispatcher $dispatcher,
		public IAppConfig $appConfig,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
