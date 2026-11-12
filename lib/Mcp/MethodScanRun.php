<?php

/**
 * One method of a class an AttributeToolScanner pass reflects.
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

use ReflectionMethod;

/**
 * The class being scanned and the method of it the scanner is looking at.
 *
 * Built by {@see AttributeToolScanner::scanClass()} for each method and never
 * handed outside that class (ADR-011 Rule 3).
 *
 * @spec openspec/specs/ai-mcp/spec.md#requirement-reflection-scanner-registers-attributed-tools-in-the-same-catalog
 */
final class MethodScanRun {
	/**
	 * Constructor.
	 *
	 * @param ToolScanSubject  $subject The class being scanned.
	 * @param ReflectionMethod $method  The method being looked at.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public readonly ToolScanSubject $subject,
		public readonly ReflectionMethod $method,
	) {
	}//end __construct()

	/**
	 * Log a warning about this method; the context starts with the app id, class and method name.
	 *
	 * @param string               $message The warning.
	 * @param array<string, mixed> $context Further context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function warn(string $message, array $context = []): void {
		$this->subject->warn(message: $message, context: ['method' => $this->method->getName()] + $context);
	}//end warn()
}//end class
