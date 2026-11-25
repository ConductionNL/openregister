<?php

/**
 * ToolInvocationRecorderInterface: the published way for an app's own MCP tool
 * provider to write the same audit record OpenRegister's built-in providers write.
 *
 * OpenRegister's derived and attribute tool providers record every invocation
 * (read, write or refused) as one immutable, hash-chained audit-trail entry. An
 * app that ships a hand-written provider (`IMcpToolProvider::<appId>`) has no
 * way to write that entry without reaching into OpenRegister's mapper, so its
 * invocations were audited elsewhere or not at all. This contract closes that
 * gap without exposing the mapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Records one tool invocation in the hash-chained audit trail, under the
 * signed-in session's actor.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
interface ToolInvocationRecorderInterface {

	/**
	 * Record one invocation. Never throws: a failed write is logged, so the
	 * caller's own result is never lost to an audit failure.
	 *
	 * Only a SHA-256 digest of the arguments is kept, never the arguments
	 * themselves. A result with `isError: true` is recorded as a refusal with
	 * its `error` code; any other result by the `id` it carries, if any.
	 *
	 * @param string               $toolId    The full tool id, e.g. `shillinq.vat_return.submit`.
	 * @param array<string, mixed> $arguments The arguments the tool was called with.
	 * @param array<string, mixed> $result    What the tool answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function record(string $toolId, array $arguments, array $result): void;
}//end interface
