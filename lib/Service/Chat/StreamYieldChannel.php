<?php

/**
 * OpenRegister Chat Stream Yield Channel
 *
 * Plain value object that forwards token / tool-call / tool-result /
 * heartbeat events from `ResponseGenerationHandler` to the consuming
 * controller (`ChatStreamController`) during a streaming LLM call.
 *
 * The channel is pure forwarding: it does not buffer, format, or
 * filter events. Buffering of partial tool-call frames lives in
 * `ResponseGenerationHandler`; SSE framing + heartbeat interleaving
 * lives in `ChatStreamController`. Multiple callbacks per event type
 * are allowed (future-proofs for telemetry / logging interceptors).
 * Late registration after a prior emit is allowed; the new callback
 * only sees subsequent events (no replay).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#1
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

/**
 * StreamYieldChannel
 *
 * Request-scoped event forwarder used by the SSE chat stream. Constructed
 * by `ChatStreamController::stream()`, passed through `ChatService::processMessage`
 * into `ResponseGenerationHandler::generateResponse`, and invoked from the
 * streaming branch of that handler. Pure-PHP value object — no DI, no I/O.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 */
class StreamYieldChannel
{

    /**
     * Registered token callbacks. Each receives the new token delta as a
     * single string argument.
     *
     * @var array<int, callable>
     */
    private array $tokenCallbacks = [];

    /**
     * Registered tool-call callbacks. Each receives the assembled tool-call
     * payload (an associative array with `toolId` + `arguments`) as a single
     * argument.
     *
     * @var array<int, callable>
     */
    private array $toolCallCallbacks = [];

    /**
     * Registered tool-result callbacks. Each receives the tool-result
     * payload (an associative array with `toolId`, `result`, `isError`)
     * as a single argument.
     *
     * @var array<int, callable>
     */
    private array $toolResultCallbacks = [];

    /**
     * Registered heartbeat callbacks. Each receives no arguments — the
     * timestamp is the controller's responsibility to attach when framing
     * the SSE event.
     *
     * @var array<int, callable>
     */
    private array $heartbeatCallbacks = [];

    /**
     * Register a callback invoked for each token delta emitted by the LLM
     * stream.
     *
     * @param callable $callback Function receiving a single string argument
     *                           (the new token delta).
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic
     *              (the class is self-documented as "pure forwarding").
     */
    public function onToken(callable $callback): void
    {
        $this->tokenCallbacks[] = $callback;
    }//end onToken()

    /**
     * Register a callback invoked once per tool invocation when the LLM
     * signals `finish_reason=tool_calls`.
     *
     * @param callable $callback Function receiving the assembled tool-call
     *                           payload as a single associative-array argument.
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic.
     */
    public function onToolCall(callable $callback): void
    {
        $this->toolCallCallbacks[] = $callback;
    }//end onToolCall()

    /**
     * Register a callback invoked once per tool result after
     * `McpToolsService::callTool` returns for the matching tool call.
     *
     * @param callable $callback Function receiving the tool-result payload
     *                           as a single associative-array argument.
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic.
     */
    public function onToolResult(callable $callback): void
    {
        $this->toolResultCallbacks[] = $callback;
    }//end onToolResult()

    /**
     * Register a callback invoked when the handler emits an explicit
     * heartbeat. The pre-emit heartbeat interleaving in the controller
     * uses its own clock and does not flow through this channel.
     *
     * @param callable $callback Function invoked with no arguments.
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — registers a callback; carries no business logic.
     */
    public function onHeartbeat(callable $callback): void
    {
        $this->heartbeatCallbacks[] = $callback;
    }//end onHeartbeat()

    /**
     * Emit a token delta to every registered token callback in registration
     * order.
     *
     * @param string $delta New token delta from the LLM stream.
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — loops registered callbacks; carries no business logic.
     */
    public function emitToken(string $delta): void
    {
        foreach ($this->tokenCallbacks as $callback) {
            $callback($delta);
        }
    }//end emitToken()

    /**
     * Emit one assembled tool-call payload to every registered tool-call
     * callback in registration order.
     *
     * @param array<string, mixed> $payload Tool-call payload (`toolId`,
     *                                      `arguments`).
     *
     * @return void
     *
     * @spec openspec/specs/chat-ai/spec.md
     */
    public function emitToolCall(array $payload): void
    {
        foreach ($this->toolCallCallbacks as $callback) {
            $callback($payload);
        }
    }//end emitToolCall()

    /**
     * Emit one tool-result payload to every registered tool-result callback
     * in registration order.
     *
     * @param array<string, mixed> $payload Tool-result payload (`toolId`,
     *                                      `result`, `isError`).
     *
     * @return void
     *
     * @spec openspec/specs/chat-ai/spec.md
     */
    public function emitToolResult(array $payload): void
    {
        foreach ($this->toolResultCallbacks as $callback) {
            $callback($payload);
        }
    }//end emitToolResult()

    /**
     * Emit an explicit heartbeat to every registered heartbeat callback in
     * registration order.
     *
     * @return void
     *
     * @spec exclude Pure pub-sub forwarder plumbing — loops registered callbacks; carries no business logic.
     */
    public function emitHeartbeat(): void
    {
        foreach ($this->heartbeatCallbacks as $callback) {
            $callback();
        }
    }//end emitHeartbeat()
}//end class
