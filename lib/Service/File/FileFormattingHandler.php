<?php

/**
 * FileFormattingHandler
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

use DateTime;
use Exception;
use OCA\OpenRegister\Files\Db\File as OrFile;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Handles file formatting and filtering operations.
 *
 * This handler is responsible for:
 * - Formatting single files to metadata arrays
 * - Formatting multiple files with pagination ({@see FileListFilter})
 * - Showing the lock of a file to authenticated callers only ({@see FileLockEnvelope})
 * - Managing file metadata (labels, tags, shares)
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileFormattingHandler {
	/**
	 * The publication fields of a file that was never published; an OR-side
	 * row overwrites them.
	 *
	 * @var array{published: null, depublished: null, isPublished: false}
	 */
	private const UNPUBLISHED = ['published' => null, 'depublished' => null, 'isPublished' => false];

	/**
	 * Reference to FileService for cross-handler coordination (circular dependency break).
	 *
	 * @var FileHandlerSupportInterface|null
	 */
	private ?FileHandlerSupportInterface $fileService = null;

	/**
	 * Builds the lock envelope shown to authenticated callers.
	 *
	 * @var FileLockEnvelope
	 */
	private readonly FileLockEnvelope $lockEnvelope;

	/**
	 * Filters and pages a formatted listing.
	 *
	 * @var FileListFilter
	 */
	private readonly FileListFilter $listFilter;

	/**
	 * Session used to gate lock fields for authenticated callers only.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger for logging operations.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for FileFormattingHandler.
	 *
	 * @param ILockManager             $lockManager Lock manager for reading file lock state.
	 * @param FileSession              $session     The session that gates lock fields for authenticated callers only, and the logger.
	 * @param FileMapperInterface|null $fileMapper  Optional OR-side metadata mapper for description / category /
	 *                                              labels / downloadCount enrichment. When null (test fixtures,
	 *                                              legacy callers), enrichment is skipped silently.
	 */
	public function __construct(
		ILockManager $lockManager,
		FileSession $session,
		private readonly ?FileMapperInterface $fileMapper = null,
	) {
		$this->userSession = $session->userSession;
		$this->logger = $session->logger;
		$this->lockEnvelope = new FileLockEnvelope(lockManager: $lockManager, logger: $session->logger);
		$this->listFilter = new FileListFilter();
	}//end __construct()

	/**
	 * Set the FileService instance for cross-handler coordination.
	 *
	 * @param FileHandlerSupportInterface $fileService The file service instance.
	 *
	 * @return void
	 */
	public function setFileService(FileHandlerSupportInterface $fileService): void {
		$this->fileService = $fileService;
	}//end setFileService()

	/**
	 * Format a single file Node into a metadata array.
	 *
	 * This method converts a Nextcloud file node into a standardized metadata array
	 * including file properties, shares, tags, and download links. Labels containing
	 * ':' are processed as key-value pairs and extracted into separate metadata fields.
	 *
	 * Anonymous callers MUST NOT see who holds a lock (design.md Decision 6).
	 *
	 * @param Node $file The file node to format.
	 *
	 * @psalm-return   array{labels: list<string>,...}
	 * @phpstan-return array<string, mixed>
	 *
	 * @return (float|int|null|string[])[]
	 *
	 * @throws Exception If formatting fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function formatFile(Node $file): array {
		$metadata = $this->baseMetadata(file: $file);
		if ($this->userSession->getUser() !== null) {
			$metadata = $this->withLock(fileId: (int)$file->getId(), metadata: $metadata);
		}

		if ($this->fileMapper !== null) {
			$metadata = $this->withOrMetadata(file: $file, metadata: $metadata);
		}

		return $this->withLabelFields(metadata: $metadata);
	}//end formatFile()

	/**
	 * Format multiple files with filtering, sorting, and pagination.
	 *
	 * Formats the file nodes, keeps those matching the request's filters and
	 * returns the requested page with its paging figures.
	 *
	 * @param Node[] $files         Array of Node files to format.
	 * @param array  $requestParams Optional request parameters for filtering.
	 *
	 * @psalm-param array<int, Node> $files
	 * @psalm-param array<string, mixed> $requestParams
	 *
	 * @phpstan-param array<int, Node> $files
	 * @phpstan-param array<string, mixed> $requestParams
	 *
	 * @return (array[]|int)[]
	 *
	 * @psalm-return   array{results: list<array<string, mixed>>,
	 *     total: int<0, max>, page: int<1, max>, pages: int,
	 *     limit: int<1, max>, offset: int<0, max>}
	 * @phpstan-return array{results: array<int, array<string, mixed>>,
	 *     total: int, page: int, pages: int, limit: int, offset: int}
	 *
	 * @throws InvalidPathException If any file path is invalid.
	 * @throws NotFoundException If files are not found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function formatFiles(array $files, ?array $requestParams = []): array {
		$requestParams = ($requestParams ?? []);
		$formattedFiles = $this->formatEach(files: $files);
		$filters = $this->listFilter->extract(requestParams: $requestParams);

		return $this->listFilter->page(
			files: $this->listFilter->apply(files: $formattedFiles, filters: $filters),
			requestParams: $requestParams
		);
	}//end formatFiles()

	/**
	 * Format every file. A single NC-locked file must not break the whole
	 * listing: its LockedException becomes a minimal stub entry so the caller
	 * still sees it (design.md Decision 3).
	 *
	 * @param array<int, Node> $files The file nodes.
	 *
	 * @return list<array<string, mixed>> The formatted files and stubs.
	 */
	private function formatEach(array $files): array {
		$isAuthenticated = $this->userSession->getUser() !== null;
		$formattedFiles = [];
		foreach ($files as $file) {
			$formattedFiles[] = $this->formatOrStub(file: $file, isAuthenticated: $isAuthenticated);
		}

		return $formattedFiles;
	}//end formatEach()

	/**
	 * One file formatted, or its stub when it raised LockedException.
	 *
	 * @param Node $file            The file node.
	 * @param bool $isAuthenticated Whether the request has a user session.
	 *
	 * @return array<string, mixed> The formatted file or its stub.
	 */
	private function formatOrStub(Node $file, bool $isAuthenticated): array {
		try {
			return $this->formatFile(file: $file);
		} catch (LockedException $lockedException) {
			$stub = $this->buildLockedStubEntry(file: $file, isAuthenticated: $isAuthenticated);
			$this->logLockedStub(stub: $stub, reason: $lockedException->getMessage());
			return $stub;
		}
	}//end formatOrStub()

	/**
	 * The metadata every formatted file carries: node properties, share links
	 * and tags.
	 *
	 * `published` is the publication window, filled from the OR-side row and
	 * null when the file was never published; the creation time is `created`.
	 *
	 * @param Node $file The file node.
	 *
	 * @return array<string, mixed> The base metadata.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function baseMetadata(Node $file): array {
		$identity = ['id' => $file->getId(), 'path' => $file->getPath(), 'title' => $file->getName()];

		return $identity + $this->shareUrls(file: $file) + $this->nodeProperties(file: $file) + self::UNPUBLISHED + [
			'created' => (new DateTime())->setTimestamp($file->getCreationTime())->format('c'),
			'modified' => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
			'labels' => $this->fileService->getFileTags((string)$file->getId()),
		];
	}//end baseMetadata()

	/**
	 * The access and download URL of the file's first share, or nulls when it
	 * is not shared.
	 *
	 * IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
	 *
	 * @param Node $file The file node.
	 *
	 * @return array{accessUrl: ?string, downloadUrl: ?string}
	 */
	private function shareUrls(Node $file): array {
		$shares = $this->fileService->findShares($file);
		if (count($shares) === 0) {
			return ['accessUrl' => null, 'downloadUrl' => null];
		}

		$accessUrl = $this->fileService->getShareLink($shares[0]);

		return ['accessUrl' => $accessUrl, 'downloadUrl' => $accessUrl . '/download'];
	}//end shareUrls()

	/**
	 * The node's type, extension, size and hash.
	 *
	 * @param Node $file The file node.
	 *
	 * @return array<string, mixed>
	 */
	private function nodeProperties(Node $file): array {
		return [
			'type' => $file->getMimetype(),
			'extension' => $file->getExtension(),
			'size' => $file->getSize(),
			'hash' => $file->getEtag(),
		];
	}//end nodeProperties()

	/**
	 * Add the `locked` flag and, when there is one, the lock envelope.
	 *
	 * Only called for authenticated callers.
	 *
	 * @param int                  $fileId   The file id.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array<string, mixed> The metadata with the lock fields.
	 */
	private function withLock(int $fileId, array $metadata): array {
		$lockEnvelope = $this->lockEnvelope->forFile(fileId: $fileId);
		$metadata['locked'] = $lockEnvelope !== null;
		if ($lockEnvelope !== null) {
			$metadata['lock'] = $lockEnvelope;
		}

		return $metadata;
	}//end withLock()

	/**
	 * Add the OR-side row's metadata, when a row exists for the file. A failed
	 * lookup is logged and leaves the metadata as it was.
	 *
	 * @param Node                 $file     The file node.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array<string, mixed> The metadata, enriched from the row.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function withOrMetadata(Node $file, array $metadata): array {
		try {
			$orFile = $this->fileMapper?->findByFileId(fileId: (int)$file->getId());
			if ($orFile !== null) {
				$metadata = $this->applyOrFile(orFile: $orFile, metadata: $metadata);
			}
		} catch (\Throwable $e) {
			$this->logOrLookupFailure(fileId: (int)$file->getId(), error: $e);
		}

		return $metadata;
	}//end withOrMetadata()

	/**
	 * Log a failed OR-side metadata lookup.
	 *
	 * @param int        $fileId The file id.
	 * @param \Throwable $error  The failure.
	 *
	 * @return void
	 */
	private function logOrLookupFailure(int $fileId, \Throwable $error): void {
		$this->logger->warning(
			message: '[FileFormattingHandler] OR-side metadata lookup failed; continuing without enrichment',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $error->getMessage()]
		);
	}//end logOrLookupFailure()

	/**
	 * Copy an OR-side row onto the metadata.
	 *
	 * Description, category, OR-managed labels and the publication window are
	 * public-safe. The download count and the OR-side lock, which operators can
	 * persist beside the NC lock state, are for authenticated callers only.
	 *
	 * @param OrFile               $orFile   The OR-side row.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array<string, mixed> The metadata with the row's fields.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function applyOrFile(OrFile $orFile, array $metadata): array {
		$metadata = $this->withPublicOrFields(orFile: $orFile, metadata: $metadata);
		if ($this->userSession->getUser() === null) {
			return $metadata;
		}

		return $this->withAuthenticatedOrFields(orFile: $orFile, metadata: $metadata);
	}//end applyOrFile()

	/**
	 * The row's public-safe fields: description, category, publication window,
	 * and the OR-managed labels joined to the tag-backed labels, de-duplicated.
	 *
	 * @param OrFile               $orFile   The OR-side row.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array<string, mixed> The metadata with the public fields.
	 */
	private function withPublicOrFields(OrFile $orFile, array $metadata): array {
		$metadata['description'] = $orFile->getDescription();
		$metadata['category'] = $orFile->getCategory();
		$metadata['published'] = $orFile->getPublished()?->format('c');
		$metadata['depublished'] = $orFile->getDepublished()?->format('c');
		$metadata['isPublished'] = $orFile->isPublishedAt();

		$orLabels = ($orFile->getLabels() ?? []);
		if (empty($orLabels) === false) {
			$metadata['labels'] = array_values(array_unique(array_merge($metadata['labels'], $orLabels)));
		}

		return $metadata;
	}//end withPublicOrFields()

	/**
	 * The row's fields for authenticated callers: the download count and the
	 * OR-side lock.
	 *
	 * @param OrFile               $orFile   The OR-side row.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array<string, mixed> The metadata with those fields.
	 */
	private function withAuthenticatedOrFields(OrFile $orFile, array $metadata): array {
		$metadata['downloadCount'] = $orFile->getDownloadCount();
		if ($orFile->getLockedBy() !== null) {
			$metadata['orLock'] = [
				'lockedBy' => $orFile->getLockedBy(),
				'lockedAt' => $orFile->getLockedAt()?->format('c'),
				'lockExpires' => $orFile->getLockExpires()?->format('c'),
			];
		}

		return $metadata;
	}//end withAuthenticatedOrFields()

	/**
	 * Turn `key:value` labels into metadata fields.
	 *
	 * A label whose key is not yet set (or set to null) becomes that field and
	 * leaves the labels; a label without `:`, or whose key is already set,
	 * stays a label.
	 *
	 * @param array<string, mixed> $metadata The metadata, with its `labels`.
	 *
	 * @return array<string, mixed> The metadata with the label fields.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function withLabelFields(array $metadata): array {
		$remainingLabels = [];
		foreach ($metadata['labels'] as $label) {
			$field = $this->labelField(label: $label, metadata: $metadata);
			if ($field === null) {
				$remainingLabels[] = $label;
				continue;
			}

			$metadata[$field['key']] = $field['value'];
		}

		$metadata['labels'] = $remainingLabels;

		return $metadata;
	}//end withLabelFields()

	/**
	 * The field a `key:value` label becomes, or null when it stays a label.
	 *
	 * @param string               $label    The label.
	 * @param array<string, mixed> $metadata The metadata so far.
	 *
	 * @return array{key: string, value: string}|null The field.
	 */
	private function labelField(string $label, array $metadata): ?array {
		if (strpos($label, ':') === false) {
			return null;
		}

		$parts = explode(':', $label, 2);
		$key = trim($parts[0]);
		if (isset($metadata[$key]) === true) {
			return null;
		}

		return ['key' => $key, 'value' => trim($parts[1])];
	}//end labelField()

	/**
	 * Build a minimal stub entry for a file that raised `LockedException`
	 * during formatting.
	 *
	 * For authenticated callers the stub carries the standard `locked`/`lock`
	 * fields populated with best-effort lock metadata; for anonymous callers
	 * it omits both fields entirely so the authentication gate is honoured
	 * even on the error path. See design.md Decisions 3 and 6.
	 *
	 * @param Node $file            The file node that failed to format.
	 * @param bool $isAuthenticated Whether the request has a user session.
	 *
	 * @return array<string, mixed>
	 */
	private function buildLockedStubEntry(Node $file, bool $isAuthenticated): array {
		$stub = ['id' => (int)$file->getId(), 'title' => $file->getName(), 'error' => 'locked'];
		if ($isAuthenticated === false) {
			return $stub;
		}

		return $this->withStubLock(stub: $stub, lock: $this->lockEnvelope->forFile(fileId: (int)$file->getId()));
	}//end buildLockedStubEntry()

	/**
	 * The stub's `locked` flag and, when one was found, its lock envelope.
	 *
	 * Only called for authenticated callers.
	 *
	 * @param array<string, mixed>      $stub The stub.
	 * @param array<string, mixed>|null $lock The best-effort lock envelope.
	 *
	 * @return array<string, mixed> The stub with its lock fields.
	 */
	private function withStubLock(array $stub, ?array $lock): array {
		$stub['locked'] = true;
		if ($lock !== null) {
			$stub['lock'] = $lock;
		}

		return $stub;
	}//end withStubLock()

	/**
	 * Log that a locked file is listed as a stub.
	 *
	 * @param array<string, mixed> $stub   The stub entry, with its best-effort lock envelope when one was found.
	 * @param string               $reason The LockedException message.
	 *
	 * @return void
	 */
	private function logLockedStub(array $stub, string $reason): void {
		$fileId = $stub['id'];
		$name = $stub['title'];
		$lock = ($stub['lock'] ?? null);
		$this->logger->info(
			message: "[FileFormattingHandler] formatFiles: file {$name} (ID: {$fileId}) is locked, emitting stub entry",
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister',
				'fileId' => $fileId, 'name' => $name,
				'lockType' => $lock['type'] ?? null,
				'lockOwner' => $lock['owner'] ?? null,
				'reason' => $reason,
			]
		);
	}//end logLockedStub()
}//end class
