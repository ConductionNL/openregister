<?php

/**
 * AuditedInvocation: one MCP tool invocation the audit runner performs and records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use Closure;

/**
 * The tool arguments, the invocation itself and how a successful result is summarised.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class AuditedInvocation {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                                $arguments The tool arguments (digested, never stored).
	 * @param Closure(): array<string, mixed>                     $call      The invocation.
	 * @param Closure(array<string, mixed>): array<string, mixed> $summarise The success summary of a result.
	 */
	public function __construct(
		public readonly array $arguments,
		public readonly Closure $call,
		public readonly Closure $summarise,
	) {
	}//end __construct()
}//end class
