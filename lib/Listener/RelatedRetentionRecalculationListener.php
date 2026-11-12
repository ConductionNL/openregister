<?php

/**
 * OpenRegister RelatedRetentionRecalculationListener
 *
 * After a record is saved, queues the recalculation of the records whose
 * destruction date reads a date off it, when that date changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndexInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the dependants' recalculation off the save.
 *
 * @template-implements IEventListener<ObjectUpdatedEvent>
 */
class RelatedRetentionRecalculationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface            $schemaMapper Resolves the saved record's schema
	 * @param DependantRetentionIndexInterface $index        Knows which schemas read a date off it
	 * @param IJobList                $jobList      The background job queue
	 * @param LoggerInterface         $logger       The logger
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly DependantRetentionIndexInterface $index,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue one job per changed source record, when a watched date changed.
	 *
	 * The job is keyed on the source uuid alone, so a burst of saves on one
	 * record queues it once.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$uuid = $event->getNewObject()->getUuid();
		if ($uuid === null || (string)$event->getNewObject()->getSchema() === '') {
			return;
		}

		try {
			$this->queueWhenWatchedChanged(event: $event, uuid: $uuid);
		} catch (Throwable $failure) {
			// The save itself already succeeded; a queue that cannot be reached
			// must not turn it into an error.
			$this->logger->warning('[RelatedRetentionRecalculationListener] Could not queue the dependants of ' . $uuid . ': ' . $failure->getMessage());
		}//end try
	}//end handle()

	/**
	 * Queue the recalculation when the update changed a property dependants' retention is derived from.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 * @param string             $uuid  The updated object's uuid.
	 *
	 * @return void
	 */
	private function queueWhenWatchedChanged(ObjectUpdatedEvent $event, string $uuid): void {
		$schema = $this->schemaMapper->find(id: (string)$event->getNewObject()->getSchema(), _rbac: false, _multitenancy: false);
		$watched = $this->index->watchedPropertiesOf(source: $schema);
		if ($watched === [] || self::changedOf(watched: $watched, event: $event) === []) {
			return;
		}

		$argument = ['uuid' => $uuid];
		if ($this->jobList->has(JobHandles::RELATED_RETENTION_RECALCULATION, $argument) === true) {
			return;
		}

		$this->jobList->add(JobHandles::RELATED_RETENTION_RECALCULATION, $argument);
	}//end queueWhenWatchedChanged()

	/**
	 * The watched properties whose value the update changed.
	 *
	 * @param list<string>       $watched The properties dependants' retention is derived from.
	 * @param ObjectUpdatedEvent $event   The update.
	 *
	 * @return array<int, string> The changed ones.
	 */
	private static function changedOf(array $watched, ObjectUpdatedEvent $event): array {
		$newData = ($event->getNewObject()->getObject() ?? []);
		$oldData = ($event->getOldObject()?->getObject() ?? []);
		return array_filter(
			$watched,
			static fn (string $property): bool => ($oldData[$property] ?? null) !== ($newData[$property] ?? null)
		);
	}//end changedOf()
}//end class
