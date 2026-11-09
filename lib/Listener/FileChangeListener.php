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

use OCA\OpenRegister\BackgroundJob\JobHandles;
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
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
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
		$file = self::changedFile(event: $event);
		if ($file === null || $this->shouldExtract(file: $file) === false) {
			return;
		}

		$this->logDetected(event: $event, file: $file);

		try {
			$this->dispatchByMode(fileId: $file->getId(), fileName: $file->getName());
		} catch (\Exception $e) {
			$this->logModeError(fileId: $file->getId(), error: $e);
		}
	}//end handle()

	/**
	 * Log that the extraction mode could not be determined or applied for a file.
	 *
	 * @param int|null   $fileId The file id
	 * @param \Exception $error  What went wrong
	 *
	 * @return void
	 */
	private function logModeError(?int $fileId, \Exception $error): void {
		$this->logger->error(
			message: '[FileChangeListener] Error determining extraction mode',
			context: self::context(
				line: __LINE__,
				fields: ['file_id' => $fileId, 'error' => $error->getMessage(), 'trace' => $error->getTraceAsString()]
			)
		);
	}//end logModeError()

	/**
	 * The file a created or written event is about; null for folders and any other event.
	 *
	 * @param Event $event File event
	 *
	 * @return File|null
	 */
	private static function changedFile(Event $event): ?File {
		if (($event instanceof NodeCreatedEvent) === false && ($event instanceof NodeWrittenEvent) === false) {
			return null;
		}

		$node = $event->getNode();
		if (($node instanceof File) === false) {
			return null;
		}

		return $node;
	}//end changedFile()

	/**
	 * Whether a file is in the extraction scope and not an anonymized copy.
	 *
	 * The scope is checked first: it loads the extraction settings once per request.
	 *
	 * @param File $file The changed file
	 *
	 * @return bool
	 */
	private function shouldExtract(File $file): bool {
		if ($this->isInExtractionScope(filePath: $file->getPath()) === false) {
			return false;
		}

		// Skip anonymized files.
		return strpos($file->getName(), '_anonymized') === false;
	}//end shouldExtract()

	/**
	 * Log that a file event will be processed.
	 *
	 * @param Event $event File event
	 * @param File  $file  The changed file
	 *
	 * @return void
	 */
	private function logDetected(Event $event, File $file): void {
		$this->logger->debug(
			message: '[FileChangeListener] File event detected - processing',
			context: self::context(
				line: __LINE__,
				fields: [
					'event_type' => get_class($event),
					'file_id' => $file->getId(),
					'file_name' => $file->getName(),
					'file_path' => $file->getPath(),
					'extraction_scope' => self::$cachedExtractScope,
				]
			)
		);
	}//end logDetected()

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
			$this->queueExtractionJob(fileId: $fileId, fileName: $fileName, mode: $extractionMode);
			return;
		}

		if (isset(self::SKIP_MESSAGES[$extractionMode]) === true) {
			$this->logger->debug(message: self::SKIP_MESSAGES[$extractionMode], context: self::context(line: __LINE__, fields: ['file_id' => $fileId]));
			return;
		}

		$this->queueForUnknownMode(fileId: $fileId, mode: $extractionMode);
	}//end dispatchByMode()

	/**
	 * Queue the extraction job for one file, logging the start and the outcome with the mode's messages.
	 *
	 * @param int|null $fileId   The file id
	 * @param string   $fileName The file name
	 * @param string   $mode     A key of QUEUE_MESSAGES
	 *
	 * @return void
	 */
	private function queueExtractionJob(?int $fileId, string $fileName, string $mode): void {
		$this->logger->debug(
			message: self::QUEUE_MESSAGES[$mode]['start'],
			context: self::context(line: __LINE__, fields: ['file_id' => $fileId, 'file_name' => $fileName])
		);

		try {
			$this->jobList->add(job: JobHandles::FILE_TEXT_EXTRACTION, argument: ['file_id' => $fileId]);
			$this->logger->debug(message: self::QUEUE_MESSAGES[$mode]['queued'], context: self::context(line: __LINE__, fields: ['file_id' => $fileId]));
		} catch (\Exception $e) {
			$this->logger->error(
				message: self::QUEUE_MESSAGES[$mode]['failed'],
				context: self::context(line: __LINE__, fields: ['file_id' => $fileId, 'error' => $e->getMessage()])
			);
		}
	}//end queueExtractionJob()

	/**
	 * Warn about an unknown extraction mode and queue the job as background mode would.
	 *
	 * @param int|null $fileId The file id
	 * @param string   $mode   The unknown mode
	 *
	 * @return void
	 */
	private function queueForUnknownMode(?int $fileId, string $mode): void {
		$this->logger->warning(
			message: '[FileChangeListener] Unknown extraction mode, defaulting to background',
			context: self::context(line: __LINE__, fields: ['file_id' => $fileId, 'extraction_mode' => $mode])
		);
		$this->jobList->add(job: JobHandles::FILE_TEXT_EXTRACTION, argument: ['file_id' => $fileId]);
	}//end queueForUnknownMode()

	/**
	 * A log context: this file, the logging line, then the given fields.
	 *
	 * @param int                  $line   The line that logs
	 * @param array<string, mixed> $fields The fields that follow
	 *
	 * @return array<string, mixed>
	 */
	private static function context(int $line, array $fields): array {
		return ['file' => __FILE__, 'line' => $line] + $fields;
	}//end context()
}//end class
