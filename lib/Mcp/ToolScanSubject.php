<?php

/**
 * The class one AttributeToolScanner pass reflects, with the logger its warnings go to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use Psr\Log\LoggerInterface;

/**
 * The app id and class name of a scan, and the logger its warnings go to.
 */
final class ToolScanSubject {
	/**
	 * Constructor.
	 *
	 * @param string          $appId     The owning app id.
	 * @param string          $className The FQCN being reflected.
	 * @param LoggerInterface $logger    PSR logger.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $className,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log a warning about this class; the context starts with its app id and class name.
	 *
	 * @param string               $message The warning.
	 * @param array<string, mixed> $context Further context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function warn(string $message, array $context = []): void {
		$this->logger->warning($message, ['appId' => $this->appId, 'class' => $this->className] + $context);
	}//end warn()
}//end class
