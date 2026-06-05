<?php

/**
 * OpenRegister Chat Stream Yield Channel
 *
<<<<<<< HEAD
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
=======
 * Pure value object for forwarding streaming LLM events to the SSE controller.
 * No buffering, formatting, or filtering — those decisions belong to the controller.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
<<<<<<< HEAD
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
=======
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

/**
 * StreamYieldChannel
 *
<<<<<<< HEAD
 * Request-scoped event forwarder used by the SSE chat stream. Constructed
 * by `ChatStreamController::stream()`, passed through `ChatService::processMessage`
 * into `ResponseGenerationHandler::generateResponse`, and invoked from the
 * streaming branch of that handler. Pure-PHP value object — no DI, no I/O.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
=======
 * Request-scoped value object connecting the LLM handler to the SSE controller.
 * Multiple callbacks per event type are allowed. Late registration after a prior
 * emit is allowed — late callbacks only see subsequent events (no replay).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */
class StreamYieldChannel
{

    /**
<<<<<<< HEAD
     * Registered token callbacks. Each receives the new token delta as a
     * single string argument.
     *
     * @var array<int, callable>
=======
     * Token event callbacks.
     *
     * @var callable[]
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private array $tokenCallbacks = [];

    /**
<<<<<<< HEAD
     * Registered tool-call callbacks. Each receives the assembled tool-call
     * payload (an associative array with `toolId` + `arguments`) as a single
     * argument.
     *
     * @var array<int, callable>
=======
     * Tool-call event callbacks.
     *
     * @var callable[]
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private array $toolCallCallbacks = [];

    /**
<<<<<<< HEAD
     * Registered tool-result callbacks. Each receives the tool-result
     * payload (an associative array with `toolId`, `result`, `isError`)
     * as a single argument.
     *
     * @var array<int, callable>
=======
     * Tool-result event callbacks.
     *
     * @var callable[]
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private array $toolResultCallbacks = [];

    /**
<<<<<<< HEAD
     * Registered heartbeat callbacks. Each receives no arguments — the
     * timestamp is the controller's responsibility to attach when framing
     * the SSE event.
     *
     * @var array<int, callable>
=======
     * Heartbeat event callbacks.
     *
     * @var callable[]
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private array $heartbeatCallbacks = [];

    /**
<<<<<<< HEAD
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
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
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-9
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
=======
     * Register a callback for token events.
     *
     * @param callable $fn Receives string $delta.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function onToken(callable $fn): void
    {
        $this->tokenCallbacks[] = $fn;

    }//end onToken()

    /**
     * Register a callback for tool_call events.
     *
     * @param callable $fn Receives array{toolId: string, arguments: array}.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function onToolCall(callable $fn): void
    {
        $this->toolCallCallbacks[] = $fn;

    }//end onToolCall()

    /**
     * Register a callback for tool_result events.
     *
     * @param callable $fn Receives array{toolId: string, result: mixed, isError: bool}.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function onToolResult(callable $fn): void
    {
        $this->toolResultCallbacks[] = $fn;

    }//end onToolResult()

    /**
     * Register a callback for heartbeat events.
     *
     * @param callable $fn Receives no arguments.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function onHeartbeat(callable $fn): void
    {
        $this->heartbeatCallbacks[] = $fn;

    }//end onHeartbeat()

    /**
     * Emit a token event to all registered callbacks.
     *
     * @param string $delta Token chunk.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function emitToken(string $delta): void
    {
        foreach ($this->tokenCallbacks as $fn) {
            $fn($delta);
        }

    }//end emitToken()

    /**
     * Emit a tool_call event to all registered callbacks.
     *
     * @param array $data Tool call data with toolId and arguments.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function emitToolCall(array $data): void
    {
        foreach ($this->toolCallCallbacks as $fn) {
            $fn($data);
        }

    }//end emitToolCall()

    /**
     * Emit a tool_result event to all registered callbacks.
     *
     * @param array $data Tool result data with toolId, result, and isError.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function emitToolResult(array $data): void
    {
        foreach ($this->toolResultCallbacks as $fn) {
            $fn($data);
        }

    }//end emitToolResult()

    /**
     * Emit a heartbeat event to all registered callbacks.
     *
     * @return void
     *
     * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1.1
     */
    public function emitHeartbeat(): void
    {
        foreach ($this->heartbeatCallbacks as $fn) {
            $fn();
        }

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    }//end emitHeartbeat()
}//end class
