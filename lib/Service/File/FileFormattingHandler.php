<?php

/**
 * FileFormattingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;

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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Handler coordinates formatting, sharing, tagging, locking, URL generation, and session lookup.
 */
class FileFormattingHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for FileFormattingHandler.
     *
     * @param TaggingHandler                       $taggingHandler     Tagging handler for tag operations.
     * @param FileSharingHandler                   $fileSharingHandler Sharing handler for share operations.
     * @param IURLGenerator                        $urlGenerator       URL generator for creating URLs.
     * @param ILockManager                         $lockManager        Lock manager for reading file lock state.
     * @param IUserSession                         $userSession        Session used to gate lock fields for authenticated callers only.
     * @param LoggerInterface                      $logger             Logger for logging operations.
     * @param \OCA\OpenRegister\Db\FileMapper|null $fileMapper         Optional OR-side metadata mapper for
     *                                                                 description / category / labels / downloadCount
     *                                                                 enrichment. When null (test fixtures, legacy
     *                                                                 callers), enrichment is skipped silently.
     */
    public function __construct(
        private readonly TaggingHandler $taggingHandler,
        private readonly FileSharingHandler $fileSharingHandler,
        private readonly IURLGenerator $urlGenerator,
        private readonly ILockManager $lockManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly ?\OCA\OpenRegister\Db\FileMapper $fileMapper=null
    ) {
    }//end __construct()

    /**
     * Set the FileService instance for cross-handler coordination.
     *
     * @param FileService $fileService The file service instance.
     *
     * @return void
     */
    public function setFileService(FileService $fileService): void
    {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Label processing requires many conditional branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple paths for label key-value extraction
     */
    public function formatFile(Node $file): array
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->fileService->findShares($file);

        // Get base metadata array.
        $accessUrl   = null;
        $downloadUrl = null;
        if (count($shares) > 0) {
            $accessUrl   = $this->fileService->getShareLink($shares[0]);
            $downloadUrl = $accessUrl.'/download';
        }

        $metadata = [
            'id'          => $file->getId(),
            'path'        => $file->getPath(),
            'title'       => $file->getName(),
            'accessUrl'   => $accessUrl,
            'downloadUrl' => $downloadUrl,
            'type'        => $file->getMimetype(),
            'extension'   => $file->getExtension(),
            'size'        => $file->getSize(),
            'hash'        => $file->getEtag(),
            'published'   => (new DateTime())->setTimestamp($file->getCreationTime())->format('c'),
            'modified'    => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
            'labels'      => $this->fileService->getFileTags((string) $file->getId()),
        ];

        // Append NC lock state for authenticated callers only (anonymous callers
        // MUST NOT see who holds a lock — prevents leaking activity/identity on
        // public objects). See design.md Decision 6.
        if ($this->userSession->getUser() !== null) {
            $lockEnvelope       = $this->formatLock(fileId: (int) $file->getId());
            $metadata['locked'] = $lockEnvelope !== null;
            if ($lockEnvelope !== null) {
                $metadata['lock'] = $lockEnvelope;
            }
        }

        // Append OR-side metadata enrichment (description / category /
        // OR-managed labels / downloadCount) when the FileMapper is wired
        // and a row exists for this fileId. Anonymous callers see the
        // public-safe subset — description and category and labels are
        // safe to expose, downloadCount is gated on authentication.
        if ($this->fileMapper !== null) {
            try {
                $orFile = $this->fileMapper->findByFileId(fileId: (int) $file->getId());
                if ($orFile !== null) {
                    $metadata['description'] = $orFile->getDescription();
                    $metadata['category']    = $orFile->getCategory();
                    $orLabels = ($orFile->getLabels() ?? []);
                    if (empty($orLabels) === false) {
                        // Merge OR-managed labels into the existing tag-
                        // backed labels array so consumers see one
                        // labels collection. De-dupe by string identity.
                        $metadata['labels'] = array_values(
                            array_unique(array_merge($metadata['labels'], $orLabels))
                        );
                    }

                    if ($this->userSession->getUser() !== null) {
                        $metadata['downloadCount'] = $orFile->getDownloadCount();

                        // Surface the OR-side lock fields ALONGSIDE
                        // the NC ILockManager state. They're separate
                        // concerns: the cache-backed FileLockHandler
                        // that powers ILockManager isn't the only path
                        // — operators can also persist locks via
                        // FileMapper::setLockForFile for cross-restart
                        // durability. When both are set the consumer
                        // sees both shapes; when neither is set the
                        // keys are absent. Same auth-gating as
                        // downloadCount + the NC lock envelope.
                        $orLockedBy = $orFile->getLockedBy();
                        if ($orLockedBy !== null) {
                            $orLockedAt         = $orFile->getLockedAt();
                            $orLockExpires      = $orFile->getLockExpires();
                            $metadata['orLock'] = [
                                'lockedBy'    => $orLockedBy,
                                'lockedAt'    => $orLockedAt?->format('c'),
                                'lockExpires' => $orLockExpires?->format('c'),
                            ];
                        }
                    }//end if
                }//end if
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[FileFormattingHandler] OR-side metadata lookup failed; continuing without enrichment',
                    context: [
                        'file'   => __FILE__,
                        'line'   => __LINE__,
                        'fileId' => (int) $file->getId(),
                        'error'  => $e->getMessage(),
                    ]
                );
            }//end try
        }//end if

        // Process labels that contain ':' to add as separate metadata fields.
        $remainingLabels = [];
        foreach ($metadata['labels'] as $label) {
            if (strpos($label, ':') !== false) {
                list($key, $value) = explode(':', $label, 2);
                $key   = trim($key);
                $value = trim($value);

                // Skip if key exists in base metadata.
                if (isset($metadata[$key]) === true) {
                    $remainingLabels[] = $label;
                    continue;
                }

                // If key already exists as array, append value.
                if (isset($metadata[$key]) === true && is_array($metadata[$key]) === true) {
                    $metadata[$key][] = $value;
                    continue;
                }

                if (isset($metadata[$key]) === true) {
                    // If key exists but not as array, convert to array with both values.
                    $metadata[$key] = [$metadata[$key], $value];
                    continue;
                }

                // If key doesn't exist, create new entry.
                $metadata[$key] = $value;

                continue;
            }//end if

            $remainingLabels[] = $label;
        }//end foreach

        // Update labels array to only contain non-processed labels.
        $metadata['labels'] = $remainingLabels;

        return $metadata;
    }//end formatFile()

    /**
     * Format multiple files with filtering, sorting, and pagination.
     *
     * This method formats an array of file nodes into standardized metadata arrays,
     * applies filtering based on request parameters (labels, extensions, size, search),
     * and returns paginated results with metadata.
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
     * @throws NotFoundException    If files are not found.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) File formatting with pagination requires multiple branches
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter and pagination paths
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        // Format all files first. A single NC-locked file must not break the
        // whole listing — catch LockedException per-file and emit a minimal
        // envelope so the caller can still see the entry. See design.md
        // Decision 3.
        $isAuthenticated = $this->userSession->getUser() !== null;
        $formattedFiles  = [];
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
        $filters        = $this->extractFilterParameters(requestParams: $requestParams ?? []);
        $formattedFiles = $this->applyFileFilters(formattedFiles: $formattedFiles, filters: $filters);

        // Apply pagination (support both _page/_limit and page/limit conventions).
        // No upper ceiling on `_limit`: per-object attachment counts are the
        // natural bound, and a 100-file cap silently truncated production
        // listings. Floor at 1 keeps pagination arithmetic sound. See
        // design.md Decision 4.
        $page   = max(1, (int) ($requestParams['_page'] ?? $requestParams['page'] ?? 1));
        $limit  = max(1, (int) ($requestParams['_limit'] ?? $requestParams['limit'] ?? 30));
        $offset = ($page - 1) * $limit;
        $total  = count($formattedFiles);
        $pages  = (int) ceil($total / $limit);

        // Slice the results for the current page.
        $results = array_slice($formattedFiles, $offset, $limit);

        return [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
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
     * @SuppressWarnings(PHPMD.NPathComplexity)      Filter extraction requires many conditional parameter checks
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Many filter types require conditional handling
     */
    private function extractFilterParameters(array $requestParams): array
    {
        $filters = [];

        // Labels filtering (business logic filters prefixed with underscore).
        if (($requestParams['_hasLabels'] ?? null) !== null) {
            $filters['_hasLabels'] = (bool) $requestParams['_hasLabels'];
        }

        if (($requestParams['_noLabels'] ?? null) !== null) {
            $filters['_noLabels'] = (bool) $requestParams['_noLabels'];
        }

        if (($requestParams['labels'] ?? null) !== null) {
            $labels = $requestParams['labels'];
            if (is_string($labels) === true) {
                $filters['labels'] = array_map('trim', explode(',', $labels));
            } else if (is_array($labels) === true) {
                $filters['labels'] = $labels;
            }
        }

        // Extension filtering.
        if (($requestParams['extension'] ?? null) !== null) {
            $filters['extension'] = trim($requestParams['extension']);
        }

        if (($requestParams['extensions'] ?? null) !== null) {
            $extensions = $requestParams['extensions'];
            if (is_string($extensions) === true) {
                $filters['extensions'] = array_map('trim', explode(',', $extensions));
            } else if (is_array($extensions) === true) {
                $filters['extensions'] = $extensions;
            }
        }

        // Size filtering.
        if (($requestParams['minSize'] ?? null) !== null) {
            $filters['minSize'] = (int) $requestParams['minSize'];
        }

        if (($requestParams['maxSize'] ?? null) !== null) {
            $filters['maxSize'] = (int) $requestParams['maxSize'];
        }

        // Title/search filtering.
        if (($requestParams['title'] ?? null) !== null) {
            $filters['title'] = trim($requestParams['title']);
        }

        if (($requestParams['search'] ?? null) !== null || (($requestParams['_search'] ?? null) !== null) === true) {
            $filters['search'] = trim($requestParams['search'] ?? $requestParams['_search']);
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
     * @param array $filters        Filter parameters to apply.
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Many filter types require conditional branches
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple filter combinations create many execution paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive filter support requires extensive code
     */
    private function applyFileFilters(array $formattedFiles, array $filters): array
    {
        if (empty($filters) === true) {
            return $formattedFiles;
        }

        return array_filter(
            $formattedFiles,
            function (array $file) use ($filters): bool {
                // Filter by label presence (business logic filter).
                if (($filters['_hasLabels'] ?? null) !== null) {
                    $hasLabels = empty($file['labels']) === false;
                    if ($filters['_hasLabels'] !== $hasLabels) {
                        return false;
                    }
                }

                // Filter for files without labels (business logic filter).
                if (($filters['_noLabels'] ?? null) !== null && $filters['_noLabels'] === true) {
                    $hasLabels = empty($file['labels']) === false;
                    if ($hasLabels === true) {
                        return false;
                    }
                }

                // Filter by specific labels.
                if (($filters['labels'] ?? null) !== null && empty($filters['labels']) === false) {
                    $fileLabels       = $file['labels'] ?? [];
                    $hasMatchingLabel = false;

                    foreach ($filters['labels'] as $requiredLabel) {
                        if (in_array($requiredLabel, $fileLabels, true) === true) {
                            $hasMatchingLabel = true;
                            break;
                        }
                    }

                    if ($hasMatchingLabel === false) {
                        return false;
                    }
                }

                // Filter by single extension.
                if (($filters['extension'] ?? null) !== null) {
                    $fileExtension = $file['extension'] ?? '';
                    if (strcasecmp($fileExtension, $filters['extension']) !== 0) {
                        return false;
                    }
                }

                // Filter by multiple extensions.
                if (($filters['extensions'] ?? null) !== null && empty($filters['extensions']) === false) {
                    $fileExtension        = $file['extension'] ?? '';
                    $hasMatchingExtension = false;

                    foreach ($filters['extensions'] as $allowedExtension) {
                        if (strcasecmp($fileExtension, $allowedExtension) === 0) {
                            $hasMatchingExtension = true;
                            break;
                        }
                    }

                    if ($hasMatchingExtension === false) {
                        return false;
                    }
                }

                // Filter by file size range.
                if (($filters['minSize'] ?? null) !== null) {
                    $fileSize = $file['size'] ?? 0;
                    if ($fileSize < $filters['minSize']) {
                        return false;
                    }
                }

                if (($filters['maxSize'] ?? null) !== null) {
                    $fileSize = $file['size'] ?? 0;
                    if ($fileSize > $filters['maxSize']) {
                        return false;
                    }
                }

                // Filter by title/filename content.
                if (($filters['title'] ?? null) !== null && empty($filters['title']) === false) {
                    $fileTitle = $file['title'] ?? '';
                    if (stripos($fileTitle, $filters['title']) === false) {
                        return false;
                    }
                }

                // Filter by search term (searches in title).
                if (($filters['search'] ?? null) !== null && empty($filters['search']) === false) {
                    $fileTitle = $file['title'] ?? '';
                    if (stripos($fileTitle, $filters['search']) === false) {
                        return false;
                    }
                }

                // File passed all filters.
                return true;
            }
        );
    }//end applyFileFilters()

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
    private function formatLock(int $fileId): ?array
    {
        if ($this->lockManager->isLockProviderAvailable() === false) {
            return null;
        }

        try {
            $locks = $this->lockManager->getLocks(fileId: $fileId);
        } catch (Exception $lockLookupException) {
            // A failure to query the lock provider must not break the listing;
            // treat it as "no lock info available" and move on.
            $this->logger->warning(
                message: "[FileFormattingHandler] formatLock: lock lookup failed for fileId {$fileId}: ".$lockLookupException->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
            );

            return null;
        }

        if (count($locks) === 0) {
            return null;
        }

        $lock = $locks[0];

        $createdAt = $lock->getCreatedAt();
        $timeout   = $lock->getTimeout();
        $expiresAt = null;
        if ($timeout > 0) {
            $expiresAt = (new DateTime())->setTimestamp($createdAt + $timeout)->format('c');
        }

        return [
            'type'      => $this->mapLockType(type: $lock->getType()),
            'scope'     => $this->mapLockScope(scope: $lock->getScope()),
            'owner'     => $lock->getOwner(),
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
     * @param Node            $file            The file node that failed to format.
     * @param LockedException $lockedException The raised LockedException.
     * @param bool            $isAuthenticated Whether the request has a user session.
     *
     * @return array<string, mixed>
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    private function buildLockedStubEntry(Node $file, LockedException $lockedException, bool $isAuthenticated): array
    {
        $fileId = (int) $file->getId();
        $name   = $file->getName();

        $bestEffortLock = null;
        if ($isAuthenticated === true) {
            $bestEffortLock = $this->formatLock(fileId: $fileId);
        }

        $this->logger->info(
            message: "[FileFormattingHandler] formatFiles: file {$name} (ID: {$fileId}) is locked, emitting stub entry",
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'app'       => 'openregister',
                'fileId'    => $fileId,
                'name'      => $name,
                'lockType'  => $bestEffortLock['type'] ?? null,
                'lockOwner' => $bestEffortLock['owner'] ?? null,
                'reason'    => $lockedException->getMessage(),
            ]
        );

        $stub = [
            'id'    => $fileId,
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
    private function mapLockType(int $type): string
    {
        return match ($type) {
            ILock::TYPE_USER  => 'user',
            ILock::TYPE_APP   => 'app',
            ILock::TYPE_TOKEN => 'token',
            default           => 'unknown',
        };
    }//end mapLockType()

    /**
     * Map an `ILock::LOCK_*` scope constant to a stable string alias for the public API.
     *
     * @param int $scope One of `ILock::LOCK_EXCLUSIVE`, `ILock::LOCK_SHARED`.
     *
     * @return string The string alias (`"exclusive"`, `"shared"`, or `"unknown"`).
     */
    private function mapLockScope(int $scope): string
    {
        return match ($scope) {
            ILock::LOCK_EXCLUSIVE => 'exclusive',
            ILock::LOCK_SHARED    => 'shared',
            default               => 'unknown',
        };
    }//end mapLockScope()
}//end class
