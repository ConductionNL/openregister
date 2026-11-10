<?php

/**
 * FilePropertyHandler
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

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCA\OpenRegister\Service\SecurityService;
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     File processing requires comprehensive validation and parsing methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex file handling with multiple input formats
 */
class FilePropertyHandler {

	/**
	 * Common file extensions that indicate downloadable files.
	 *
	 * @var list<string>
	 */
	private const COMMON_FILE_EXTENSIONS = [
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

	/**
	 * The keys a file object, as the file renderer returns it, may carry.
	 *
	 * @var list<string>
	 */
	private const FILE_OBJECT_PROPERTIES = [
		'id',
		'title',
		'path',
		'type',
		'size',
		'accessUrl',
		'downloadUrl',
		'labels',
		'extension',
		'hash',
		'modified',
		'published',
	];

	/**
	 * Stream options for fetching a file from a URL.
	 *
	 * SEC-SVC-2: redirects are DISABLED — follow_location would let a public URL
	 * 302-redirect to an internal address, defeating the point-in-time guard in
	 * fetchFileFromUrl().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const HTTP_FETCH_OPTIONS = [
		'http' => [
			// 30 second timeout.
			'timeout' => 30,
			'user_agent' => 'OpenRegister/1.0',
			'follow_location' => false,
			'max_redirects' => 0,
		],
	];

	/**
	 * The reader, guard, stored-file operations and configuration resolver delegated to.
	 *
	 * @var FilePropertyParts
	 */
	private readonly FilePropertyParts $parts;

	/**
	 * Constructor for FilePropertyHandler.
	 *
	 * @param LoggerInterface $logger Logger for logging operations
	 * @param ObjectFilesInterface $fileService File service for file operations
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function __construct(
		LoggerInterface $logger,
		private readonly ObjectFilesInterface $fileService,
	) {
		$this->parts = new FilePropertyParts(logger: $logger, fileService: $fileService);
	}//end __construct()

	/**
	 * Processes uploaded files from multipart/form-data and injects them into object data.
	 *
	 * This method handles PHP's $_FILES format uploaded via multipart/form-data requests.
	 * It converts uploaded files into data URIs that the existing file handlers process.
	 * A file with an upload error is logged and skipped, not fatal for the request.
	 *
	 * @param array $uploadedFiles The uploaded files array (from IRequest::getUploadedFile()).
	 * @param array $data The object data to inject files into.
	 *
	 * @psalm-param   array<string, array{
	 *     name: string, type: string, tmp_name: string, error: int, size: int
	 * }> $uploadedFiles
	 * @psalm-param   array<string, mixed> $data
	 * @phpstan-param array<string, array{
	 *     name: string, type: string, tmp_name: string, error: int, size: int
	 * }> $uploadedFiles
	 * @phpstan-param array<string, mixed> $data
	 *
	 * @return array The modified object data with file content injected.
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @throws Exception If file reading fails.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function processUploadedFiles(array $uploadedFiles, array $data): array {
		return $this->parts->reader->injectUploads(uploadedFiles: $uploadedFiles, data: $data);
	}//end processUploadedFiles()

	/**
	 * Whether a value is a file string, a file object, or a list holding either.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function isFileValue(mixed $value): bool {
		if (is_string($value) === true) {
			return $this->looksLikeFile(value: $value);
		}

		if (is_array($value) === false) {
			return false;
		}

		return $this->isFileObject(value: $value) === true || $this->listHoldsFile(items: $value) === true;
	}//end isFileValue()

	/**
	 * Whether any item of a list is a file string or a file object.
	 *
	 * @param array $items The list.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function listHoldsFile(array $items): bool {
		foreach ($items as $item) {
			if ($this->isFileItem(item: $item) === true) {
				return true;
			}
		}

		return false;
	}//end listHoldsFile()

	/**
	 * Whether a list item is a file string or a file object.
	 *
	 * @param mixed $item The item.
	 *
	 * @return bool
	 */
	private function isFileItem(mixed $item): bool {
		return (is_string($item) === true && $this->looksLikeFile(value: $item) === true)
			|| (is_array($item) === true && $this->isFileObject(value: $item) === true);
	}//end isFileItem()

	/**
	 * Whether the schema declares the property as a file, or as an array of files.
	 *
	 * @param Schema $schema       The schema.
	 * @param string $propertyName The property.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function isFileInSchema(Schema $schema, string $propertyName): bool {
		$propertyConfig = ($schema->getProperties() ?? [])[$propertyName] ?? null;
		if ($propertyConfig === null) {
			return false;
		}

		$propertyType = $propertyConfig['type'] ?? '';
		if ($propertyType === 'file') {
			return true;
		}

		return $propertyType === 'array' && (($propertyConfig['items'] ?? [])['type'] ?? '') === 'file';
	}//end isFileInSchema()

	/**
	 * Whether a string is a data URI, an http(s) URL to a known file extension, or long base64.
	 *
	 * Website URLs without a file extension are not files.
	 *
	 * @param string $value The string.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function looksLikeFile(string $value): bool {
		if (strpos($value, 'data:') === 0) {
			return true;
		}

		if ($this->parts->reader->isHttpUrl(value: $value) === true) {
			return $this->hasFileExtension(url: $value);
		}

		return base64_encode(base64_decode($value, true)) === $value && strlen($value) > 100;
	}//end looksLikeFile()

	/**
	 * Whether a URL's path ends in a common file extension.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function hasFileExtension(string $url): bool {
		$urlPath = parse_url($url, PHP_URL_PATH);
		if ($urlPath === null || $urlPath === '') {
			return false;
		}

		$extension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
		return in_array($extension, self::COMMON_FILE_EXTENSIONS) === true;
	}//end hasFileExtension()

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
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function isFileObject(array $value): bool {
		if (isset($value['id']) === false) {
			return false;
		}

		if (isset($value['title']) === false && isset($value['path']) === false) {
			return false;
		}

		return $this->hasFileObjectProperty(value: $value);
	}//end isFileObject()

	/**
	 * Whether an array carries a non-null file-specific property.
	 *
	 * @param array<string, mixed> $value The array.
	 *
	 * @return bool
	 */
	private function hasFileObjectProperty(array $value): bool {
		foreach (self::FILE_OBJECT_PROPERTIES as $prop) {
			if (($value[$prop] ?? null) !== null) {
				return true;
			}
		}

		return false;
	}//end hasFileObjectProperty()

	/**
	 * Generates a unique filename for uploaded files.
	 *
	 * This method creates a unique filename by combining the property name,
	 * timestamp, and random bytes to ensure uniqueness.
	 *
	 * @param FileStoreRequest $request   The file being stored: its property name and optional array index.
	 * @param string           $extension The file extension.
	 *
	 * @return string The generated unique filename.
	 *
	 * @psalm-return   string
	 * @phpstan-return string
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function generateFileName(FileStoreRequest $request, string $extension): string {
		$timestamp = time();
		$random = bin2hex(random_bytes(4));
		$indexSuffix = '';
		if ($request->index !== null) {
			$indexSuffix = "_{$request->index}";
		}

		return "{$request->propertyName}{$indexSuffix}_{$timestamp}_{$random}.{$extension}";
	}//end generateFileName()

	/**
	 * Prepares auto-tags for a file based on the property configuration.
	 *
	 * The tags are the property name tag and any configured auto-tags, deduplicated.
	 *
	 * @param array $fileConfig The file property configuration from schema.
	 * @param string $propertyName The name of the file property.
	 *
	 * @psalm-param   array<string, mixed> $fileConfig
	 * @phpstan-param array<string, mixed> $fileConfig
	 *
	 * @return string[] The prepared tags array.
	 *
	 * @psalm-return   list<string>
	 * @phpstan-return array<int, string>
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function prepareAutoTags(array $fileConfig, string $propertyName): array {
		$tags = ["property:{$propertyName}"];
		if (isset($fileConfig['autoTags']) === true && is_array($fileConfig['autoTags']) === true) {
			$tags = array_merge($tags, $fileConfig['autoTags']);
		}

		return array_values(array_unique($tags));
	}//end prepareAutoTags()

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
	 * @param array $object The object data (passed by reference to update with file IDs).
	 * @param string $propertyName The name of the file property.
	 * @param Schema $schema The schema containing property configuration.
	 *
	 * @psalm-param   ObjectEntity $objectEntity
	 * @psalm-param   array<string, mixed> $object
	 * @psalm-param   string $propertyName
	 * @psalm-param   Schema $schema
	 * @phpstan-param ObjectEntity $objectEntity
	 * @phpstan-param array<string, mixed> $object
	 * @phpstan-param string $propertyName
	 * @phpstan-param Schema $schema
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @throws Exception If file validation fails or file operations fail.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function handleFileProperty(
		ObjectEntity $objectEntity,
		array &$object,
		string $propertyName,
		Schema $schema,
	): void {
		$fileValue = $object[$propertyName];
		$target = new FilePropertyTarget(
			objectEntity: $objectEntity,
			name: $propertyName,
			isArray: (($schema->getProperties() ?? [])[$propertyName]['type'] ?? '') === 'array',
			fileConfig: $this->parts->configs->fileConfigOf(schema: $schema, propertyName: $propertyName)
		);

		$object[$propertyName] = $this->storedValue(target: $target, fileValue: $fileValue);
	}//end handleFileProperty()

	/**
	 * The value a file property holds after the save: cleared, the stored file ids, or unchanged.
	 *
	 * Null (single) or an empty array (array property) deletes the stored files. A single value
	 * that is not a file is kept as sent.
	 *
	 * @param FilePropertyTarget $target    The file property.
	 * @param mixed              $fileValue The incoming value.
	 *
	 * @return mixed
	 *
	 * @throws Exception If a file cannot be validated or stored.
	 */
	private function storedValue(FilePropertyTarget $target, mixed $fileValue): mixed {
		if ($this->isClearingValue(fileValue: $fileValue) === true) {
			return $this->parts->files->clear(target: $target);
		}

		if ($target->isArray === true) {
			return $this->storeFiles(target: $target, fileValue: $fileValue);
		}

		if ($this->isFileValue(value: $fileValue) === false) {
			return $fileValue;
		}

		return $this->processSingleFileProperty(request: $this->storeRequest(target: $target, index: null), fileInput: $fileValue);
	}//end storedValue()

	/**
	 * Whether an incoming value deletes the stored files: null, or an empty array.
	 *
	 * @param mixed $fileValue The incoming value.
	 *
	 * @return bool
	 */
	private function isClearingValue(mixed $fileValue): bool {
		return $fileValue === null || (is_array($fileValue) === true && empty($fileValue) === true);
	}//end isClearingValue()

	/**
	 * Store every file of an array property and return their ids; items that are not files are dropped.
	 *
	 * @param FilePropertyTarget $target    The file property.
	 * @param mixed              $fileValue The incoming value.
	 *
	 * @return array<int, int>
	 *
	 * @throws Exception If the value is not an array, or a file cannot be stored.
	 */
	private function storeFiles(FilePropertyTarget $target, mixed $fileValue): array {
		if (is_array($fileValue) === false) {
			throw new Exception("Property '{$target->name}' is configured as array but received non-array value");
		}

		$fileIds = [];
		foreach ($fileValue as $index => $singleFileContent) {
			if ($this->isFileValue(value: $singleFileContent) === true) {
				$fileIds[] = $this->processSingleFileProperty(
					request: $this->storeRequest(target: $target, index: $index),
					fileInput: $singleFileContent
				);
			}
		}

		return $fileIds;
	}//end storeFiles()

	/**
	 * The store request for one file of a property, for processSingleFileProperty().
	 *
	 * @param FilePropertyTarget $target The file property.
	 * @param int|null           $index  The index in an array property, null for a single file.
	 *
	 * @return FileStoreRequest
	 */
	private function storeRequest(FilePropertyTarget $target, ?int $index): FileStoreRequest {
		return new FileStoreRequest(
			objectEntity: $target->objectEntity,
			propertyName: $target->name,
			fileConfig: $target->fileConfig,
			index: $index
		);
	}//end storeRequest()

	/**
	 * Processes a single file property with validation, tagging, and storage.
	 *
	 * This method handles three types of file input:
	 * - Base64 data URIs or encoded strings
	 * - URLs (fetches file content from URL)
	 * - File objects (existing files, returns existing ID or creates copy)
	 *
	 * @param FileStoreRequest $request The object entity being saved, the name of the file property,
	 *                                  its configuration from the schema and the optional index for
	 *                                  array properties.
	 * @param mixed $fileInput The file input (string, URL, or file object).
	 *
	 * @psalm-param   FileStoreRequest $request
	 * @psalm-param   mixed $fileInput
	 * @phpstan-param FileStoreRequest $request
	 * @phpstan-param mixed $fileInput
	 *
	 * @return int The ID of the created/existing file.
	 *
	 * @psalm-return   int
	 * @phpstan-return int
	 *
	 * @throws Exception If file validation fails or file operations fail.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function processSingleFileProperty(FileStoreRequest $request, $fileInput): int {
		if (is_string($fileInput) === true) {
			return $this->processStringFileInput(request: $request, fileInput: $fileInput);
		}

		if (is_array($fileInput) === true && $this->isFileObject(value: $fileInput) === true) {
			return $this->processFileObjectInput(request: $request, fileObject: $fileInput);
		}

		throw new Exception("Unsupported file input type for property '{$request->propertyName}'");
	}//end processSingleFileProperty()

	/**
	 * Processes string file input (base64, data URI, or URL): validate, then store with tags.
	 *
	 * @param FileStoreRequest $request   The object, property, file configuration and index.
	 * @param string           $fileInput The string input (base64, data URI, or URL).
	 *
	 * @return int The ID of the created file.
	 *
	 * @throws Exception If file processing fails.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function processStringFileInput(FileStoreRequest $request, string $fileInput): int {
		$fileData = $this->fileDataOf(fileInput: $fileInput);
		$this->validateFileAgainstConfig(fileData: $fileData, request: $request);

		return $this->addFile(request: $request, fileData: $fileData);
	}//end processStringFileInput()

	/**
	 * Store validated file data on the object with a generated name, the auto-tags and autoShare.
	 *
	 * @param FileStoreRequest                                                       $request  The object, property, config and index.
	 * @param array{content: string, mimeType: string, extension: string, size: int} $fileData The file data.
	 *
	 * @return int The ID of the created file.
	 *
	 * @throws Exception If the file cannot be stored.
	 */
	private function addFile(FileStoreRequest $request, array $fileData): int {
		$file = $this->fileService->addFile(
			target: new FileTarget(object: $request->objectEntity),
			upload: new FileUpload(
				fileName: $this->generateFileName(request: $request, extension: $fileData['extension']),
				content: $fileData['content'],
				share: $request->fileConfig['autoShare'] ?? false,
				tags: $this->prepareAutoTags(fileConfig: $request->fileConfig, propertyName: $request->propertyName)
			)
		);

		return $file->getId();
	}//end addFile()

	/**
	 * The file data of a string input: fetched for an http(s) URL, else parsed as base64 or a data URI.
	 *
	 * @param string $fileInput The string input.
	 *
	 * @return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @throws Exception If the URL cannot be fetched or the data cannot be parsed.
	 */
	private function fileDataOf(string $fileInput): array {
		if ($this->parts->reader->isHttpUrl(value: $fileInput) === true) {
			return $this->parseFileDataFromUrl(url: $fileInput, content: $this->fetchFileFromUrl(url: $fileInput));
		}

		return $this->parseFileData(fileContent: $fileInput);
	}//end fileDataOf()

	/**
	 * Processes file object input: the existing file's id when this object holds it, else a copy
	 * fetched from its downloadUrl or accessUrl.
	 *
	 * @param FileStoreRequest     $request    The object, property, file configuration and index.
	 * @param array<string, mixed> $fileObject The file object input.
	 *
	 * @return int The ID of the existing or created file.
	 *
	 * @throws Exception If file processing fails.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function processFileObjectInput(FileStoreRequest $request, array $fileObject): int {
		$fileId = $fileObject['id'] ?? null;
		if ($fileId !== null && is_numeric($fileId) === true && $this->parts->files->holdsFile(request: $request, fileId: $fileId) === true) {
			return (int)$fileId;
		}

		$fileUrl = $fileObject['downloadUrl'] ?? $fileObject['accessUrl'] ?? null;
		if ($fileUrl === null) {
			throw new Exception("File object for property '{$request->propertyName}' has no downloadable URL");
		}

		return $this->processStringFileInput(request: $request, fileInput: $fileUrl);
	}//end processFileObjectInput()

	/**
	 * Fetches file content from a URL, behind the SEC-SVC-2 anti-SSRF guard and with redirects disabled.
	 *
	 * @param string $url The URL to fetch from.
	 *
	 * @return string The file content.
	 *
	 * @throws Exception If the URL cannot be fetched.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function fetchFileFromUrl(string $url): string {
		// SEC-SVC-2: validate the user-supplied URL against the shared anti-SSRF guard
		// BEFORE issuing any request. This rejects non-http(s) schemes and any host that
		// resolves to a loopback / private / reserved address (e.g. cloud metadata,
		// localhost, RFC-1918), closing the full read-SSRF on this object-write path.
		SecurityService::assertSafeFetchUrl($url);

		// SEC-SVC-2: redirects are disabled in HTTP_FETCH_OPTIONS.
		$content = file_get_contents($url, false, stream_context_create(self::HTTP_FETCH_OPTIONS));
		if ($content === false) {
			throw new Exception("Unable to fetch file from URL: $url");
		}

		return $content;
	}//end fetchFileFromUrl()

	/**
	 * Parses file data from URL fetch results.
	 *
	 * The MIME type is detected from the content; the extension comes from the URL path,
	 * else from the MIME type.
	 *
	 * @param string $url The original URL.
	 * @param string $content The fetched content.
	 *
	 * @return (int|string)[]
	 *
	 * @psalm-return   array{content: string, mimeType: string, extension: string, size: int<0, max>}
	 * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function parseFileDataFromUrl(string $url, string $content): array {
		$mimeType = $this->parts->reader->detectMimeType(content: $content);
		$parsedUrl = parse_url($url);
		$extension = pathinfo($parsedUrl['path'] ?? '', PATHINFO_EXTENSION);
		if (empty($extension) === true) {
			$extension = $this->getExtensionFromMimeType(mimeType: $mimeType);
		}

		return $this->parts->reader->fileData(content: $content, format: new FileFormat(mimeType: $mimeType, extension: $extension));
	}//end parseFileDataFromUrl()

	/**
	 * Parses file data from various formats (data URI, base64) and extracts metadata.
	 *
	 * @param string $fileContent The file content to parse.
	 *
	 * @return (int|string)[]
	 *
	 * @throws Exception If the file data format is invalid.
	 *
	 * @psalm-return   array{content: string, mimeType: string, extension: string, size: int<0, max>}
	 * @phpstan-return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function parseFileData(string $fileContent): array {
		return $this->parts->reader->parse(fileContent: $fileContent);
	}//end parseFileData()

	/**
	 * Validates a file against property configuration.
	 *
	 * Executables are refused first (unless explicitly allowed), then the MIME type, then the size.
	 *
	 * @param array            $fileData The parsed file data.
	 * @param FileStoreRequest $request  The file being stored: its property configuration, and its
	 *                                   property name and optional array index (for error messages).
	 *
	 * @psalm-param   array{content: string, mimeType: string, extension: string, size: int} $fileData
	 * @phpstan-param array{content: string, mimeType: string, extension: string, size: int} $fileData
	 *
	 * @return void
	 *
	 * @throws Exception If validation fails.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function validateFileAgainstConfig(array $fileData, FileStoreRequest $request): void {
		$errorPrefix = $this->parts->guard->errorPrefix(propertyName: $request->propertyName, index: $request->index);
		if (($request->fileConfig['allowExecutables'] ?? false) === false) {
			$this->blockExecutableFiles(fileData: $fileData, errorPrefix: $errorPrefix);
		}

		$this->parts->guard->assertAccepted(check: new UploadCheck(fileData: $fileData, errorPrefix: $errorPrefix, fileConfig: $request->fileConfig));
	}//end validateFileAgainstConfig()

	/**
	 * Blocks executable files from being uploaded for security.
	 *
	 * The file extension is checked first, then the magic bytes of the content, then the MIME type.
	 *
	 * @param array $fileData The file data containing content, mimeType, and filename.
	 * @param string $errorPrefix The error message prefix for context.
	 *
	 * @psalm-param   array<string, mixed> $fileData
	 * @phpstan-param array<string, mixed> $fileData
	 *
	 * @return void
	 *
	 * @throws Exception If an executable file is detected.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function blockExecutableFiles(array $fileData, string $errorPrefix): void {
		$this->parts->guard->blockExecutableExtension(fileData: $fileData, errorPrefix: $errorPrefix);

		// The filename is passed through because the embedded-PHP-tag scan is scoped by
		// extension as well as by sniffed content type — see openregister#2776. An absent
		// filename yields '', which is treated as text-ish and always scanned.
		if (($fileData['content'] ?? null) !== null && empty($fileData['content']) === false) {
			// The checks live in ExecutableContentDetector, shared with FileValidationHandler.
			$this->parts->guard->blockExecutableContent(
				upload: new UploadedContent(content: $fileData['content'], errorPrefix: $errorPrefix, fileName: (string)($fileData['filename'] ?? ''))
			);
		}

		$this->parts->guard->blockMimeTypeIn(
			check: new UploadCheck(fileData: $fileData, errorPrefix: $errorPrefix),
			mimeTypes: $this->getExecutableMimeTypes()
		);
	}//end blockExecutableFiles()

	/**
	 * Gets file extension from MIME type.
	 *
	 * @param string $mimeType The MIME type.
	 *
	 * @return string The extension, `bin` for an unknown type.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function getExtensionFromMimeType(string $mimeType): string {
		return $this->parts->reader->extensionOf(mimeType: $mimeType);
	}//end getExtensionFromMimeType()

	/**
	 * Gets a list of executable MIME types to block.
	 *
	 * @return string[]
	 *
	 * @phpstan-return array<int, string>
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private function getExecutableMimeTypes(): array {
		return $this->parts->guard->executableMimeTypes();
	}//end getExecutableMimeTypes()
}//end class
