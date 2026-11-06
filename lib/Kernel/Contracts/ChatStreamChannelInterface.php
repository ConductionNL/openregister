<?php

/**
 * ChatStreamChannelInterface: the channel a streaming chat turn talks through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Producers emit tokens, tool calls and tool results; consumers subscribe to them.
 */
interface ChatStreamChannelInterface {

	/**
	 * Subscribe to token deltas.
	 *
	 * @param callable $callback Receives the delta string.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function onToken(callable $callback): void;

	/**
	 * Subscribe to tool calls.
	 *
	 * @param callable $callback Receives the payload array.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function onToolCall(callable $callback): void;

	/**
	 * Subscribe to tool results.
	 *
	 * @param callable $callback Receives the payload array.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function onToolResult(callable $callback): void;

	/**
	 * Emit a token delta to every token subscriber.
	 *
	 * @param string $delta The delta text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function emitToken(string $delta): void;

	/**
	 * Emit a tool call to every tool call subscriber.
	 *
	 * @param array $payload The tool call payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function emitToolCall(array $payload): void;

	/**
	 * Emit a tool result to every tool result subscriber.
	 *
	 * @param array $payload The tool result payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function emitToolResult(array $payload): void;
}//end interface
