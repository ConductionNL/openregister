<?php

/**
 * OpenRegister FilePropertyParts
 *
 * The collaborators FilePropertyHandler delegates to.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Service\File\ExecutableContentDetector;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use Psr\Log\LoggerInterface;

/**
 * The file input reader, the upload guard, the stored-file operations and the configuration
 * resolver of file property handling, built over one logger.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FilePropertyParts {

	/**
	 * Reads uploads, data URIs and base64.
	 *
	 * @var FileInputReader
	 */
	public readonly FileInputReader $reader;

	/**
	 * Refuses executables, unaccepted MIME types and oversized files.
	 *
	 * @var FileUploadGuard
	 */
	public readonly FileUploadGuard $guard;

	/**
	 * Clears stored files and looks up existing ones.
	 *
	 * @var FilePropertyFiles
	 */
	public readonly FilePropertyFiles $files;

	/**
	 * Resolves a property's file configuration.
	 *
	 * @var FilePropertyConfigs
	 */
	public readonly FilePropertyConfigs $configs;

	/**
	 * Build the parts.
	 *
	 * @param LoggerInterface           $logger              Logger for logging operations.
	 * @param ObjectFilesInterface      $fileService         File service for file operations.
	 * @param ExecutableContentDetector $executableDetector  Shared executable-content detection.
	 * @param list<string>              $dangerousExtensions The executable file extensions to block.
	 */
	public function __construct(
		LoggerInterface $logger,
		ObjectFilesInterface $fileService,
		ExecutableContentDetector $executableDetector,
		array $dangerousExtensions,
	) {
		$log = new FilePropertyLog(logger: $logger);
		$this->reader = new FileInputReader(log: $log);
		$this->guard = new FileUploadGuard(log: $log, executableDetector: $executableDetector, dangerousExtensions: $dangerousExtensions);
		$this->files = new FilePropertyFiles(log: $log, fileService: $fileService);
		$this->configs = new FilePropertyConfigs(log: $log);
	}//end __construct()
}//end class
