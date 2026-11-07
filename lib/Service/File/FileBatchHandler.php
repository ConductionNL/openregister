<?php

/**
 * FileBatchHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;

/**
 * Handles batch file operations.
 *
 * Provides a single endpoint for performing publish, depublish, delete, and label
 * operations on multiple files at once, replacing N sequential HTTP calls.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileBatchHandler {

	/**
	 * Maximum number of files per batch request.
	 *
	 * @var int
	 */
	private const MAX_BATCH_SIZE = 100;

	/**
	 * Allowed batch actions.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_ACTIONS = ['publish', 'depublish', 'delete', 'label'];

	/**
	 * Reference to FileService for cross-handler coordination.
	 *
	 * @var FileService|null
	 */
	private ?FileService $fileService = null;

	/**
	 * Constructor for FileBatchHandler.
	 *
	 * @param FilePublishingHandler $publishingHandler Publishing handler for publish/depublish.
	 * @param DeleteFileHandler $deleteHandler Delete handler for file deletion.
	 * @param TaggingHandlerInterface $taggingHandler Tagging handler for label operations.
	 * @param LoggerInterface $logger Logger for logging operations.
	 */
	public function __construct(
		private readonly FilePublishingHandler $publishingHandler,
		private readonly DeleteFileHandler $deleteHandler,
		private readonly TaggingHandlerInterface $taggingHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Set the FileService instance for cross-handler coordination.
	 *
	 * @param FileService $fileService The file service instance.
	 *
	 * @return void
	 */
	public function setFileService(FileService $fileService): void {
		$this->fileService = $fileService;
	}//end setFileService()

	/**
	 * Execute a batch operation on multiple files.
	 *
	 * @param ObjectEntity $object The object entity owning the files.
	 * @param string $action The batch action (publish|depublish|delete|label).
	 * @param array<int> $fileIds Array of file IDs to operate on.
	 * @param array $params Additional parameters (e.g., labels for label action).
	 *
	 * @return array{results: array, summary: array{total: int, succeeded: int, failed: int}} Batch results.
	 *
	 * @throws Exception If validation fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function executeBatch(
		ObjectEntity $object,
		string $action,
		array $fileIds,
		array $params = [],
	): array {
		$this->assertValidBatch(action: $action, fileIds: $fileIds);
		$request = new FileBatchRequest(object: $object, action: $action, params: $params);

		$results = [];
		foreach ($fileIds as $fileId) {
			$results[] = $this->executeOne(request: $request, fileId: $fileId);
		}

		return ['results' => $results, 'summary' => $this->summary(results: $results)];
	}//end executeBatch()

	/**
	 * Refuse an unknown action, an oversized batch or an empty one.
	 *
	 * @param string     $action  The batch action.
	 * @param array<int> $fileIds The file IDs.
	 *
	 * @return void
	 *
	 * @throws Exception If validation fails.
	 */
	private function assertValidBatch(string $action, array $fileIds): void {
		if (in_array($action, self::ALLOWED_ACTIONS, true) === false) {
			throw new Exception('Invalid batch action. Allowed: ' . implode(', ', self::ALLOWED_ACTIONS));
		}

		if (count($fileIds) > self::MAX_BATCH_SIZE) {
			throw new Exception('Batch operations are limited to ' . self::MAX_BATCH_SIZE . ' files per request');
		}

		if (empty($fileIds) === true) {
			throw new Exception('No file IDs provided');
		}
	}//end assertValidBatch()

	/**
	 * Run the action on one file and report the outcome; a failure is logged, not thrown.
	 *
	 * @param FileBatchRequest $request The batch request.
	 * @param mixed            $fileId  The file ID as given.
	 *
	 * @return array{fileId: mixed, success: bool, error?: string}
	 */
	private function executeOne(FileBatchRequest $request, mixed $fileId): array {
		try {
			$this->executeAction(request: $request, fileId: (int)$fileId);
			return ['fileId' => $fileId, 'success' => true];
		} catch (Exception $e) {
			$this->logger->warning(
				message: "[FileBatchHandler] Batch {$request->action} failed for file {$fileId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return ['fileId' => $fileId, 'success' => false, 'error' => $e->getMessage()];
		}
	}//end executeOne()

	/**
	 * Count the outcomes of a batch.
	 *
	 * @param list<array{fileId: mixed, success: bool, error?: string}> $results The per-file outcomes.
	 *
	 * @return array{total: int, succeeded: int, failed: int}
	 */
	private function summary(array $results): array {
		$succeeded = count(array_filter($results, static fn (array $result): bool => $result['success']));

		return [
			'total' => count($results),
			'succeeded' => $succeeded,
			'failed' => (count($results) - $succeeded),
		];
	}//end summary()

	/**
	 * Execute a single batch action on one file.
	 *
	 * The action was validated by assertValidBatch(), so it is one of the four.
	 *
	 * @param FileBatchRequest $request The batch request.
	 * @param int              $fileId  The file ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the action fails.
	 */
	private function executeAction(FileBatchRequest $request, int $fileId): void {
		if ($request->action === 'publish' || $request->action === 'depublish') {
			$this->changePublication(request: $request, fileId: $fileId);
			return;
		}

		if ($request->action === 'delete') {
			$this->deleteOrFail(request: $request, fileId: $fileId);
			return;
		}

		$this->label(request: $request, fileId: $fileId);
	}//end executeAction()

	/**
	 * Set one file's labels to the request's `labels` parameter (none when absent).
	 *
	 * @param FileBatchRequest $request The batch request.
	 * @param int              $fileId  The file ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the FileService is missing or the update fails.
	 */
	private function label(FileBatchRequest $request, int $fileId): void {
		$this->requireFileService()->updateFile(
			filePath: $fileId,
			content: null,
			tags: ($request->params['labels'] ?? []),
			object: $request->object
		);
	}//end label()

	/**
	 * Publish or depublish one file.
	 *
	 * @param FileBatchRequest $request The batch request (action publish or depublish).
	 * @param int              $fileId  The file ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the FileService is missing or the change fails.
	 */
	private function changePublication(FileBatchRequest $request, int $fileId): void {
		$fileService = $this->requireFileService();
		if ($request->action === 'publish') {
			$fileService->publishFile(object: $request->object, file: $fileId);
			return;
		}

		$fileService->unpublishFile(object: $request->object, filePath: $fileId);
	}//end changePublication()

	/**
	 * Delete one file, failing when it is still there.
	 *
	 * FileService answers false for a file that is still there (an operation a
	 * listener aborted, such as the frozen-object guard): that is a failed
	 * delete, not a succeeded one.
	 *
	 * @param FileBatchRequest $request The batch request.
	 * @param int              $fileId  The file ID.
	 *
	 * @return void
	 *
	 * @throws Exception If the FileService is missing or the file was not deleted.
	 */
	private function deleteOrFail(FileBatchRequest $request, int $fileId): void {
		if ($this->requireFileService()->deleteFile(file: $fileId, object: $request->object) === false) {
			throw new Exception('The file could not be deleted');
		}
	}//end deleteOrFail()

	/**
	 * The FileService, or an exception when it was never set.
	 *
	 * @return FileService
	 *
	 * @throws Exception If setFileService() was not called.
	 */
	private function requireFileService(): FileService {
		if ($this->fileService === null) {
			throw new Exception('FileService not initialized in FileBatchHandler');
		}

		return $this->fileService;
	}//end requireFileService()
}//end class
