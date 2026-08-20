<?php

/**
 * FileValidationHandler
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
use OCA\OpenRegister\Db\FileMapper;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file validation and security operations.
 *
 * This handler is responsible for:
 * - Blocking executable files for security
 * - Detecting executable magic bytes in file content
 * - Checking and fixing file ownership issues
 * - Validating file access permissions
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileValidationHandler {
	/**
	 * Constructor for FileValidationHandler.
	 *
	 * @param FileMapper $fileMapper File mapper for ownership operations.
	 * @param IUserSession $userSession User session for user context.
	 * @param LoggerInterface $logger Logger for logging operations.
	 */
	public function __construct(
		private readonly FileMapper $fileMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Block executable files from being uploaded for security reasons.
	 *
	 * This method checks both the file extension and the file content (magic bytes)
	 * to detect executable files. If an executable file is detected, an exception
	 * is thrown to prevent the upload.
	 *
	 * @param string $fileName The name of the file to check.
	 * @param string $fileContent The content of the file to check.
	 *
	 * @return void
	 *
	 * @throws Exception If an executable file is detected.
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive list of dangerous extensions requires extensive code
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function blockExecutableFile(string $fileName, string $fileContent): void {
		// List of dangerous executable extensions.
		$dangerousExtensions = [
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

		// Check file extension.
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (in_array($extension, $dangerousExtensions, true) === true) {
			$this->logger->warning(
				message: '[FileValidationHandler] Executable file upload blocked',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'filename' => $fileName,
					'extension' => $extension,
				]
			);

			$part1 = "File '$fileName' is an executable file (.$extension). ";
			$part2 = 'Executable files are blocked for security reasons. ';
			$part3 = 'Allowed formats: documents, images, archives, data files.';
			throw new Exception($part1 . $part2 . $part3);
		}

		// Check magic bytes (file signatures) in content.
		if (empty($fileContent) === false) {
			$this->detectExecutableMagicBytes(content: $fileContent, fileName: $fileName);
		}
	}//end blockExecutableFile()

	/**
	 * Detects executable magic bytes in file content.
	 *
	 * Magic bytes are signatures at the start of files that identify the file type.
	 * This provides defense-in-depth against renamed executables.
	 *
	 * @param string $content The file content to check.
	 * @param string $fileName The filename for error messages.
	 *
	 * @return void
	 *
	 * @throws Exception If executable magic bytes are detected.
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function detectExecutableMagicBytes(string $content, string $fileName): void {
		// Common executable magic bytes.
		$magicBytes = [
			'MZ' => 'Windows executable (PE/EXE)',
			"\x7FELF" => 'Linux/Unix executable (ELF)',
			'#!/bin/sh' => 'Shell script',
			'#!/bin/bash' => 'Bash script',
			'#!/usr/bin/env' => 'Script with env shebang',
			'<?php' => 'PHP script',
			"\xCA\xFE\xBA\xBE" => 'Java class file',
		];

		foreach ($magicBytes as $signature => $description) {
			if (strpos($content, $signature) === 0) {
				$this->logger->warning(
					message: '[FileValidationHandler] Executable magic bytes detected',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'app' => 'openregister',
						'filename' => $fileName,
						'type' => $description,
					]
				);

				$execMsg = "File '$fileName' contains executable code ($description). ";
				throw new Exception($execMsg . 'Executable files are blocked for security.');
			}
		}

		// Check for script shebangs anywhere in first 4 lines.
		$firstLines = substr($content, 0, 1024);
		if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines) === 1) {
			throw new Exception(
				"File '$fileName' contains script shebang. Script files are blocked for security reasons."
			);
		}

		// Check for embedded PHP tags.
		if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines) === 1) {
			throw new Exception(
				"File '$fileName' contains PHP code. PHP files are blocked for security reasons."
			);
		}
	}//end detectExecutableMagicBytes()

	/**
	 * Assert that the current session may access the given file.
	 *
	 * Access is readability-based and ownership-agnostic. An OpenRegister object
	 * may link a file owned by the `openregister` system user, by the uploading
	 * user, or by any other user, and the current session may reach it either
	 * through direct ownership OR through a file share. `Node::isReadable()`
	 * reflects exactly that surface: a pure permission-bitmask check against
	 * `oc_filecache` for the current user's view. It does NOT read file contents
	 * and does NOT acquire a Nextcloud shared lock, so this probe is safe to run
	 * in a hot listing loop against arbitrarily large or actively-locked files.
	 * See `openspec/changes/fix-object-files-listing-lock-and-limit/design.md`
	 * Decision 1 (the prior implementation used `File::getContent()` which forced
	 * O(file-size) reads and triggered `LockedException` on every NC-locked file).
	 *
	 * Comparing the file owner against the session user is intentionally NOT done:
	 * it rejects files reachable via a share, which broke linking and viewing of
	 * files owned by users other than the session user. Write and delete paths
	 * additionally assert `isUpdateable()`/`isDeletable()` before mutating, and the
	 * underlying Nextcloud node operations enforce permissions natively, so the
	 * readability gate here does not over-grant.
	 *
	 * @param Node $file The file node to check access for.
	 *
	 * @return void
	 *
	 * @throws NotPermittedException When the file is not readable by the current session.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function checkOwnership(Node $file): void {
		$fileName = $file->getName();
		$fileId = $file->getId();

		if ($file->isReadable() === false) {
			$this->logger->warning(
				message: "[FileValidationHandler] checkOwnership: File {$fileName} (ID: {$fileId}) is not readable by current session",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			throw new NotPermittedException("File {$fileName} is not readable by the current session");
		}
	}//end checkOwnership()

	/**
	 * Set file ownership to the OpenRegister user.
	 *
	 * This method updates the file ownership in the database to the OpenRegister
	 * user to fix access permission issues.
	 *
	 * @param Node $file The file node to set ownership for.
	 *
	 * @return bool True if ownership was set successfully, false otherwise.
	 *
	 * @throws Exception If ownership update fails.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function ownFile(Node $file): bool {
		try {
			$openRegisterUser = $this->getUser();
			$userId = $openRegisterUser->getUID();
			$fileId = $file->getId();

			$this->logger->info(
				message: "[FileValidationHandler] ownFile: Setting ownership of {$file->getName()} to {$userId}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			$result = $this->fileMapper->setFileOwnership(fileId: $fileId, userId: $userId);

			if ($result === false) {
				$this->logger->warning(
					message: "[FileValidationHandler] ownFile: Failed to set ownership of {$file->getName()} to {$userId}",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);

				return $result;
			}

			$this->logger->info(
				message: "[FileValidationHandler] ownFile: Set ownership of {$file->getName()} to {$userId}",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $result;
		} catch (Exception $e) {
			$this->logger->error(
				message: "[FileValidationHandler] ownFile: Error setting ownership of {$file->getName()}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to set file ownership: ' . $e->getMessage());
		}//end try
	}//end ownFile()

	/**
	 * Get the OpenRegister user from the session.
	 *
	 * This method retrieves the current user from the session context.
	 * The OpenRegister user is used for file ownership operations.
	 *
	 * @return IUser The OpenRegister user.
	 *
	 * @throws Exception If user is not logged in.
	 *
	 * @psalm-return   IUser
	 * @phpstan-return IUser
	 */
	private function getUser(): IUser {
		$user = $this->userSession->getUser();

		if ($user === null) {
			throw new Exception('User not logged in');
		}

		return $user;
	}//end getUser()
}//end class
