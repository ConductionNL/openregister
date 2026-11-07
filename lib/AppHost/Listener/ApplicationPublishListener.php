<?php

/**
 * OpenRegister AppHost: the publish hook for manifest schedules.
 *
 * Runs in the session of whoever saved, which is the identity a compiled schedule
 * takes. A failure here is logged: it must not fail the publish itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\AppHost\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Listener;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowSync;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReleaseReader;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a manifest's schedules when an object write is a publish.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
class ApplicationPublishListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ScheduleReleaseReader $reader Recognises a publish.
	 * @param ContainerInterface $container Builds the sync only for a publish: this runs on every object write.
	 * @param LoggerInterface $logger Failures.
	 */
	public function __construct(
		private readonly ScheduleReleaseReader $reader,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function handle(Event $event): void {
		try {
			$release = $this->releaseOf(event: $event);
			if ($release !== null) {
				$this->container->get(ScheduleFlowSync::class)->apply(release: $release);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[AppHost\\Scheduling] Could not apply a published manifest: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end handle()

	/**
	 * The publish an event amounts to.
	 *
	 * @param Event $event The event.
	 *
	 * @return ScheduleRelease|null The publish, or null.
	 */
	private function releaseOf(Event $event): ?ScheduleRelease {
		if ($event instanceof ObjectUpdatedEvent) {
			return $this->reader->releaseOf(object: $event->getNewObject(), before: $event->getOldObject());
		}

		if ($event instanceof ObjectCreatedEvent) {
			return $this->reader->releaseOf(object: $event->getObject(), before: null);
		}

		if ($event instanceof ObjectDeletedEvent) {
			return $this->reader->releaseOfDeleted(object: $event->getObject());
		}

		return null;
	}//end releaseOf()
}//end class
