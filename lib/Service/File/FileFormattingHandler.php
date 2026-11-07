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
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;

/**
 * Handles file formatting and filtering operations.
 *
 * This handler is responsible for:
 * - Formatting single files to metadata arrays
 * - Formatting multiple files with pagination
 * - Extracting filter parameters from requests
 * - Applying filters to formatted files
 * - Managing file metadata (labels, tags, shares)
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Handler coordinates formatting, sharing, tagging, locking, URL generation, and session lookup.
 */
class FileFormattingHandler {
	/**
	 * The file filters read from a request, and how each is taken: bool and
	 * int are cast, trim is a trimmed string, and list is a comma-separated
	 * string or an array.
	 */
	private const FILE_FILTER_PARAMETERS = [
		'_hasLabels' => 'bool',
		'_noLabels' => 'bool',
		'labels' => 'list',
		'extension' => 'trim',
		'extensions' => 'list',
		'minSize' => 'int',
		'maxSize' => 'int',
		'title' => 'trim',
	];


	/**
	 * Reference to FileService for cross-handler coordination (circular dependency break).
	 *
	 * @var FileHandlerSupportInterface|null
	 */
	private ?FileHandlerSupportInterface $fileService = null;

	/**
	 * Constructor for FileFormattingHandler.
	 *
	 * @param TaggingHandlerInterface $taggingHandler Tagging handler for tag operations.
	 * @param FileSharingHandler $fileSharingHandler Sharing handler for share operations.
	 * @param IURLGenerator $urlGenerator URL generator for creating URLs.
	 * @param ILockManager $lockManager Lock manager for reading file lock state.
	 * @param IUserSession $userSession Session used to gate lock fields for authenticated callers only.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param \OCA\OpenRegister\Files\Db\FileMapperInterface|null $fileMapper Optional OR-side metadata mapper for
	 *                                                         description / category / labels / downloadCount
	 *                                                         enrichment. When null (test fixtures, legacy
	 *                                                         callers), enrichment is skipped silently.
	 */
	public function __construct(
		private readonly TaggingHandlerInterface $taggingHandler,
		private readonly FileSharingHandler $fileSharingHandler,
		private readonly IURLGenerator $urlGenerator,
		private readonly ILockManager $lockManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?\OCA\OpenRegister\Files\Db\FileMapperInterface $fileMapper = null,
	) {
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

		// Anonymous callers MUST NOT see who holds a lock (design.md Decision 6).
		if ($this->userSession->getUser() !== null) {
			$lockEnvelope = $this->formatLock(fileId: (int)$file->getId());
			$metadata['locked'] = $lockEnvelope !== null;
			if ($lockEnvelope !== null) {
				$metadata['lock'] = $lockEnvelope;
			}
		}

		if ($this->fileMapper !== null) {
			$metadata = $this->withOrMetadata(file: $file, metadata: $metadata);
		}

		return $this->withLabelFields(metadata: $metadata);
	}//end formatFile()

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
		// IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
		$shares = $this->fileService->findShares($file);

		$accessUrl = null;
		$downloadUrl = null;
		if (count($shares) > 0) {
			$accessUrl = $this->fileService->getShareLink($shares[0]);
			$downloadUrl = $accessUrl . '/download';
		}

		return [
			'id' => $file->getId(),
			'path' => $file->getPath(),
			'title' => $file->getName(),
			'accessUrl' => $accessUrl,
			'downloadUrl' => $downloadUrl,
			'type' => $file->getMimetype(),
			'extension' => $file->getExtension(),
			'size' => $file->getSize(),
			'hash' => $file->getEtag(),
			'published' => null,
			'depublished' => null,
			'isPublished' => false,
			'created' => (new DateTime())->setTimestamp($file->getCreationTime())->format('c'),
			'modified' => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
			'labels' => $this->fileService->getFileTags((string)$file->getId()),
		];
	}//end baseMetadata()

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
			$this->logger->warning(
				message: '[FileFormattingHandler] OR-side metadata lookup failed; continuing without enrichment',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => (int)$file->getId(),
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $metadata;
	}//end withOrMetadata()

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
		$metadata['description'] = $orFile->getDescription();
		$metadata['category'] = $orFile->getCategory();
		$metadata['published'] = $orFile->getPublished()?->format('c');
		$metadata['depublished'] = $orFile->getDepublished()?->format('c');
		$metadata['isPublished'] = $orFile->isPublishedAt();

		// OR-managed labels join the tag-backed labels, de-duplicated.
		$orLabels = ($orFile->getLabels() ?? []);
		if (empty($orLabels) === false) {
			$metadata['labels'] = array_values(
				array_unique(array_merge($metadata['labels'], $orLabels))
			);
		}

		if ($this->userSession->getUser() === null) {
			return $metadata;
		}

		$metadata['downloadCount'] = $orFile->getDownloadCount();
		if ($orFile->getLockedBy() !== null) {
			$metadata['orLock'] = [
				'lockedBy' => $orFile->getLockedBy(),
				'lockedAt' => $orFile->getLockedAt()?->format('c'),
				'lockExpires' => $orFile->getLockExpires()?->format('c'),
			];
		}

		return $metadata;
	}//end applyOrFile()

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
			if (strpos($label, ':') === false) {
				$remainingLabels[] = $label;
				continue;
			}

			[$key, $value] = explode(':', $label, 2);
			$key = trim($key);
			if (isset($metadata[$key]) === true) {
				$remainingLabels[] = $label;
				continue;
			}

			$metadata[$key] = trim($value);
		}

		$metadata['labels'] = $remainingLabels;

		return $metadata;
	}//end withLabelFields()

	/**
	 * Format multiple files with filtering, sorting, and pagination.
	 *
	 * This method formats an array of file nodes into standardized metadata arrays,
	 * applies filtering based on request parameters (labels, extensions, size, search),
	 * and returns paginated results with metadata.
	 *
	 * @param Node[] $files Array of Node files to format.
	 * @param array $requestParams Optional request parameters for filtering.
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File formatting with pagination requires multiple branches
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter and pagination paths
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function formatFiles(array $files, ?array $requestParams = []): array {
		// Format all files first. A single NC-locked file must not break the
		// whole listing — catch LockedException per-file and emit a minimal
		// envelope so the caller can still see the entry. See design.md
		// Decision 3.
		$isAuthenticated = $this->userSession->getUser() !== null;
		$formattedFiles = [];
		foreach ($files as $file) {
			try {
				$formattedFiles[] = $this->formatFile(file: $file);
			} catch (LockedException $lockedException) {
				$formattedFiles[] = $this->buildLockedStubEntry(
					file: $file,
					lockedException: $lockedException,
					isAuthenticated: $isAuthenticated
				);
			}
		}

		// Extract and apply filters.
		$filters = $this->extractFilterParameters(requestParams: $requestParams ?? []);
		$formattedFiles = $this->applyFileFilters(formattedFiles: $formattedFiles, filters: $filters);

		// Apply pagination (support both _page/_limit and page/limit conventions).
		// No upper ceiling on `_limit`: per-object attachment counts are the
		// natural bound, and a 100-file cap silently truncated production
		// listings. Floor at 1 keeps pagination arithmetic sound. See
		// design.md Decision 4.
		$page = max(1, (int)($requestParams['_page'] ?? $requestParams['page'] ?? 1));
		$limit = max(1, (int)($requestParams['_limit'] ?? $requestParams['limit'] ?? 30));
		$offset = ($page - 1) * $limit;
		$total = count($formattedFiles);
		$pages = (int)ceil($total / $limit);

		// Slice the results for the current page.
		$results = array_slice($formattedFiles, $offset, $limit);

		return [
			'results' => $results,
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'limit' => $limit,
			'offset' => $offset,
		];
	}//end formatFiles()

	/**
	 * Extract and normalize filter parameters from request.
	 *
	 * This method extracts filter-specific parameters from the request, excluding
	 * pagination and other control parameters. It normalizes string parameters
	 * to arrays where appropriate for consistent filtering logic.
	 *
	 * @param array $requestParams Raw request parameters.
	 *
	 * @return array{
	 *     _hasLabels?: bool,
	 *     _noLabels?: bool,
	 *     labels?: array<string>,
	 *     extension?: string,
	 *     extensions?: array<string>,
	 *     minSize?: int,
	 *     maxSize?: int,
	 *     title?: string,
	 *     search?: string
	 * } Normalized filter parameters.
	 *
	 * @psalm-param   array<string, mixed> $requestParams
	 * @phpstan-param array<string, mixed> $requestParams
	 *
	 */
	private function extractFilterParameters(array $requestParams): array {
		$filters = [];
		foreach (self::FILE_FILTER_PARAMETERS as $key => $kind) {
			$value = ($requestParams[$key] ?? null);
			if ($value === null) {
				continue;
			}

			if ($kind === 'list' && is_string($value) === true) {
				$value = array_map('trim', explode(',', $value));
			}

			if ($kind === 'list' && is_array($value) === false) {
				continue;
			}

			$filters[$key] = match ($kind) {
				'bool' => (bool)$value,
				'int' => (int)$value,
				'trim' => trim($value),
				default => $value,
			};
		}

		$search = ($requestParams['search'] ?? $requestParams['_search'] ?? null);
		if ($search !== null) {
			$filters['search'] = trim($search);
		}

		return $filters;
	}//end extractFilterParameters()

	/**
	 * Apply filters to formatted files.
	 *
	 * This method applies various filters to the formatted file metadata based on
	 * the provided filter parameters. Filters are applied in sequence and files
	 * must match ALL specified criteria to be included in the results.
	 *
	 * Supported filters:
	 * - _hasLabels: Files must have at least one label
	 * - _noLabels: Files must have no labels
	 * - labels: Files must have at least one of the specified labels
	 * - extension: Files must have the exact extension (case-insensitive)
	 * - extensions: Files must have one of the specified extensions
	 * - minSize: Files must be at least this size in bytes
	 * - maxSize: Files must be at most this size in bytes
	 * - title: Files must contain this text in their title (case-insensitive)
	 * - search: Files must contain this text in their title (case-insensitive)
	 *
	 * @param array $formattedFiles Array of formatted file metadata.
	 * @param array $filters Filter parameters to apply.
	 *
	 * @psalm-param   array<int, array<string, mixed>> $formattedFiles
	 * @psalm-param   array<string, mixed> $filters
	 * @phpstan-param array<int, array<string, mixed>> $formattedFiles
	 * @phpstan-param array<string, mixed> $filters
	 *
	 * @return array Filtered array of file metadata.
	 *
	 * @psalm-return   array<int, array<string, mixed>>
	 * @phpstan-return array<int, array<string, mixed>>
	 *
	 */
	private function applyFileFilters(array $formattedFiles, array $filters): array {
		if (empty($filters) === true) {
			return $formattedFiles;
		}

		return array_filter(
			$formattedFiles,
			fn (array $file): bool => $this->fileMatchesLabelFilters(file: $file, filters: $filters) === true
				&& $this->fileMatchesExtensionFilters(file: $file, filters: $filters) === true
				&& $this->fileMatchesSizeFilters(file: $file, filters: $filters) === true
				&& $this->fileMatchesTextFilters(file: $file, filters: $filters) === true
		);
	}//end applyFileFilters()

	/**
	 * Whether a file passes `_hasLabels`, `_noLabels` and `labels` (any one of them).
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function fileMatchesLabelFilters(array $file, array $filters): bool {
		$hasLabels = empty($file['labels']) === false;
		if (($filters['_hasLabels'] ?? null) !== null && $filters['_hasLabels'] !== $hasLabels) {
			return false;
		}

		if (($filters['_noLabels'] ?? null) === true && $hasLabels === true) {
			return false;
		}

		if (empty($filters['labels']) === true) {
			return true;
		}

		return $this->anyLabelPresent(required: $filters['labels'], fileLabels: ($file['labels'] ?? []));
	}//end fileMatchesLabelFilters()

	/**
	 * Whether any required label is among the file's labels, compared strictly.
	 *
	 * @param array $required   The labels of which one must be present.
	 * @param array $fileLabels The file's labels.
	 *
	 * @return bool
	 */
	private function anyLabelPresent(array $required, array $fileLabels): bool {
		foreach ($required as $requiredLabel) {
			if (in_array($requiredLabel, $fileLabels, true) === true) {
				return true;
			}
		}

		return false;
	}//end anyLabelPresent()

	/**
	 * Whether a file passes `extension` and `extensions` (any one of them), ignoring case.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function fileMatchesExtensionFilters(array $file, array $filters): bool {
		$fileExtension = $file['extension'] ?? '';
		if (($filters['extension'] ?? null) !== null && strcasecmp($fileExtension, $filters['extension']) !== 0) {
			return false;
		}

		if (empty($filters['extensions']) === true) {
			return true;
		}

		foreach ($filters['extensions'] as $allowedExtension) {
			if (strcasecmp($fileExtension, $allowedExtension) === 0) {
				return true;
			}
		}

		return false;
	}//end fileMatchesExtensionFilters()

	/**
	 * Whether a file's size lies within `minSize` and `maxSize`, a missing size counting as 0.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function fileMatchesSizeFilters(array $file, array $filters): bool {
		$fileSize = $file['size'] ?? 0;
		if (($filters['minSize'] ?? null) !== null && $fileSize < $filters['minSize']) {
			return false;
		}

		return ($filters['maxSize'] ?? null) === null || $fileSize <= $filters['maxSize'];
	}//end fileMatchesSizeFilters()

	/**
	 * Whether a file's title contains `title` and `search`, ignoring case.
	 *
	 * @param array<string, mixed> $file    The formatted file.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function fileMatchesTextFilters(array $file, array $filters): bool {
		$fileTitle = $file['title'] ?? '';
		foreach (['title', 'search'] as $key) {
			if (empty($filters[$key]) === false && stripos($fileTitle, $filters[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end fileMatchesTextFilters()

	/**
	 * Build the `lock` response envelope for a given file id.
	 *
	 * Returns `null` when no lock provider is registered (the `files_lock` app
	 * is not installed / disabled) or when there are no locks on the file.
	 * Otherwise returns the first lock's public fields mapped to stable
	 * string aliases so the API contract does not leak Nextcloud's numeric
	 * constants to consumers.
	 *
	 * This helper MUST only be invoked for authenticated callers; anonymous
	 * requests never reach `ILockManager`. See design.md Decision 6.
	 *
	 * @param int $fileId The file id to look up locks for.
	 *
	 * @return array{type: string, scope: string, owner: string, createdAt: string, expiresAt: ?string}|null
	 *
	 * @psalm-return   array{type: string, scope: string, owner: string, createdAt: string, expiresAt: ?string}|null
	 * @phpstan-return array{type: string, scope: string, owner: string, createdAt: string, expiresAt: string|null}|null
	 */
	private function formatLock(int $fileId): ?array {
		if ($this->lockManager->isLockProviderAvailable() === false) {
			return null;
		}

		try {
			$locks = $this->lockManager->getLocks(fileId: $fileId);
		} catch (Exception $lockLookupException) {
			// A failure to query the lock provider must not break the listing;
			// treat it as "no lock info available" and move on.
			$this->logger->warning(
				message: "[FileFormattingHandler] formatLock: lock lookup failed for fileId {$fileId}: " . $lockLookupException->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
			);

			return null;
		}

		if (count($locks) === 0) {
			return null;
		}

		$lock = $locks[0];

		$createdAt = $lock->getCreatedAt();
		$timeout = $lock->getTimeout();
		$expiresAt = null;
		if ($timeout > 0) {
			$expiresAt = (new DateTime())->setTimestamp($createdAt + $timeout)->format('c');
		}

		return [
			'type' => $this->mapLockType(type: $lock->getType()),
			'scope' => $this->mapLockScope(scope: $lock->getScope()),
			'owner' => $lock->getOwner(),
			'createdAt' => (new DateTime())->setTimestamp($createdAt)->format('c'),
			'expiresAt' => $expiresAt,
		];
	}//end formatLock()

	/**
	 * Build a minimal stub entry for a file that raised `LockedException`
	 * during formatting.
	 *
	 * For authenticated callers the stub carries the standard `locked`/`lock`
	 * fields populated with best-effort lock metadata; for anonymous callers
	 * it omits both fields entirely so the authentication gate is honoured
	 * even on the error path. See design.md Decisions 3 and 6.
	 *
	 * @param Node $file The file node that failed to format.
	 * @param LockedException $lockedException The raised LockedException.
	 * @param bool $isAuthenticated Whether the request has a user session.
	 *
	 * @return array<string, mixed>
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 */
	private function buildLockedStubEntry(Node $file, LockedException $lockedException, bool $isAuthenticated): array {
		$fileId = (int)$file->getId();
		$name = $file->getName();

		$bestEffortLock = null;
		if ($isAuthenticated === true) {
			$bestEffortLock = $this->formatLock(fileId: $fileId);
		}

		$this->logger->info(
			message: "[FileFormattingHandler] formatFiles: file {$name} (ID: {$fileId}) is locked, emitting stub entry",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'app' => 'openregister',
				'fileId' => $fileId,
				'name' => $name,
				'lockType' => $bestEffortLock['type'] ?? null,
				'lockOwner' => $bestEffortLock['owner'] ?? null,
				'reason' => $lockedException->getMessage(),
			]
		);

		$stub = [
			'id' => $fileId,
			'title' => $name,
			'error' => 'locked',
		];

		if ($isAuthenticated === true) {
			$stub['locked'] = true;
			if ($bestEffortLock !== null) {
				$stub['lock'] = $bestEffortLock;
			}
		}

		return $stub;
	}//end buildLockedStubEntry()

	/**
	 * Map an `ILock::TYPE_*` constant to a stable string alias for the public API.
	 *
	 * @param int $type One of `ILock::TYPE_USER`, `ILock::TYPE_APP`, `ILock::TYPE_TOKEN`.
	 *
	 * @return string The string alias (`"user"`, `"app"`, `"token"`, or `"unknown"`).
	 */
	private function mapLockType(int $type): string {
		return match ($type) {
			ILock::TYPE_USER => 'user',
			ILock::TYPE_APP => 'app',
			ILock::TYPE_TOKEN => 'token',
			default => 'unknown',
		};
	}//end mapLockType()

	/**
	 * Map an `ILock::LOCK_*` scope constant to a stable string alias for the public API.
	 *
	 * @param int $scope One of `ILock::LOCK_EXCLUSIVE`, `ILock::LOCK_SHARED`.
	 *
	 * @return string The string alias (`"exclusive"`, `"shared"`, or `"unknown"`).
	 */
	private function mapLockScope(int $scope): string {
		return match ($scope) {
			ILock::LOCK_EXCLUSIVE => 'exclusive',
			ILock::LOCK_SHARED => 'shared',
			default => 'unknown',
		};
	}//end mapLockScope()
}//end class
