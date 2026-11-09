<?php

/**
 * OpenRegister FileUploadGuard
 *
 * Refuses a file a file property must not store.
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
use OCA\OpenRegister\Service\File\ExecutableContentDetector;
use OCA\OpenRegister\Service\File\FilePropertyRules;

/**
 * The refusals of a file property: an executable by extension, content or MIME type, a MIME
 * type the property does not accept, and an oversized file.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FileUploadGuard {

	/**
	 * Executable MIME types to block.
	 *
	 * @var list<string>
	 */
	private const EXECUTABLE_MIME_TYPES = [
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

	/**
	 * Constructor.
	 *
	 * @param FilePropertyLog           $log                 The file property log.
	 * @param ExecutableContentDetector $executableDetector  Shared executable-content detection.
	 * @param list<string>              $dangerousExtensions The executable file extensions to block.
	 */
	public function __construct(
		private readonly FilePropertyLog $log,
		private readonly ExecutableContentDetector $executableDetector,
		private readonly array $dangerousExtensions,
	) {
	}//end __construct()

	/**
	 * Refuse a file whose MIME type the property does not accept, then one larger than it accepts.
	 *
	 * @param array<string, mixed> $fileData    The parsed file data.
	 * @param array<string, mixed> $fileConfig  The file property configuration.
	 * @param string               $errorPrefix The error message prefix.
	 *
	 * @return void
	 *
	 * @throws Exception If the type is not accepted or the file is too large.
	 */
	public function assertAccepted(array $fileData, array $fileConfig, string $errorPrefix): void {
		$this->assertAllowedType(fileData: $fileData, fileConfig: $fileConfig, errorPrefix: $errorPrefix);
		$this->assertWithinMaxSize(fileData: $fileData, fileConfig: $fileConfig, errorPrefix: $errorPrefix);
	}//end assertAccepted()

	/**
	 * Refuse content with an executable signature, a script shebang or (text-ish payloads only)
	 * an embedded PHP tag. The checks live in {@see ExecutableContentDetector}; this owns the
	 * caller-facing message shape and the logging.
	 *
	 * @param string $content     The file content to check.
	 * @param string $errorPrefix The error message prefix.
	 * @param string $fileName    The filename, used for its extension only; '' when unknown.
	 *
	 * @return void
	 *
	 * @throws Exception If executable content is detected.
	 */
	public function blockExecutableContent(string $content, string $errorPrefix, string $fileName): void {
		// Offset-0 executable signatures — universal, every file property.
		$this->blockExecutableSignature(content: $content, errorPrefix: $errorPrefix);

		// Script shebangs — universal, every file property.
		if ($this->executableDetector->hasScriptShebang(content: $content) === true) {
			throw new Exception(
				"$errorPrefix contains script shebang. Script files are blocked for security reasons."
			);
		}

		// Embedded PHP tags — text-ish payloads only, see openregister#2776.
		if ($this->executableDetector->containsEmbeddedPhpTag(content: $content, fileName: $fileName) === true) {
			throw new Exception(
				"$errorPrefix contains PHP code. PHP files are blocked for security reasons."
			);
		}
	}//end blockExecutableContent()

	/**
	 * The executable MIME types blocked.
	 *
	 * @return list<string>
	 */
	public function executableMimeTypes(): array {
		return self::EXECUTABLE_MIME_TYPES;
	}//end executableMimeTypes()

	/**
	 * The prefix of a validation error: the property, with the index for an array item.
	 *
	 * @param string   $propertyName The property name.
	 * @param int|null $index        The array index, null for a single file.
	 *
	 * @return string
	 */
	public function errorPrefix(string $propertyName, ?int $index): string {
		if ($index === null) {
			return "File at $propertyName";
		}

		return "File at {$propertyName}[{$index}]";
	}//end errorPrefix()

	/**
	 * Refuse a file whose MIME type the property does not accept.
	 *
	 * Two configuration shapes are in use, see {@see FilePropertyRules::allowedTypes()}.
	 *
	 * @param array<string, mixed> $fileData    The parsed file data.
	 * @param array<string, mixed> $fileConfig  The file property configuration.
	 * @param string               $errorPrefix The error message prefix.
	 *
	 * @return void
	 *
	 * @throws Exception If the type is not accepted.
	 */
	private function assertAllowedType(array $fileData, array $fileConfig, string $errorPrefix): void {
		$allowedTypes = (new FilePropertyRules())->allowedTypes(fileConfig: $fileConfig);
		if (empty($allowedTypes) === false && in_array($fileData['mimeType'], $allowedTypes, true) === false) {
			$allowedStr = implode(', ', $allowedTypes);
			$mimeType = $fileData['mimeType'];
			throw new Exception(
				"$errorPrefix has invalid type '$mimeType'. Allowed types: $allowedStr"
			);
		}
	}//end assertAllowedType()

	/**
	 * Refuse a file larger than the property accepts, in bytes.
	 *
	 * The editor's megabyte limit is converted, see {@see FilePropertyRules::maxSizeBytes()}.
	 *
	 * @param array<string, mixed> $fileData    The parsed file data.
	 * @param array<string, mixed> $fileConfig  The file property configuration.
	 * @param string               $errorPrefix The error message prefix.
	 *
	 * @return void
	 *
	 * @throws Exception If the file is too large.
	 */
	private function assertWithinMaxSize(array $fileData, array $fileConfig, string $errorPrefix): void {
		$maxSize = (new FilePropertyRules())->maxSizeBytes(fileConfig: $fileConfig);
		if ($maxSize > 0 && $fileData['size'] > $maxSize) {
			$fileSize = $fileData['size'];
			throw new Exception(
				"$errorPrefix exceeds maximum size ($maxSize bytes). File size: $fileSize bytes"
			);
		}
	}//end assertWithinMaxSize()

	/**
	 * Refuse a file whose filename has a dangerous executable extension.
	 *
	 * @param array<string, mixed> $fileData    The file data.
	 * @param string               $errorPrefix The error message prefix.
	 *
	 * @return void
	 *
	 * @throws Exception If the extension is executable.
	 */
	public function blockExecutableExtension(array $fileData, string $errorPrefix): void {
		if (($fileData['filename'] ?? null) === null) {
			return;
		}

		$extension = strtolower(pathinfo($fileData['filename'], PATHINFO_EXTENSION));
		if (in_array($extension, $this->dangerousExtensions, true) === false) {
			return;
		}

		$context = ['filename' => $fileData['filename'], 'extension' => $extension, 'mimeType' => $fileData['mimeType'] ?? 'unknown'];
		$this->log->write(level: 'warning', message: '[FilePropertyHandler] Executable file upload blocked', context: $context);
		throw new Exception(
			"$errorPrefix is an executable file (.$extension). Executable files are blocked for security reasons. "
			. 'Allowed formats: documents, images, archives, data files.'
		);
	}//end blockExecutableExtension()

	/**
	 * Refuse a file whose MIME type is one of the given executable MIME types.
	 *
	 * @param array<string, mixed> $fileData    The file data.
	 * @param string               $errorPrefix The error message prefix.
	 * @param array<int, string>   $mimeTypes   The executable MIME types, see executableMimeTypes().
	 *
	 * @return void
	 *
	 * @throws Exception If the MIME type is executable.
	 */
	public function blockMimeTypeIn(array $fileData, string $errorPrefix, array $mimeTypes): void {
		$mimeType = $fileData['mimeType'] ?? null;
		if ($mimeType === null || in_array($mimeType, $mimeTypes, true) === false) {
			return;
		}

		$this->log->write(level: 'warning', message: '[FilePropertyHandler] Executable MIME type blocked', context: ['mimeType' => $mimeType]);
		throw new Exception(
			"$errorPrefix has executable MIME type '$mimeType'. " . 'Executable files are blocked for security reasons.'
		);
	}//end blockExecutableMimeType()

	/**
	 * Refuse content that starts with an executable signature.
	 *
	 * @param string $content     The file content.
	 * @param string $errorPrefix The error message prefix.
	 *
	 * @return void
	 *
	 * @throws Exception If an executable signature matches.
	 */
	private function blockExecutableSignature(string $content, string $errorPrefix): void {
		$description = $this->executableDetector->matchExecutableSignature(content: $content);
		if ($description === null) {
			return;
		}

		$this->log->write(level: 'warning', message: '[FilePropertyHandler] Executable magic bytes detected', context: ['type' => $description]);
		$msg = "$errorPrefix contains executable code ($description). ";
		throw new Exception($msg . 'Executable files are blocked for security reasons.');
	}//end blockExecutableSignature()
}//end class
