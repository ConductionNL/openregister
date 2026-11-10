<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

/**
 * The audited outcome of one tool invocation: the digest of its arguments,
 * the structured summary of what happened and, on success, its result.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class ToolInvocationOutcome {

	/**
	 * Constructor.
	 *
	 * @param string                    $paramsDigest  SHA-256 hex digest of the arguments.
	 * @param array<string, mixed>      $resultSummary Structured outcome summary.
	 * @param array<string, mixed>|null $result        The result, or null on failure.
	 */
	public function __construct(
		public readonly string $paramsDigest,
		public readonly array $resultSummary,
		public readonly ?array $result,
	) {
	}//end __construct()

	/**
	 * The id of the object the result names, or null on failure or without an id.
	 *
	 * @return string|null The object id.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function objectUuid(): ?string {
		return ToolInvocationDigest::resultId(result: $this->result);
	}//end objectUuid()
}//end class
