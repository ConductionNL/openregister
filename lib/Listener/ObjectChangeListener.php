<?php

/**
 * OpenRegister Object Change Listener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * ObjectChangeListener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
 * Instead of processing objects synchronously (which would block user requests),
 * this listener queues a background job for each object that needs processing.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 * @author   OpenRegister Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
class ObjectChangeListener implements IEventListener {

	/**
	 * Log messages of the extraction modes that leave the object to another trigger.
	 *
	 * @var array<string, string>
	 */
	private const SKIP_MESSAGES = [
		'cron'   => '[ObjectChangeListener] Cron mode - skipping, will be processed by scheduled job',
		'manual' => '[ObjectChangeListener] Manual mode - skipping, requires manual trigger',
	];

	/**
	 * Log messages of a failed extraction or queueing, by the mode that failed.
	 *
	 * @var array<string, string>
	 */
	private const FAILURE_MESSAGES = [
		'immediate'  => '[ObjectChangeListener] Immediate extraction failed',
		'background' => '[ObjectChangeListener] Failed to queue background job',
	];

	/**
	 * Constructor
	 *
	 * @param TextExtractionServiceInterface $textExtractSvc Text extraction service
	 * @param AppSettingsInterface $settingsService Settings service
	 * @param IJobList $jobList Job list for queuing background jobs
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly TextExtractionServiceInterface $textExtractSvc,
		private readonly AppSettingsInterface $settingsService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log that an object event reached the listener.
	 *
	 * @param Event        $event  The object event.
	 * @param ObjectEntity $object The created or updated object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function logDetected(Event $event, ObjectEntity $object): void {
		$this->logger->debug(
			message: '[ObjectChangeListener] Object event detected',
			context: self::context(
				line: __LINE__,
				fields: ['event_type' => get_class($event), 'object_id' => $object->getId(), 'object_uuid' => $object->getUuid()]
			)
		);
	}//end logDetected()

	/**
	 * Handle object events
	 *
	 * @param Event $event Object event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		// Only handle ObjectCreatedEvent and ObjectUpdatedEvent.
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$this->logDetected(event: $event, object: $object);

		try {
			$this->extract(object: $object);
		} catch (\Exception $e) {
			$this->logModeError(object: $object, error: $e);
		}
	}//end handle()

	/**
	 * Log that the extraction mode could not be determined or applied for an object.
	 *
	 * @param ObjectEntity $object The created or updated object.
	 * @param \Exception   $error  What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function logModeError(ObjectEntity $object, \Exception $error): void {
		$this->logger->error(
			message: '[ObjectChangeListener] Error determining extraction mode',
			context: self::context(
				line: __LINE__,
				fields: ['object_id' => $object->getId(), 'error' => $error->getMessage(), 'trace' => $error->getTraceAsString()]
			)
		);
	}//end logModeError()

	/**
	 * Read the extraction mode and process the object accordingly.
	 *
	 * The settings are read first, so an object without an id (magic mapper
	 * objects use the UUID only) still costs one read before it is skipped.
	 *
	 * @param ObjectEntity $object The created or updated object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function extract(ObjectEntity $object): void {
		$extractionMode = ($this->settingsService->getFileSettingsOnly()['extractionMode'] ?? 'background');
		$objectId = $object->getId();
		if ($objectId === null) {
			$this->logger->debug(
				message: '[ObjectChangeListener] Skipping extraction for object without ID (magic mapper?)',
				context: self::context(line: __LINE__, fields: [])
			);
			return;
		}

		$run = new ObjectExtractionRun();
		$run->mode = $extractionMode;
		$run->objectId = $objectId;
		$run->objectUuid = $object->getUuid();
		$this->processExtractionMode(run: $run);
	}//end extract()

	/**
	 * Process extraction based on configured mode
	 *
	 * Cron and manual mode skip the object; an unknown mode falls back to background.
	 *
	 * @param ObjectExtractionRun $run The mode, object ID and object UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processExtractionMode(ObjectExtractionRun $run): void {
		if (isset(self::SKIP_MESSAGES[$run->mode]) === true) {
			$this->logger->debug(message: self::SKIP_MESSAGES[$run->mode], context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId]));
			return;
		}

		if ($run->mode === 'immediate') {
			$this->processImmediateExtraction(run: $run);
			return;
		}

		if ($run->mode === 'background') {
			$this->processBackgroundExtraction(run: $run);
			return;
		}

		$this->processUnknownMode(run: $run);
	}//end processExtractionMode()

	/**
	 * Process immediate synchronous extraction
	 *
	 * @param ObjectExtractionRun $run The object ID to extract and its UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processImmediateExtraction(ObjectExtractionRun $run): void {
		$this->logger->info(
			message: '[ObjectChangeListener] Immediate mode - processing synchronously',
			context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId, 'object_uuid' => $run->objectUuid])
		);

		try {
			$this->textExtractSvc->extractObject(objectId: $run->objectId, forceReExtract: false);
			$this->logger->info(
				message: '[ObjectChangeListener] Immediate extraction completed',
				context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId])
			);
		} catch (\Exception $e) {
			$this->logFailure(run: $run, error: $e);
		}
	}//end processImmediateExtraction()

	/**
	 * Queue background job for extraction
	 *
	 * @param ObjectExtractionRun $run The object ID to extract and its UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processBackgroundExtraction(ObjectExtractionRun $run): void {
		$this->logger->info(
			message: '[ObjectChangeListener] Background mode - queueing extraction job',
			context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId, 'object_uuid' => $run->objectUuid])
		);

		try {
			$this->jobList->add(job: JobHandles::OBJECT_TEXT_EXTRACTION, argument: ['object_id' => $run->objectId]);
			$this->logger->debug(
				message: '[ObjectChangeListener] Background extraction job queued',
				context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId])
			);
		} catch (\Exception $e) {
			$this->logFailure(run: $run, error: $e);
		}
	}//end processBackgroundExtraction()

	/**
	 * Log a failed extraction or queueing of one object, in the words of its mode.
	 *
	 * @param ObjectExtractionRun $run   The mode that failed and the object.
	 * @param \Exception          $error What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function logFailure(ObjectExtractionRun $run, \Exception $error): void {
		$this->logger->error(
			message: self::FAILURE_MESSAGES[$run->mode],
			context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId, 'error' => $error->getMessage()])
		);
	}//end logFailure()

	/**
	 * Handle unknown extraction mode (fallback to background)
	 *
	 * @param ObjectExtractionRun $run The unknown mode and the object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processUnknownMode(ObjectExtractionRun $run): void {
		$this->logger->warning(
			message: '[ObjectChangeListener] Unknown extraction mode, defaulting to background',
			context: self::context(line: __LINE__, fields: ['object_id' => $run->objectId, 'extraction_mode' => $run->mode])
		);

		$this->jobList->add(job: JobHandles::OBJECT_TEXT_EXTRACTION, argument: ['object_id' => $run->objectId]);
	}//end processUnknownMode()

	/**
	 * A log context: this file, the logging line, then the given fields.
	 *
	 * @param int                  $line   The line that logs.
	 * @param array<string, mixed> $fields The fields that follow.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private static function context(int $line, array $fields): array {
		return ['file' => __FILE__, 'line' => $line] + $fields;
	}//end context()
}//end class
