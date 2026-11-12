<?php

/**
 * OpenRegister FilePropertyLog
 *
 * The log lines of file property handling.
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

use Psr\Log\LoggerInterface;

/**
 * Writes a file property log line with the file, line and app ahead of its own context.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FilePropertyLog {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log at debug level with the file, line and app in the context, ahead of the given keys.
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The context after file, line and app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function debug(string $message, array $context): void {
		$this->logger->debug(message: $message, context: self::withOrigin(context: $context));
	}//end debug()

	/**
	 * Log at info level with the file, line and app in the context, ahead of the given keys.
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The context after file, line and app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function info(string $message, array $context): void {
		$this->logger->info(message: $message, context: self::withOrigin(context: $context));
	}//end info()

	/**
	 * Log at warning level with the file, line and app in the context, ahead of the given keys.
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The context after file, line and app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function warning(string $message, array $context): void {
		$this->logger->warning(message: $message, context: self::withOrigin(context: $context));
	}//end warning()

	/**
	 * The context with the file, line and app ahead of the given keys.
	 *
	 * @param array<string, mixed> $context The context after file, line and app.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	private static function withOrigin(array $context): array {
		return ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister'] + $context;
	}//end withOrigin()

	/**
	 * Log with only the file and line as context.
	 *
	 * @param string $level   The log level: info or warning.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	public function note(string $level, string $message): void {
		$this->logger->{$level}(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end note()
}//end class
