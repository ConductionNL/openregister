<?php

/**
 * OpenRegister Object Change Listener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
 * In background mode the objects are deferred in chunks (ObjectTextExtractionChunkJob),
 * never one job per object (openregister#4241).
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

use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionChunkJob;
use OCA\OpenRegister\BackgroundJob\ObjectTextExtractionJob;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * ObjectChangeListener
 *
 * Listens for object creation and update events to queue asynchronous text extraction.
 * Instead of processing objects synchronously (which would block user requests),
 * this listener hands each object to ListenerDeferralService, which collects the
 * objects of one process (single saves, a flow writing item by item, a bulk save
 * with events) and queues one ObjectTextExtractionChunkJob per chunk. The chunk
 * size is the app config `textExtractionChunkSize` (default 100). With the
 * deferral kill switch `listenerDeferral` set to `inline` the listener queues one
 * ObjectTextExtractionJob per object, as before.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 * @author   OpenRegister Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent>
 */
class ObjectChangeListener implements IEventListener {
	/**
	 * App-config key of the maximum number of objects per deferred extraction job.
	 *
	 * @var string
	 */
	public const CHUNK_SIZE_KEY = 'textExtractionChunkSize';

	/**
	 * Default number of objects per deferred extraction job.
	 *
	 * @var integer
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

	/**
	 * Constructor
	 *
	 * @param TextExtractionService $textExtractSvc Text extraction service
	 * @param SettingsService $settingsService Settings service
	 * @param IJobList $jobList Job list for queuing background jobs
	 * @param LoggerInterface $logger Logger
	 * @param ListenerDeferralService $deferral Buffers object ids into chunk jobs
	 * @param IAppConfig $appConfig App config holding the chunk size
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly TextExtractionService $textExtractSvc,
		private readonly SettingsService $settingsService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly ListenerDeferralService $deferral,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

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
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		$object = $event->getObject();
		$objectId = $object->getId();

		$this->logger->debug(
			message: '[ObjectChangeListener] Object event detected',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'event_type' => get_class($event),
				'object_id' => $objectId,
				'object_uuid' => $object->getUuid(),
			]
		);

		// Get extraction mode and process accordingly.
		try {
			$fileSettings = $this->settingsService->getFileSettingsOnly();
			$extractionMode = $fileSettings['extractionMode'] ?? 'background';

			// Skip extraction if no object ID (e.g., magic mapper objects use UUID only).
			if ($objectId === null) {
				$this->logger->debug(
					message: '[ObjectChangeListener] Skipping extraction for object without ID (magic mapper?)',
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				return;
			}

			$this->processExtractionMode(mode: $extractionMode, objectId: $objectId, objectUuid: $object->getUuid());
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ObjectChangeListener] Error determining extraction mode',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'object_id' => $objectId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}//end try
	}//end handle()

	/**
	 * Process extraction based on configured mode
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
		switch ($mode) {
			case 'immediate':
				$this->processImmediateExtraction(objectId: $objectId, objectUuid: $objectUuid);
				break;

			case 'background':
				$this->processBackgroundExtraction(objectId: $objectId, objectUuid: $objectUuid);
				break;

			case 'cron':
				$this->processCronMode(objectId: $objectId);
				break;

			case 'manual':
				$this->processManualMode(objectId: $objectId);
				break;

			default:
				$this->processUnknownMode(mode: $mode, objectId: $objectId);
				break;
		}//end switch
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
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_id' => $objectId,
				'object_uuid' => $objectUuid,
			]
		);

		try {
			$this->textExtractSvc->extractObject(objectId: $objectId, forceReExtract: false);
			$this->logger->info(
				message: '[ObjectChangeListener] Immediate extraction completed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $objectId]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ObjectChangeListener] Immediate extraction failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'object_id' => $objectId,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end processImmediateExtraction()

	/**
	 * Queue the object for background extraction.
	 *
	 * Deferral on (the default): the object id goes into the deferral buffer
	 * and reaches the job list in a chunk job of at most the configured chunk
	 * size. Kill switch `listenerDeferral=inline`: one ObjectTextExtractionJob
	 * for this object, the behaviour before chunking.
	 *
	 * @param int $objectId Object ID to extract.
	 * @param string $objectUuid Object UUID for logging.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
	 */
	private function processBackgroundExtraction(int $objectId, string $objectUuid): void {
		$this->logger->info(
			message: '[ObjectChangeListener] Background mode - queueing extraction job',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_id' => $objectId,
				'object_uuid' => $objectUuid,
			]
		);

		try {
			$this->enqueue(objectId: $objectId);
			$this->logger->debug(
				message: '[ObjectChangeListener] Background extraction job queued',
				context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $objectId]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ObjectChangeListener] Failed to queue background job',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'object_id' => $objectId,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end processBackgroundExtraction()

	/**
	 * Hand the object to the deferral buffer, or under the kill switch queue
	 * one ObjectTextExtractionJob for it.
	 *
	 * The entry is the object id only, so a chunk of 100 stays under the job
	 * argument limit of Nextcloud 32 and 33; the id is also the dedupe key, so
	 * an object saved twice in one process is extracted once.
	 *
	 * @param int $objectId Object ID to extract.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
	 */
	private function enqueue(int $objectId): void {
		if ($this->deferral->isDeferralEnabled() === true) {
			$this->deferral->defer(
				jobClass: ObjectTextExtractionChunkJob::class,
				entry: ['id' => $objectId],
				chunkSize: $this->chunkSize(),
				dedupeKey: (string)$objectId
			);
			return;
		}

		$this->jobList->add(job: ObjectTextExtractionJob::class, argument: ['object_id' => $objectId]);
	}//end enqueue()

	/**
	 * The maximum number of objects per deferred extraction job: the app config
	 * `textExtractionChunkSize`, the default when it is unset or below 1.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-object-text-extraction-in-background-mode-is-deferred-in-chunks
	 */
	private function chunkSize(): int {
		try {
			$size = $this->appConfig->getValueInt('openregister', self::CHUNK_SIZE_KEY, self::DEFAULT_CHUNK_SIZE);
		} catch (\Exception $e) {
			// A type conflict (the key stored as another type) is an Exception.
			return self::DEFAULT_CHUNK_SIZE;
		}

		if ($size < 1) {
			return self::DEFAULT_CHUNK_SIZE;
		}

		return $size;
	}//end chunkSize()

	/**
	 * Handle cron mode (skip processing)
	 *
	 * @param int $objectId Object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processCronMode(int $objectId): void {
		$this->logger->debug(
			message: '[ObjectChangeListener] Cron mode - skipping, will be processed by scheduled job',
			context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $objectId]
		);
	}//end processCronMode()

	/**
	 * Handle manual mode (skip processing)
	 *
	 * @param int $objectId Object ID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function processManualMode(int $objectId): void {
		$this->logger->debug(
			message: '[ObjectChangeListener] Manual mode - skipping, requires manual trigger',
			context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $objectId]
		);
	}//end processManualMode()

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
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'object_id' => $objectId,
				'extraction_mode' => $mode,
			]
		);

		$this->enqueue(objectId: $objectId);
	}//end processUnknownMode()
}//end class
