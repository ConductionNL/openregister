<?php

/**
 * OpenRegister File Change Listener
 *
 * Listens for file creation and update events to queue asynchronous text extraction.
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

use OCA\OpenRegister\BackgroundJob\FileTextExtractionJob;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * FileChangeListener
 *
 * Listens for file creation and update events to queue asynchronous text extraction.
 * Instead of processing files synchronously (which would block user requests),
 * this listener queues a background job for each file that needs processing.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 * @author   OpenRegister Team
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent>
 */
class FileChangeListener implements IEventListener {

	/**
	 * Log messages of the extraction modes that queue a job.
	 *
	 * @var array<string, array{start: string, queued: string, failed: string}>
	 */
	private const QUEUE_MESSAGES = [
		'immediate'  => [
			'start'  => '[FileChangeListener] Immediate mode - queueing extraction job',
			'queued' => '[FileChangeListener] Immediate-mode extraction job queued',
			'failed' => '[FileChangeListener] Failed to queue immediate-mode extraction job',
		],
		'background' => [
			'start'  => '[FileChangeListener] Background mode - queueing extraction job',
			'queued' => '[FileChangeListener] Background extraction job queued',
			'failed' => '[FileChangeListener] Failed to queue background job',
		],
	];

	/**
	 * Log messages of the extraction modes that leave the file to another trigger.
	 *
	 * @var array<string, string>
	 */
	private const SKIP_MESSAGES = [
		'cron'   => '[FileChangeListener] Cron mode - skipping, will be processed by scheduled job',
		'manual' => '[FileChangeListener] Manual mode - skipping, requires manual trigger',
	];

	/**
	 * Cached extraction scope to avoid repeated DB reads within the same request.
	 *
	 * @var string|null
	 */
	private static ?string $cachedExtractScope = null;

	/**
	 * Cached extraction mode.
	 *
	 * @var string|null
	 */
	private static ?string $cachedExtractionMode = null;

	/**
	 * Constructor
	 *
	 * @param AppSettingsInterface $settingsService Settings service
	 * @param IJobList $jobList Job list for queuing background jobs
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly AppSettingsInterface $settingsService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle file events
	 *
	 * @param Event $event File event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function handle(Event $event): void {
		// Only handle NodeCreatedEvent and NodeWrittenEvent.
		if (($event instanceof NodeCreatedEvent) === false
			&& ($event instanceof NodeWrittenEvent) === false
		) {
			return;
		}

		$node = $event->getNode();

		// Only process files, not folders.
		if (($node instanceof File) === false) {
			return;
		}

		$filePath = $node->getPath();
		if ($this->isInExtractionScope(filePath: $filePath) === false) {
			return;
		}

		$fileId = $node->getId();
		$fileName = $node->getName();

		// Skip anonymized files.
		if (strpos($fileName, '_anonymized') !== false) {
			return;
		}

		$this->logger->debug(
			message: '[FileChangeListener] File event detected - processing',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'event_type' => get_class($event),
				'file_id' => $fileId,
				'file_name' => $fileName,
				'file_path' => $filePath,
				'extraction_scope' => self::$cachedExtractScope,
			]
		);

		try {
			$this->dispatchByMode(fileId: $fileId, fileName: $fileName);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[FileChangeListener] Error determining extraction mode',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'file_id' => $fileId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);
		}
	}//end handle()

	/**
	 * Whether the extraction scope covers a file path.
	 *
	 * @param string $filePath The file path
	 *
	 * @return bool
	 */
	private function isInExtractionScope(string $filePath): bool {
		// Checked before the settings load so most files (e.g. skeleton files) cost no DB read.
		$isOpenRegisterFile = strpos($filePath, 'OpenRegister/files') !== false
			|| strpos($filePath, '/Open Registers/') !== false;

		$this->loadExtractionSettings();
		$extractionScope = self::$cachedExtractScope;

		if ($extractionScope === 'none') {
			return false;
		}

		return $extractionScope !== 'objects' || $isOpenRegisterFile === true;
	}//end isInExtractionScope()

	/**
	 * Load the extraction scope and mode once per request.
	 *
	 * @return void
	 */
	private function loadExtractionSettings(): void {
		if (self::$cachedExtractScope !== null) {
			return;
		}

		try {
			$fileSettings = $this->settingsService->getFileSettingsOnly();
			self::$cachedExtractScope = $fileSettings['extractionScope'] ?? 'objects';
			self::$cachedExtractionMode = $fileSettings['extractionMode'] ?? 'background';
		} catch (\Exception $e) {
			self::$cachedExtractScope = 'objects';
			self::$cachedExtractionMode = 'background';
		}
	}//end loadExtractionSettings()

	/**
	 * Queue, skip or default the extraction of one file according to the cached extraction mode.
	 *
	 * Immediate mode queues a job too, so a file write never blocks on extraction.
	 *
	 * @param int|null $fileId   The file id
	 * @param string   $fileName The file name
	 *
	 * @return void
	 */
	private function dispatchByMode(?int $fileId, string $fileName): void {
		$extractionMode = self::$cachedExtractionMode ?? 'background';

		if (isset(self::QUEUE_MESSAGES[$extractionMode]) === true) {
			$this->logger->debug(
				message: self::QUEUE_MESSAGES[$extractionMode]['start'],
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'file_id' => $fileId,
					'file_name' => $fileName,
				]
			);
			$this->queueExtractionJob(fileId: $fileId, mode: $extractionMode);
			return;
		}

		if (isset(self::SKIP_MESSAGES[$extractionMode]) === true) {
			$this->logger->debug(
				message: self::SKIP_MESSAGES[$extractionMode],
				context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
			);
			return;
		}

		$this->logger->warning(
			message: '[FileChangeListener] Unknown extraction mode, defaulting to background',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'file_id' => $fileId,
				'extraction_mode' => $extractionMode,
			]
		);
		$this->jobList->add(job: FileTextExtractionJob::class, argument: ['file_id' => $fileId]);
	}//end dispatchByMode()

	/**
	 * Queue the extraction job for one file, logging the outcome with the mode's messages.
	 *
	 * @param int|null $fileId The file id
	 * @param string   $mode   A key of QUEUE_MESSAGES
	 *
	 * @return void
	 */
	private function queueExtractionJob(?int $fileId, string $mode): void {
		try {
			$this->jobList->add(job: FileTextExtractionJob::class, argument: ['file_id' => $fileId]);
			$this->logger->debug(
				message: self::QUEUE_MESSAGES[$mode]['queued'],
				context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: self::QUEUE_MESSAGES[$mode]['failed'],
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'file_id' => $fileId,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end queueExtractionJob()
}//end class
