<?php

/**
 * OpenRegister FileInputReader
 *
 * Reads file content and its metadata from uploads, data URIs and base64.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use finfo;

/**
 * Turns multipart uploads into data URIs, recognises http(s) URLs and parses data URIs and
 * base64 into file data: content, MIME type, extension and size.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FileInputReader {

	/**
	 * The extension a stored file gets for its MIME type; any other type gets `bin`.
	 *
	 * @var array<string, string>
	 */
	private const MIME_TO_EXTENSION = [
		// Images.
		'image/jpeg' => 'jpg',
		'image/jpg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
		'image/svg+xml' => 'svg',
		'image/bmp' => 'bmp',
		'image/tiff' => 'tiff',
		'image/x-icon' => 'ico',

		// Documents.
		'application/pdf' => 'pdf',
		'application/msword' => 'doc',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.ms-excel' => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.ms-powerpoint' => 'ppt',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/rtf' => 'rtf',
		'application/vnd.oasis.opendocument.text' => 'odt',
		'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
		'application/vnd.oasis.opendocument.presentation' => 'odp',

		// Text.
		'text/plain' => 'txt',
		'text/csv' => 'csv',
		'text/html' => 'html',
		'text/css' => 'css',
		'text/javascript' => 'js',
		'application/json' => 'json',
		'application/xml' => 'xml',
		'text/xml' => 'xml',

		// Archives.
		'application/zip' => 'zip',
		'application/x-rar-compressed' => 'rar',
		'application/x-7z-compressed' => '7z',
		'application/x-tar' => 'tar',
		'application/gzip' => 'gz',

		// Audio.
		'audio/mpeg' => 'mp3',
		'audio/wav' => 'wav',
		'audio/ogg' => 'ogg',
		'audio/aac' => 'aac',
		'audio/flac' => 'flac',

		// Video.
		'video/mp4' => 'mp4',
		'video/mpeg' => 'mpeg',
		'video/quicktime' => 'mov',
		'video/x-msvideo' => 'avi',
		'video/webm' => 'webm',
	];

	/**
	 * Constructor.
	 *
	 * @param FilePropertyLog $log The file property log.
	 */
	public function __construct(
		private readonly FilePropertyLog $log,
	) {
	}//end __construct()

	/**
	 * Inject every upload into the object data as a data URI; one with an upload error is
	 * logged and skipped, not fatal for the request.
	 *
	 * @param array<string, array<string, mixed>> $uploadedFiles The uploaded files.
	 * @param array<string, mixed>                $data          The object data.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Exception If an upload cannot be read.
	 */
	public function injectUploads(array $uploadedFiles, array $data): array {
		foreach ($uploadedFiles as $fieldName => $fileInfo) {
			if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
				$this->logUploadError(fieldName: $fieldName, fileInfo: $fileInfo);
				continue;
			}

			$dataUri = $this->uploadDataUri(fieldName: $fieldName, fileInfo: $fileInfo);
			$list    = $this->listField(fieldName: $fieldName);
			if ($list === null) {
				$data[$fieldName] = $dataUri;
				continue;
			}

			$data[$list][] = $dataUri;
		}

		return $data;
	}//end injectUploads()

	/**
	 * Whether a string is a valid http:// or https:// URL.
	 *
	 * @param string $value The string.
	 *
	 * @return bool
	 */
	public function isHttpUrl(string $value): bool {
		return filter_var($value, FILTER_VALIDATE_URL) !== false
			&& (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0);
	}//end isHttpUrl()

	/**
	 * Parse a data URI or plain base64.
	 *
	 * @param string $fileContent The file content to parse.
	 *
	 * @return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @throws Exception If the file data format is invalid.
	 */
	public function parse(string $fileContent): array {
		if (strpos($fileContent, 'data:') === 0) {
			return $this->parseDataUri(dataUri: $fileContent);
		}

		return $this->parseBase64(base64: $fileContent);
	}//end parse()

	/**
	 * The extension for a MIME type, `bin` for an unknown one.
	 *
	 * @param string $mimeType The MIME type.
	 *
	 * @return string
	 */
	public function extensionOf(string $mimeType): string {
		return self::MIME_TO_EXTENSION[$mimeType] ?? 'bin';
	}//end extensionOf()

	/**
	 * Log an upload that arrived with an error.
	 *
	 * @param string               $fieldName The form field name.
	 * @param array<string, mixed> $fileInfo  The upload entry.
	 *
	 * @return void
	 */
	private function logUploadError(string $fieldName, array $fileInfo): void {
		$this->log->write(
			level: 'warning',
			message: '[FilePropertyHandler] File upload error for field {field}: {error}',
			context: ['field' => $fieldName, 'error' => $fileInfo['error'], 'filename' => $fileInfo['name'] ?? 'unknown']
		);
	}//end logUploadError()

	/**
	 * Read an uploaded file into a data URI the file handlers process.
	 *
	 * Readability is checked first so an absent or unreadable tmp path throws this
	 * clear exception instead of leaking a file_get_contents() warning.
	 *
	 * @param string               $fieldName The form field name.
	 * @param array<string, mixed> $fileInfo  The upload entry.
	 *
	 * @return string
	 *
	 * @throws Exception If the file cannot be read.
	 */
	private function uploadDataUri(string $fieldName, array $fileInfo): string {
		if (is_readable((string)($fileInfo['tmp_name'] ?? '')) === false) {
			throw new Exception("Failed to read uploaded file for field '$fieldName'");
		}

		$fileContent = file_get_contents($fileInfo['tmp_name']);
		if ($fileContent === false) {
			throw new Exception("Failed to read uploaded file for field '$fieldName'");
		}

		$mimeType = $fileInfo['type'] ?? 'application/octet-stream';
		return "data:$mimeType;base64," . base64_encode($fileContent);
	}//end uploadDataUri()

	/**
	 * The list a field name in array notation (`files[]`, `files[0]`) appends to.
	 *
	 * @param string $fieldName The upload's field name.
	 *
	 * @return string|null The list's key, or null when the name is a plain field.
	 */
	private function listField(string $fieldName): ?string {
		if (preg_match('/^(.+)\[\d*\]$/', $fieldName, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end listField()

	/**
	 * Parse a data URI (data:image/png;base64,iVBOR...); its MIME type is taken as declared.
	 *
	 * @param string $dataUri The data URI.
	 *
	 * @return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @throws Exception If the data URI or its base64 content is invalid.
	 */
	private function parseDataUri(string $dataUri): array {
		if (preg_match('/^data:([^;]+);base64,(.+)$/', $dataUri, $matches) !== 1) {
			throw new Exception('Invalid data URI format');
		}

		// Strict mode.
		$content = base64_decode($matches[2], true);
		if ($content === false) {
			throw new Exception('Invalid base64 content in data URI');
		}

		return $this->fileData(content: $content, format: new FileFormat(mimeType: $matches[1], extension: $this->extensionOf(mimeType: $matches[1])));
	}//end parseDataUri()

	/**
	 * Parse plain base64 content; its MIME type is detected from the decoded bytes.
	 *
	 * @param string $base64 The base64 content.
	 *
	 * @return array{content: string, mimeType: string, extension: string, size: int}
	 *
	 * @throws Exception If the content is not valid base64.
	 */
	private function parseBase64(string $base64): array {
		// Strict mode.
		$content = base64_decode($base64, true);
		if ($content === false) {
			throw new Exception('Invalid base64 content');
		}

		$mimeType = $this->detectMimeType(content: $content);
		return $this->fileData(content: $content, format: new FileFormat(mimeType: $mimeType, extension: $this->extensionOf(mimeType: $mimeType)));
	}//end parseBase64()

	/**
	 * The MIME type finfo detects for some content, application/octet-stream when it cannot.
	 *
	 * @param string $content The content.
	 *
	 * @return string
	 */
	public function detectMimeType(string $content): string {
		$mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
		if ($mimeType === false) {
			return 'application/octet-stream';
		}

		return $mimeType;
	}//end detectMimeType()

	/**
	 * The parsed file data shape.
	 *
	 * @param string     $content The file content.
	 * @param FileFormat $format  The MIME type and the extension.
	 *
	 * @return array{content: string, mimeType: string, extension: string, size: int}
	 */
	public function fileData(string $content, FileFormat $format): array {
		return [
			'content' => $content,
			'mimeType' => $format->mimeType,
			'extension' => $format->extension,
			'size' => strlen($content),
		];
	}//end fileData()
}//end class
