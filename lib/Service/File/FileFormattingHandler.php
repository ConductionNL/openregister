<?php

/**
 * FileFormattingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
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
     * @param TaggingHandler     $taggingHandler     Tagging handler for tag operations.
     * @param FileSharingHandler $fileSharingHandler Sharing handler for share operations.
     * @param IURLGenerator      $urlGenerator       URL generator for creating URLs.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly TaggingHandler $taggingHandler,
        private readonly FileSharingHandler $fileSharingHandler,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger
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
     */
    public function formatFile(Node $file): array
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->fileService->findShares($file);

        // Get base metadata array.
        if (count($shares) > 0) {
            $accessUrl   = $this->fileService->getShareLink($shares[0]);
            $downloadUrl = $accessUrl.'/download';
        } else {
            $accessUrl   = null;
            $downloadUrl = null;
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
                } else if (isset($metadata[$key]) === true) {
                    // If key exists but not as array, convert to array with both values.
                    $metadata[$key] = [$metadata[$key], $value];
                } else {
                    // If key doesn't exist, create new entry.
                    $metadata[$key] = $value;
                }//end if
            } else {
                $remainingLabels[] = $label;
            }//end if
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
     *     limit: int<1, 100>, offset: int<0, max>}
     * @phpstan-return array{results: array<int, array<string, mixed>>,
     *     total: int, page: int, pages: int, limit: int, offset: int}
     *
     * @throws InvalidPathException If any file path is invalid.
     * @throws NotFoundException    If files are not found.
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        // Format all files first.
        $formattedFiles = [];
        foreach ($files as $file) {
            $formattedFiles[] = $this->formatFile($file);
        }

        // Extract and apply filters.
        $filters        = $this->extractFilterParameters($requestParams ?? []);
        $formattedFiles = $this->applyFileFilters($formattedFiles, $filters);

        // Apply pagination.
        $page   = max(1, (int) ($requestParams['page'] ?? 1));
        $limit  = max(1, min(100, (int) ($requestParams['limit'] ?? 30)));
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
}//end class
