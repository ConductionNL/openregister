<?php

/**
 * FilePropertyHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use finfo;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Handles file property processing including upload, validation, and security checks.
 *
 * This handler is responsible for:
 * - Processing uploaded files (multipart/form-data)
 * - Detecting and validating file properties
 * - Parsing file data from data URIs, base64, and URLs
 * - Validating files against schema configuration
 * - Security: blocking executable files
 * - Managing file IDs in object data
 * - Applying auto-tags to files
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FilePropertyHandler
{
    /**
     * Constructor for FilePropertyHandler.
     *
     * @param FileService     $fileService File service for managing files.
     * @param LoggerInterface $logger      Logger for logging operations.
     */
    public function __construct(
        // REMOVED: private readonly.
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Processes uploaded files from multipart/form-data and injects them into object data.
     *
     * This method handles PHP's $_FILES format uploaded via multipart/form-data requests.
     * It converts uploaded files into a format that can be processed by existing file handlers.
     *
     * @param array $uploadedFiles The uploaded files array (from IRequest::getUploadedFile()).
     * @param array $data          The object data to inject files into.
     *
     * @psalm-param   array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $uploadedFiles
     * @psalm-param   array<string, mixed> $data
     * @phpstan-param array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $uploadedFiles
     * @phpstan-param array<string, mixed> $data
     *
     * @return array The modified object data with file content injected.
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @throws Exception If file reading fails.
     */
    public function processUploadedFiles(array $uploadedFiles, array $data): array
    {
        foreach ($uploadedFiles as $fieldName => $fileInfo) {
            // Skip files with upload errors.
            if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
                // Log the error but don't fail the entire request.
                $this->logger->warning(
                    'File upload error for field {field}: {error}',
                    [
                        'app'   => 'openregister',
                        'field' => $fieldName,
                        'error' => $fileInfo['error'],
                        'file'  => $fileInfo['name'] ?? 'unknown',
                    ]
                );
                continue;
            }

            // Read file content.
            $fileContent = file_get_contents($fileInfo['tmp_name']);
            if ($fileContent === false) {
                throw new Exception("Failed to read uploaded file for field '$fieldName'");
            }

            // Create a data URI from the uploaded file.
            // This allows the existing file handling logic to process it.
            $mimeType      = $fileInfo['type'] ?? 'application/octet-stream';
            $base64Content = base64_encode($fileContent);
            $dataUri       = "data:$mimeType;base64,$base64Content";

            // Handle array field names (e.g., 'images[]' or 'images[0]' becomes 'images').
            // Strip the array suffix/index and treat as array property.
            $isArrayField   = false;
            $cleanFieldName = $fieldName;

            // Check for array notation: images[] or images[0], images[1], etc.
            if (preg_match('/^(.+)\[\d*\]$/', $fieldName, $matches) === 1) {
                $isArrayField   = true;
                $cleanFieldName = $matches[1];
                // Extract 'images' from 'images[0]'.
            }

            // Inject the data URI into the object data.
            // If the field already has a value in $data, the uploaded file takes precedence.
            if ($isArrayField === true) {
                // For array fields, append to array.
                if (isset($data[$cleanFieldName]) === false) {
                    $data[$cleanFieldName] = [];
                }

                $data[$cleanFieldName][] = $dataUri;
                continue;
            }

            // For single fields, set directly.
            $data[$fieldName] = $dataUri;
        }//end foreach

        return $data;
    }//end processUploadedFiles()

    /**
     * Check if a value should be treated as a file property
     *
     * @param mixed       $value        The value to check
     * @param Schema|null $schema       Optional schema for property-based checking
     * @param string|null $propertyName Optional property name for schema lookup
     *
     * @return         bool True if the value should be treated as a file property
     * @phpstan-return bool
     */
    public function isFileProperty($value, ?Schema $schema=null, ?string $propertyName=null): bool
    {
        // If we have schema and property name, use schema-based checking.
        if ($schema !== null && $propertyName !== null) {
            $schemaProperties = $schema->getProperties() ?? [];

            if (isset($schemaProperties[$propertyName]) === false) {
                return false;
                // Property not in schema, not a file.
            }

            $propertyConfig = $schemaProperties[$propertyName];

            // Check if it's a direct file property.
            if (($propertyConfig['type'] ?? '') === 'file') {
                return true;
            }

            // Check if it's an array of files.
            if (($propertyConfig['type'] ?? '') === 'array') {
                $itemsConfig = $propertyConfig['items'] ?? [];
                if (($itemsConfig['type'] ?? '') === 'file') {
                    return true;
                }
            }

            return false;
            // Property exists but is not configured as file type.
        }//end if

        // Fallback to format-based checking when schema info is not available.
        // This is used within handleFileProperty for individual value validation.
        // Check for single file (data URI, base64, URL with file extension, or file object).
        if (is_string($value) === true) {
            // Data URI format.
            if (strpos($value, 'data:') === 0) {
                return true;
            }

            // URL format (http/https) - but only if it looks like a downloadable file.
            if (filter_var($value, FILTER_VALIDATE_URL) !== false
                && (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0)
            ) {
                // Parse URL to get path.
                $urlPath = parse_url($value, PHP_URL_PATH);
                if ($urlPath !== null && $urlPath !== '') {
                    // Get file extension.
                    $extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

                    // Common file extensions that indicate downloadable files.
                    $fileExtensions = $this->getCommonFileExtensions();

                    // Only treat as file if it has a recognized file extension.
                    if (in_array($extension, $fileExtensions) === true) {
                        return true;
                    }
                }//end if

                // Don't treat regular website URLs as files.
                return false;
            }//end if

            // Base64 encoded string (simple heuristic).
            if (base64_encode(base64_decode($value, true)) === $value && strlen($value) > 100) {
                return true;
            }
        }//end if

        // Check for file object (array with required file object properties).
        if (is_array($value) === true && $this->isFileObject($value) === true) {
            return true;
        }

        // Check for array of files.
        if (is_array($value) === true) {
            foreach ($value as $item) {
                if (is_string($item) === true) {
                    // Data URI.
                    if (strpos($item, 'data:') === 0) {
                        return true;
                    }

                    // URL with file extension.
                    if (filter_var($item, FILTER_VALIDATE_URL) !== false
                        && (strpos($item, 'http://') === 0 || strpos($item, 'https://') === 0)
                    ) {
                        $urlPath = parse_url($item, PHP_URL_PATH);
                        if ($urlPath !== null && $urlPath !== '') {
                            $extension      = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                            $fileExtensions = $this->getCommonFileExtensions();
                            if (in_array($extension, $fileExtensions) === true) {
                                return true;
                            }
                        }//end if
                    }//end if

                    // Base64.
                    if (base64_encode(base64_decode($item, true)) === $item && strlen($item) > 100) {
                        return true;
                    }
                } else if (is_array($item) === true && $this->isFileObject($item) === true) {
                    // File object in array.
                    return true;
                }//end if
            }//end foreach
        }//end if

        return false;
    }//end isFileProperty()

    /**
     * Checks if an array represents a file object.
     *
     * A file object should have at least an 'id' and either 'title' or 'path'.
     * This matches the structure returned by the file renderer.
     *
     * @param array $value The array to check.
     *
     * @psalm-param   array<string, mixed> $value
     * @phpstan-param array<string, mixed> $value
     *
     * @return bool Whether the array is a file object.
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    public function isFileObject(array $value): bool
    {
        // Must have an ID.
        if (isset($value['id']) === false) {
            return false;
        }

        // Must have either title or path (typical file object properties).
        if (isset($value['title']) === false && isset($value['path']) === false) {
            return false;
        }

        // Should not be a regular data array with other purposes.
        // File objects typically have file-specific properties.
        $fileProperties    = ['id', 'title', 'path', 'type', 'size', 'accessUrl', 'downloadUrl', 'labels', 'extension', 'hash', 'modified', 'published'];
        $hasFileProperties = false;

        foreach ($fileProperties as $prop) {
            if (($value[$prop] ?? null) !== null) {
                $hasFileProperties = true;
                break;
            }
        }

        return $hasFileProperties;
    }//end isFileObject()

    /**
     * Handles a file property during save with validation and proper ID storage.
     *
     * This method processes file properties by:
     * - Validating files against schema property configuration (MIME type, size)
     * - Applying auto tags from the property configuration
     * - Storing file IDs in the object data instead of just attaching files
     * - Supporting both single files and arrays of files
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        &$object      The object data (passed by reference to update with file IDs).
     * @param string       $propertyName The name of the file property.
     * @param Schema       $schema       The schema containing property configuration.
     *
     * @psalm-param   ObjectEntity $objectEntity
     * @psalm-param   array<string, mixed> &$object
     * @psalm-param   string $propertyName
     * @psalm-param   Schema $schema
     * @phpstan-param ObjectEntity $objectEntity
     * @phpstan-param array<string, mixed> &$object
     * @phpstan-param string $propertyName
     * @phpstan-param Schema $schema
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws Exception If file validation fails or file operations fail.
     */
    public function handleFileProperty(ObjectEntity $objectEntity, array &$object, string $propertyName, Schema $schema): void
    {
        $fileValue        = $object[$propertyName];
        $schemaProperties = $schema->getProperties() ?? [];

        // Get property configuration for this file property.
        if (isset($schemaProperties[$propertyName]) === false) {
            throw new Exception("Property '$propertyName' not found in schema configuration");
        }

        $propertyConfig = $schemaProperties[$propertyName];

        // Determine if this is a direct file property or array[file].
        $isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';
        $fileConfig      = $propertyConfig;
        if ($isArrayProperty === true) {
            $fileConfig = ($propertyConfig['items'] ?? []);
        }

        // Validate that the property is configured for files.
        if (($fileConfig['type'] ?? '') !== 'file') {
            throw new Exception("Property '$propertyName' is not configured as a file property");
        }

        // Handle file deletion: null for single files, empty array for array properties.
        if ($fileValue === null || (is_array($fileValue) === true && empty($fileValue) === true) === true) {
            // Get existing file IDs from the current object data.
            $currentObjectData = $objectEntity->getObject();
            $existingFileIds   = $currentObjectData[$propertyName] ?? null;

            if ($existingFileIds !== null) {
                // Delete existing files.
                if (is_array($existingFileIds) === true) {
                    // Array of file IDs.
                    foreach ($existingFileIds as $fileId) {
                        if (is_numeric($fileId) === true) {
                            try {
                                null;
                                // TODO->deleteFile(file: (int) $fileId, object: $objectEntity).
                            } catch (Exception $e) {
                                // Log but don't fail - file might already be deleted.
                                $this->logger->warning("Failed to delete file $fileId: ".$e->getMessage());
                            }
                        }
                    }
                } else if (is_numeric($existingFileIds) === true) {
                    // Single file ID.
                    try {
                        null;
                        // TODO->deleteFile(file: (int) $existingFileIds, object: $objectEntity).
                    } catch (Exception $e) {
                        // Log but don't fail - file might already be deleted.
                        $this->logger->warning("Failed to delete file $existingFileIds: ".$e->getMessage());
                    }
                }//end if
            }//end if

            // Set property to null or empty array.
            $object[$propertyName] = null;
            if ($isArrayProperty === true) {
                $object[$propertyName] = [];
            }

            return;
        }//end if

        if ($isArrayProperty === true) {
            // Handle array of files.
            if (is_array($fileValue) === false) {
                throw new Exception("Property '$propertyName' is configured as array but received non-array value");
            }

                        $fileIds = [];
            foreach ($fileValue as $index => $singleFileContent) {
                if ($this->isFileProperty(value: $singleFileContent) === true) {
                    $fileId = $this->processSingleFileProperty(
                        objectEntity: $objectEntity,
                        fileInput: $singleFileContent,
                        propertyName: $propertyName,
                        fileConfig: $fileConfig,
                        index: $index
                    );
                    if ($fileId !== null) {
                        $fileIds[] = $fileId;
                    }
                }
            }

            // Replace the file content with file IDs in the object data.
            $object[$propertyName] = $fileIds;
        }//end if

        if ($isArrayProperty === false) {
            // Handle single file.
            if ($this->isFileProperty(value: $fileValue) === true) {
                $fileId = $this->processSingleFileProperty(
                    objectEntity: $objectEntity,
                    fileInput: $fileValue,
                    propertyName: $propertyName,
                    fileConfig: $fileConfig
                );

                // Replace the file content with file ID in the object data.
                if ($fileId !== null) {
                    $object[$propertyName] = $fileId;
                }
            }
        }//end if
    }//end handleFileProperty()

    /**
     * Processes a single file property with validation, tagging, and storage.
     *
     * This method handles three types of file input:
     * - Base64 data URIs or encoded strings
     * - URLs (fetches file content from URL)
     * - File objects (existing files, returns existing ID or creates copy)
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param mixed        $fileInput    The file input (string, URL, or file object).
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @psalm-param   ObjectEntity $objectEntity
     * @psalm-param   mixed $fileInput
     * @psalm-param   string $propertyName
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   int|null $index
     * @phpstan-param ObjectEntity $objectEntity
     * @phpstan-param mixed $fileInput
     * @phpstan-param string $propertyName
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param int|null $index
     *
     * @return int The ID of the created/existing file.
     *
     * @psalm-return   int
     * @phpstan-return int
     *
     * @throws Exception If file validation fails or file operations fail.
     */
    public function processSingleFileProperty(
        ObjectEntity $objectEntity,
        $fileInput,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): int {
        try {
            // Determine input type and process accordingly.
            if (is_string($fileInput) === true) {
                // Handle string inputs (base64, data URI, or URL).
                return $this->processStringFileInput(objectEntity: $objectEntity, fileInput: $fileInput, propertyName: $propertyName, fileConfig: $fileConfig, index: $index);
            }

            if (is_array($fileInput) === true && $this->isFileObject($fileInput) === true) {
                // Handle file object input.
                return $this->processFileObjectInput(objectEntity: $objectEntity, fileObject: $fileInput, propertyName: $propertyName, fileConfig: $fileConfig, index: $index);
            }

            throw new Exception("Unsupported file input type for property '$propertyName'");
        } catch (Exception $e) {
            throw $e;
        }
    }//end processSingleFileProperty()

    /**
     * Processes string file input (base64, data URI, or URL).
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param string       $fileInput    The string input (base64, data URI, or URL).
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @psalm-param   ObjectEntity $objectEntity
     * @psalm-param   string $fileInput
     * @psalm-param   string $propertyName
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   int|null $index
     * @phpstan-param ObjectEntity $objectEntity
     * @phpstan-param string $fileInput
     * @phpstan-param string $propertyName
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param int|null $index
     *
     * @return int The ID of the created file.
     *
     * @psalm-return   int
     * @phpstan-return int
     *
     * @throws Exception If file processing fails.
     */
    private function processStringFileInput(
        ObjectEntity $objectEntity,
        string $fileInput,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): int {
        // Check if it's a URL.
        if (filter_var($fileInput, FILTER_VALIDATE_URL) !== false
            && (strpos($fileInput, 'http://') === 0 || strpos($fileInput, 'https://') === 0)
        ) {
            // Fetch file content from URL.
            $fileContent = $this->fetchFileFromUrl($fileInput);
            $fileData    = $this->parseFileDataFromUrl(url: $fileInput, content: $fileContent);
        }

        if (is_string($fileInput) === false
            || (filter_var($fileInput, FILTER_VALIDATE_URL) === false
            && (str_starts_with($fileInput, 'http://') === false && str_starts_with($fileInput, 'https://') === false))
        ) {
            // Parse as base64 or data URI.
            $fileData = $this->parseFileData($fileInput);
        }

        // Validate file against property configuration.
        $this->validateFileAgainstConfig(fileData: $fileData, fileConfig: $fileConfig, propertyName: $propertyName, index: $index);

        // Generate filename (currently unused - will be used when fileService is implemented).
        // $filename = $this->generateFileName(propertyName: $propertyName, extension: $fileData['extension'], index: $index);
        // Prepare auto tags (currently unused - will be used when fileService is implemented).
        // $autoTags = $this->prepareAutoTags(fileConfig: $fileConfig, propertyName: $propertyName, index: $index);
        // Check if auto-publish is enabled in the property configuration (currently unused).
        // $autoPublish = $fileConfig['autoPublish'] ?? false;
        // Create the file with validation and tagging.
        $file = null;
        // TODO: Implement file creation when fileService is available.
        // $file = $this->fileService->addFile(
        // ObjectEntity: $objectEntity,
        // FileName: $filename,
        // Content: $fileData['content'],
        // Share: $autoPublish,
        // Tags: $autoTags
        // );
        return $file->getId();
    }//end processStringFileInput()

    /**
     * Processes file object input (existing file object).
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        $fileObject   The file object input.
     * @param string       $propertyName The name of the file property.
     * @param array        $fileConfig   The file property configuration from schema.
     * @param int|null     $index        Optional index for array properties.
     *
     * @psalm-param   ObjectEntity $objectEntity
     * @psalm-param   array<string, mixed> $fileObject
     * @psalm-param   string $propertyName
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   int|null $index
     * @phpstan-param ObjectEntity $objectEntity
     * @phpstan-param array<string, mixed> $fileObject
     * @phpstan-param string $propertyName
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param int|null $index
     *
     * @return int The ID of the existing or created file.
     *
     * @psalm-return   int
     * @phpstan-return int
     *
     * @throws Exception If file processing fails.
     */
    private function processFileObjectInput(
        ObjectEntity $objectEntity,
        array $fileObject,
        string $propertyName,
        array $fileConfig,
        ?int $index=null
    ): int {
        // If file object has an ID, try to use the existing file.
        if (($fileObject['id'] ?? null) !== null) {
            $fileId = (int) $fileObject['id'];

            // Validate that the existing file meets the property configuration.
            // Get file info to validate against config.
            try {
                // TODO: Implement file retrieval when fileService is available.
                // $existingFile = $this->fileService->getFile(object: $objectEntity, file: $fileId);
                // If ($existingFile !== null) {
                // Validate the existing file against current config.
                // $this->validateExistingFileAgainstConfig(file: $existingFile, fileConfig: $fileConfig, propertyName: $propertyName, index: $index);
                // Apply auto tags if needed (non-destructive - adds to existing tags).
                // $this->applyAutoTagsToExistingFile(file: $existingFile, fileConfig: $fileConfig, propertyName: $propertyName, index: $index);
                // Return $fileId;
                // }
                // }.
            } catch (Exception $e) {
                // Existing file not accessible, continue to create new one.
            }
        }//end if

        // If no ID or existing file not accessible, create a new file.
        // This requires downloadUrl or accessUrl to fetch content.
        if (($fileObject['downloadUrl'] ?? null) !== null) {
            $fileUrl = $fileObject['downloadUrl'];
        }

        if (($fileObject['accessUrl'] ?? null) !== null) {
            $fileUrl = $fileObject['accessUrl'];
        }

        if (($fileObject['downloadUrl'] ?? null) === null && ($fileObject['accessUrl'] ?? null) === null) {
            throw new Exception("File object for property '$propertyName' has no downloadable URL");
        }

        // Fetch and process as URL.
        return $this->processStringFileInput(objectEntity: $objectEntity, fileInput: $fileUrl, propertyName: $propertyName, fileConfig: $fileConfig, index: $index);
    }//end processFileObjectInput()

    /**
     * Fetches file content from a URL.
     *
     * @param string $url The URL to fetch from.
     *
     * @return string The file content.
     *
     * @throws Exception If the URL cannot be fetched.
     *
     * @psalm-param    string $url
     * @phpstan-param  string $url
     * @psalm-return   string
     * @phpstan-return string
     */
    private function fetchFileFromUrl(string $url): string
    {
        // Create a context with appropriate options.
        $context = stream_context_create(
            [
                'http' => [
                    'timeout'         => 30,
            // 30 second timeout.
                    'user_agent'      => 'OpenRegister/1.0',
                    'follow_location' => true,
                    'max_redirects'   => 5,
                ],
            ]
        );

        $content = file_get_contents($url, false, $context);

        if ($content === false) {
            throw new Exception("Unable to fetch file from URL: $url");
        }

        return $content;
    }//end fetchFileFromUrl()

    /**
     * Parses file data from URL fetch results.
     *
     * @param string $url     The original URL.
     * @param string $content The fetched content.
     *
     * @return (int|string)[]
     *
     * @throws Exception If the file data cannot be parsed.
     *
     * @psalm-param string $url
     * @psalm-param string $content
     *
     * @phpstan-param string $url
     * @phpstan-param string $content
     *
     * @psalm-return   array{content: string, mimeType: string, extension: string, size: int<0, max>}
     * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
     */
    private function parseFileDataFromUrl(string $url, string $content): array
    {
        // Try to detect MIME type from content.
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if ($mimeType === false) {
            $mimeType = 'application/octet-stream';
        }

        // Try to get extension from URL.
        $parsedUrl = parse_url($url);
        $path      = $parsedUrl['path'] ?? '';
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        // If no extension from URL, get from MIME type.
        if (empty($extension) === true) {
            $extension = $this->getExtensionFromMimeType($mimeType);
        }

        return [
            'content'   => $content,
            'mimeType'  => $mimeType,
            'extension' => $extension,
            'size'      => strlen($content),
        ];
    }//end parseFileDataFromUrl()

    /**
     * Validates an existing file against property configuration.
     *
     * @param File     $file         The existing file.
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name (for error messages).
     * @param int|null $index        Optional array index (for error messages).
     *
     * @psalm-param   File $file
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   string $propertyName
     * @psalm-param   int|null $index
     * @phpstan-param File $file
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param string $propertyName
     * @phpstan-param int|null $index
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws Exception If validation fails.
     */
    private function validateExistingFileAgainstConfig(File $file, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $errorPrefix = "Existing file at $propertyName";
        if ($index !== null) {
            $errorPrefix = "Existing file at $propertyName[$index]";
        }

        // Validate MIME type.
        if (($fileConfig['allowedTypes'] ?? null) !== null && empty($fileConfig['allowedTypes']) === false) {
            $fileMimeType = $file->getMimeType();
            if (in_array($fileMimeType, $fileConfig['allowedTypes'], true) === false) {
                throw new Exception(
                    "$errorPrefix has invalid type '$fileMimeType'. "."Allowed types: ".implode(', ', $fileConfig['allowedTypes'])
                );
            }
        }

        // Validate file size.
        if (($fileConfig['maxSize'] ?? null) !== null && $fileConfig['maxSize'] > 0) {
            $fileSize = $file->getSize();
            if ($fileSize > $fileConfig['maxSize']) {
                throw new Exception(
                    "$errorPrefix exceeds maximum size ({$fileConfig['maxSize']} bytes). "."File size: {$fileSize} bytes"
                );
            }
        }
    }//end validateExistingFileAgainstConfig()

    /**
     * Applies auto tags to an existing file (non-destructive).
     *
     * @param File     $file         The existing file.
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name.
     * @param int|null $index        Optional array index.
     *
     * @psalm-param   File $file
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   string $propertyName
     * @psalm-param   int|null $index
     * @phpstan-param File $file
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param string $propertyName
     * @phpstan-param int|null $index
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    private function applyAutoTagsToExistingFile(File $file, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $autoTags = $this->prepareAutoTags(fileConfig: $fileConfig, propertyName: $propertyName, index: $index);

        if (empty($autoTags) === false) {
            // Get existing tags and merge with auto tags.
            try {
                // TODO: Implement file formatting and tag updating when fileService is available.
                // $formattedFile = $this->fileService->formatFile($file);
                // $existingTags = $formattedFile['labels'] ?? [];
                // $allTags = array_unique(array_merge($existingTags, $autoTags));
                // $this->fileService->updateFile(
                // FilePath: $file->getId(),
                // Content: null,  // Don't change content
                // Tags: $allTags
                // );
            } catch (Exception $e) {
                // Log but don't fail - auto tagging is not critical.
            }
        }
    }//end applyAutoTagsToExistingFile()

    /**
     * Parses file data from various formats (data URI, base64) and extracts metadata.
     *
     * @param string $fileContent The file content to parse.
     *
     * @return (int|string)[]
     *
     * @throws Exception If the file data format is invalid.
     *
     * @psalm-param string $fileContent
     *
     * @phpstan-param string $fileContent
     *
     * @psalm-return   array{content: string, mimeType: string, extension: string, size: int<0, max>}
     * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
     */
    public function parseFileData(string $fileContent): array
    {
        $mimeType = 'application/octet-stream';

        // Handle data URI format (data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...).
        if (strpos($fileContent, 'data:') === 0) {
            // Extract MIME type and content from data URI.
            if (preg_match('/^data:([^;]+);base64,(.+)$/', $fileContent, $matches) === 1) {
                $mimeType = $matches[1];
                $content  = base64_decode($matches[2], true);
                // Strict mode.
                if ($content === false) {
                    throw new Exception('Invalid base64 content in data URI');
                }
            }

            if (preg_match('/^data:([^;]+);base64,(.+)$/', $fileContent, $matches) !== 1) {
                throw new Exception('Invalid data URI format');
            }
        }

        if (strpos($fileContent, 'data:') !== 0) {
            // Handle plain base64 content.
            $content = base64_decode($fileContent, true);
            // Strict mode.
            if ($content === false) {
                throw new Exception('Invalid base64 content');
            }

            // Try to detect MIME type from content.
            $finfo            = new finfo(FILEINFO_MIME_TYPE);
            $detectedMimeType = $finfo->buffer($content);
            if ($detectedMimeType !== false) {
                $mimeType = $detectedMimeType;
            }
        }//end if

        // Determine file extension from MIME type.
        $extension = $this->getExtensionFromMimeType($mimeType);

        return [
            'content'   => $content,
            'mimeType'  => $mimeType,
            'extension' => $extension,
            'size'      => strlen($content),
        ];
    }//end parseFileData()

    /**
     * Validates a file against property configuration.
     *
     * @param array    $fileData     The parsed file data.
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name (for error messages).
     * @param int|null $index        Optional array index (for error messages).
     *
     * @psalm-param   array{content: string, mimeType: string, extension: string, size: int} $fileData
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   string $propertyName
     * @psalm-param   int|null $index
     * @phpstan-param array{content: string, mimeType: string, extension: string, size: int} $fileData
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param string $propertyName
     * @phpstan-param int|null $index
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws Exception If validation fails.
     */
    public function validateFileAgainstConfig(array $fileData, array $fileConfig, string $propertyName, ?int $index=null): void
    {
        $errorPrefix = $index !== null ? "File at $propertyName[$index]" : "File at $propertyName";

        // Security: Block executable files (unless explicitly allowed).
        $allowExecutables = $fileConfig['allowExecutables'] ?? false;
        if ($allowExecutables === false) {
            $this->blockExecutableFiles(fileData: $fileData, errorPrefix: $errorPrefix);
        }

        // Validate MIME type.
        if (($fileConfig['allowedTypes'] ?? null) !== null && empty($fileConfig['allowedTypes']) === false) {
            if (in_array($fileData['mimeType'], $fileConfig['allowedTypes'], true) === false) {
                throw new Exception(
                    "$errorPrefix has invalid type '{$fileData['mimeType']}'. "."Allowed types: ".implode(', ', $fileConfig['allowedTypes'])
                );
            }
        }

        // Validate file size.
        if (($fileConfig['maxSize'] ?? null) !== null && $fileConfig['maxSize'] > 0) {
            if ($fileData['size'] > $fileConfig['maxSize']) {
                throw new Exception(
                    "$errorPrefix exceeds maximum size ({$fileConfig['maxSize']} bytes). "."File size: {$fileData['size']} bytes"
                );
            }
        }
    }//end validateFileAgainstConfig()

    /**
     * Blocks executable files from being uploaded for security.
     *
     * This method checks both file extensions and magic bytes to detect executables.
     *
     * @param array  $fileData    The file data containing content, mimeType, and filename.
     * @param string $errorPrefix The error message prefix for context.
     *
     * @psalm-param   array<string, mixed> $fileData
     * @psalm-param   string $errorPrefix
     * @phpstan-param array<string, mixed> $fileData
     * @phpstan-param string $errorPrefix
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws Exception If an executable file is detected.
     */
    public function blockExecutableFiles(array $fileData, string $errorPrefix): void
    {
        // List of dangerous executable extensions.
        $dangerousExtensions = $this->getDangerousExecutableExtensions();

        // Check file extension.
        if (($fileData['filename'] ?? null) !== null) {
            $extension = strtolower(pathinfo($fileData['filename'], PATHINFO_EXTENSION));
            if (in_array($extension, $dangerousExtensions, true) === true) {
                $this->logger->warning(
                    'Executable file upload blocked',
                    [
                        'app'       => 'openregister',
                        'filename'  => $fileData['filename'],
                        'extension' => $extension,
                        'mimeType'  => $fileData['mimeType'] ?? 'unknown',
                    ]
                );

                throw new Exception(
                    "$errorPrefix is an executable file (.$extension). "."Executable files are blocked for security reasons. "."Allowed formats: documents, images, archives, data files."
                );
            }
        }

        // Check magic bytes (file signatures) in content.
        if (($fileData['content'] ?? null) !== null && empty($fileData['content']) === false) {
            $this->detectExecutableMagicBytes(content: $fileData['content'], errorPrefix: $errorPrefix);
        }

        // Check MIME types for executables.
        $executableMimeTypes = $this->getExecutableMimeTypes();

        if (($fileData['mimeType'] ?? null) !== null && in_array($fileData['mimeType'], $executableMimeTypes, true) === true) {
            $this->logger->warning(
                'Executable MIME type blocked',
                [
                    'app'      => 'openregister',
                    'mimeType' => $fileData['mimeType'],
                ]
            );

            throw new Exception(
                "$errorPrefix has executable MIME type '{$fileData['mimeType']}'. "."Executable files are blocked for security reasons."
            );
        }
    }//end blockExecutableFiles()

    /**
     * Detects executable magic bytes in file content.
     *
     * Magic bytes are signatures at the start of files that identify the file type.
     * This provides defense-in-depth against renamed executables.
     *
     * @param string $content     The file content to check.
     * @param string $errorPrefix The error message prefix.
     *
     * @psalm-param   string $content
     * @psalm-param   string $errorPrefix
     * @phpstan-param string $content
     * @phpstan-param string $errorPrefix
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws Exception If executable magic bytes are detected.
     */
    private function detectExecutableMagicBytes(string $content, string $errorPrefix): void
    {
        // Common executable magic bytes.
        $magicBytes = [
            'MZ'               => 'Windows executable (PE/EXE)',
            "\x7FELF"          => 'Linux/Unix executable (ELF)',
            "#!/bin/sh"        => 'Shell script',
            "#!/bin/bash"      => 'Bash script',
            "#!/usr/bin/env"   => 'Script with env shebang',
            "<?php"            => 'PHP script',
            "\xCA\xFE\xBA\xBE" => 'Java class file',
            "PK\x03\x04"       => false,
        // ZIP - need deeper inspection as JARs are ZIPs.
            // Note: "\x50\x4B\x03\x04" is the same as "PK\x03\x04" (PK in hex), so removed duplicate.
        ];

        foreach ($magicBytes as $signature => $description) {
            if ($description === false) {
                continue;
                // Skip patterns that need deeper inspection.
            }

            if (strpos($content, $signature) === 0) {
                $this->logger->warning(
                    'Executable magic bytes detected',
                    [
                        'app'  => 'openregister',
                        'type' => $description,
                    ]
                );

                throw new Exception(
                    "$errorPrefix contains executable code ($description). "."Executable files are blocked for security reasons."
                );
            }
        }//end foreach

        // Check for script shebangs anywhere in first 4 lines.
        $firstLines = substr($content, 0, 1024);
        if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines) === 1) {
            throw new Exception(
                "$errorPrefix contains script shebang. "."Script files are blocked for security reasons."
            );
        }

        // Check for embedded PHP tags.
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines) === 1) {
            throw new Exception(
                "$errorPrefix contains PHP code. "."PHP files are blocked for security reasons."
            );
        }
    }//end detectExecutableMagicBytes()

    /**
     * Generates a filename for a file property.
     *
     * @param string   $propertyName The property name.
     * @param string   $extension    The file extension.
     * @param int|null $index        Optional array index.
     *
     * @psalm-param string $propertyName
     * @psalm-param string $extension
     * @psalm-param int|null $index
     *
     * @phpstan-param string $propertyName
     * @phpstan-param string $extension
     * @phpstan-param int|null $index
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    private function generateFileName(string $propertyName, string $extension, ?int $index=null): string
    {
        $timestamp   = time();
        $indexSuffix = $index !== null ? "_$index" : '';

        return "{$propertyName}{$indexSuffix}_{$timestamp}.{$extension}";
    }//end generateFileName()

    /**
     * Prepares auto tags for a file based on property configuration.
     *
     * @param array    $fileConfig   The file property configuration.
     * @param string   $propertyName The property name.
     * @param int|null $index        Optional array index.
     *
     * @psalm-param   array<string, mixed> $fileConfig
     * @psalm-param   string $propertyName
     * @psalm-param   int|null $index
     * @phpstan-param array<string, mixed> $fileConfig
     * @phpstan-param string $propertyName
     * @phpstan-param int|null $index
     *
     * @return array The prepared auto tags.
     *
     * @psalm-return   list<string>
     * @phpstan-return array<int, string>
     */
    private function prepareAutoTags(array $fileConfig, string $propertyName, ?int $index=null): array
    {
        $autoTags = $fileConfig['autoTags'] ?? [];

        // Replace placeholders in auto tags.
        $processedTags = [];
        foreach ($autoTags as $tag) {
            // Handle both string tags and array tags (flatten arrays).
            if (is_array($tag) === true) {
                $tag = implode(',', array_filter($tag, 'is_string'));
            }

            // Ensure tag is a string.
            if (is_string($tag) === false) {
                continue;
            }

            // Replace property name placeholder.
            $tag = str_replace('{property}', $propertyName, $tag);
            $tag = str_replace('{propertyName}', $propertyName, $tag);

            // Replace index placeholder for array properties.
            if ($index !== null) {
                $tag = str_replace('{index}', (string) $index, $tag);
            }

            $processedTags[] = $tag;
        }//end foreach

        return $processedTags;
    }//end prepareAutoTags()

    /**
     * Gets file extension from MIME type.
     *
     * @param string $mimeType The MIME type.
     *
     * @psalm-param string $mimeType
     *
     * @phpstan-param string $mimeType
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExtension = [
            // Images.
            'image/jpeg'                                                                => 'jpg',
            'image/jpg'                                                                 => 'jpg',
            'image/png'                                                                 => 'png',
            'image/gif'                                                                 => 'gif',
            'image/webp'                                                                => 'webp',
            'image/svg+xml'                                                             => 'svg',
            'image/bmp'                                                                 => 'bmp',
            'image/tiff'                                                                => 'tiff',
            'image/x-icon'                                                              => 'ico',

            // Documents.
            'application/pdf'                                                           => 'pdf',
            'application/msword'                                                        => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
            'application/vnd.ms-excel'                                                  => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
            'application/vnd.ms-powerpoint'                                             => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/rtf'                                                           => 'rtf',
            'application/vnd.oasis.opendocument.text'                                   => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet'                            => 'ods',
            'application/vnd.oasis.opendocument.presentation'                           => 'odp',

            // Text.
            'text/plain'                                                                => 'txt',
            'text/csv'                                                                  => 'csv',
            'text/html'                                                                 => 'html',
            'text/css'                                                                  => 'css',
            'text/javascript'                                                           => 'js',
            'application/json'                                                          => 'json',
            'application/xml'                                                           => 'xml',
            'text/xml'                                                                  => 'xml',

            // Archives.
            'application/zip'                                                           => 'zip',
            'application/x-rar-compressed'                                              => 'rar',
            'application/x-7z-compressed'                                               => '7z',
            'application/x-tar'                                                         => 'tar',
            'application/gzip'                                                          => 'gz',

            // Audio.
            'audio/mpeg'                                                                => 'mp3',
            'audio/wav'                                                                 => 'wav',
            'audio/ogg'                                                                 => 'ogg',
            'audio/aac'                                                                 => 'aac',
            'audio/flac'                                                                => 'flac',

            // Video.
            'video/mp4'                                                                 => 'mp4',
            'video/mpeg'                                                                => 'mpeg',
            'video/quicktime'                                                           => 'mov',
            'video/x-msvideo'                                                           => 'avi',
            'video/webm'                                                                => 'webm',
        ];

        return $mimeToExtension[$mimeType] ?? 'bin';
    }//end getExtensionFromMimeType()

    /**
     * Gets a list of common file extensions that indicate downloadable files.
     *
     * @return string[]
     *
     * @psalm-return   list{'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', '3gp', 'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'xml', 'json', 'sql', 'exe', 'dmg', 'iso', 'deb', 'rpm'}
     * @phpstan-return array<int, string>
     */
    private function getCommonFileExtensions(): array
    {
        return [
            // Documents.
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'odt',
            'ods',
            'odp',
            'rtf',
            'txt',
            'csv',
            // Images.
            'jpg',
            'jpeg',
            'png',
            'gif',
            'bmp',
            'svg',
            'webp',
            'tiff',
            'ico',
            // Videos.
            'mp4',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm',
            'mkv',
            '3gp',
            // Audio.
            'mp3',
            'wav',
            'ogg',
            'flac',
            'aac',
            'm4a',
            'wma',
            // Archives.
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            'bz2',
            'xz',
            // Other common file types.
            'xml',
            'json',
            'sql',
            'exe',
            'dmg',
            'iso',
            'deb',
            'rpm',
        ];
    }//end getCommonFileExtensions()

    /**
     * Gets a list of dangerous executable extensions to block.
     *
     * @return string[]
     *
     * @psalm-return   list{'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'dll', 'sh', 'bash', 'csh', 'ksh', 'zsh', 'run', 'bin', 'app', 'deb', 'rpm', 'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'py', 'pyc', 'pyo', 'pyw', 'pl', 'pm', 'cgi', 'rb', 'rbw', 'jar', 'war', 'ear', 'class', 'appimage', 'snap', 'flatpak', 'dmg', 'pkg', 'command', 'apk', 'elf', 'out', 'o', 'so', 'dylib'}
     * @phpstan-return array<int, string>
     */
    private function getDangerousExecutableExtensions(): array
    {
        return [
            // Windows executables.
            'exe',
            'bat',
            'cmd',
            'com',
            'msi',
            'scr',
            'vbs',
            'vbe',
            'js',
            'jse',
            'wsf',
            'wsh',
            'ps1',
            'dll',
            // Unix/Linux executables.
            'sh',
            'bash',
            'csh',
            'ksh',
            'zsh',
            'run',
            'bin',
            'app',
            'deb',
            'rpm',
            // Scripts and code.
            'php',
            'phtml',
            'php3',
            'php4',
            'php5',
            'phps',
            'phar',
            'py',
            'pyc',
            'pyo',
            'pyw',
            'pl',
            'pm',
            'cgi',
            'rb',
            'rbw',
            'jar',
            'war',
            'ear',
            'class',
            // Containers and packages.
            'appimage',
            'snap',
            'flatpak',
            // MacOS.
            'dmg',
            'pkg',
            'command',
            // Android.
            'apk',
            // Other dangerous.
            'elf',
            'out',
            'o',
            'so',
            'dylib',
        ];
    }//end getDangerousExecutableExtensions()

    /**
     * Gets a list of executable MIME types to block.
     *
     * @return string[]
     *
     * @psalm-return   list{'application/x-executable', 'application/x-sharedlib', 'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program', 'application/x-sh', 'application/x-shellscript', 'application/x-php', 'application/x-httpd-php', 'text/x-php', 'text/x-shellscript', 'text/x-script.python', 'application/x-python-code', 'application/java-archive'}
     * @phpstan-return array<int, string>
     */
    private function getExecutableMimeTypes(): array
    {
        return [
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/x-msdownload',
            'application/x-msdos-program',
            'application/x-sh',
            'application/x-shellscript',
            'application/x-php',
            'application/x-httpd-php',
            'text/x-php',
            'text/x-shellscript',
            'text/x-script.python',
            'application/x-python-code',
            'application/java-archive',
        ];
    }//end getExecutableMimeTypes()
}//end class
