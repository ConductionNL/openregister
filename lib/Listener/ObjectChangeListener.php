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

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
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

		$this->processExtractionMode(mode: $extractionMode, objectId: $objectId, objectUuid: $object->getUuid());
	}//end extract()

	/**
	 * Process extraction based on configured mode
	 *
	 * Cron and manual mode skip the object; an unknown mode falls back to background.
	 *
	 * @param string $mode Extraction mode (immediate, background, cron, manual).
	 * @param int $objectId Object ID to process.
	 * @param string $objectUuid Object UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processExtractionMode(string $mode, int $objectId, string $objectUuid): void {
		if (isset(self::SKIP_MESSAGES[$mode]) === true) {
			$this->logger->debug(message: self::SKIP_MESSAGES[$mode], context: self::context(line: __LINE__, fields: ['object_id' => $objectId]));
			return;
		}

		if ($mode === 'immediate') {
			$this->processImmediateExtraction(objectId: $objectId, objectUuid: $objectUuid);
			return;
		}

		if ($mode === 'background') {
			$this->processBackgroundExtraction(objectId: $objectId, objectUuid: $objectUuid);
			return;
		}

		$this->processUnknownMode(mode: $mode, objectId: $objectId);
	}//end processExtractionMode()

	/**
	 * Process immediate synchronous extraction
	 *
	 * @param int $objectId Object ID to extract.
	 * @param string $objectUuid Object UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processImmediateExtraction(int $objectId, string $objectUuid): void {
		$this->logger->info(
			message: '[ObjectChangeListener] Immediate mode - processing synchronously',
			context: self::context(line: __LINE__, fields: ['object_id' => $objectId, 'object_uuid' => $objectUuid])
		);

		try {
			$this->textExtractSvc->extractObject(objectId: $objectId, forceReExtract: false);
			$this->logger->info(
				message: '[ObjectChangeListener] Immediate extraction completed',
				context: self::context(line: __LINE__, fields: ['object_id' => $objectId])
			);
		} catch (\Exception $e) {
			$this->logFailure(message: '[ObjectChangeListener] Immediate extraction failed', objectId: $objectId, error: $e);
		}
	}//end processImmediateExtraction()

	/**
	 * Queue background job for extraction
	 *
	 * @param int $objectId Object ID to extract.
	 * @param string $objectUuid Object UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processBackgroundExtraction(int $objectId, string $objectUuid): void {
		$this->logger->info(
			message: '[ObjectChangeListener] Background mode - queueing extraction job',
			context: self::context(line: __LINE__, fields: ['object_id' => $objectId, 'object_uuid' => $objectUuid])
		);

		try {
			$this->jobList->add(job: ObjectTextExtractionJob::class, argument: ['object_id' => $objectId]);
			$this->logger->debug(
				message: '[ObjectChangeListener] Background extraction job queued',
				context: self::context(line: __LINE__, fields: ['object_id' => $objectId])
			);
		} catch (\Exception $e) {
			$this->logFailure(message: '[ObjectChangeListener] Failed to queue background job', objectId: $objectId, error: $e);
		}
	}//end processBackgroundExtraction()

	/**
	 * Log a failed extraction or queueing of one object.
	 *
	 * @param string     $message  The log message.
	 * @param int        $objectId The object.
	 * @param \Exception $error    What went wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function logFailure(string $message, int $objectId, \Exception $error): void {
		$this->logger->error(
			message: $message,
			context: self::context(line: __LINE__, fields: ['object_id' => $objectId, 'error' => $error->getMessage()])
		);
	}//end logFailure()

	/**
	 * Handle unknown extraction mode (fallback to background)
	 *
	 * @param string $mode Unknown mode name.
	 * @param int $objectId Object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processUnknownMode(string $mode, int $objectId): void {
		$this->logger->warning(
			message: '[ObjectChangeListener] Unknown extraction mode, defaulting to background',
			context: self::context(line: __LINE__, fields: ['object_id' => $objectId, 'extraction_mode' => $mode])
		);

		$this->jobList->add(job: ObjectTextExtractionJob::class, argument: ['object_id' => $objectId]);
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
