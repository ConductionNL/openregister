<?php

/**
 * OpenRegister Chat Stream Yield Channel
 *
 * Pure value object for forwarding streaming LLM events to the SSE controller.
 * No buffering, formatting, or filtering — those decisions belong to the controller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

/**
 * StreamYieldChannel
 *
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
 */
class StreamYieldChannel
{

    /**
     * Token event callbacks.
     *
     * @var callable[]
     */
    private array $tokenCallbacks = [];

    /**
     * Tool-call event callbacks.
     *
     * @var callable[]
     */
    private array $toolCallCallbacks = [];

    /**
     * Tool-result event callbacks.
     *
     * @var callable[]
     */
    private array $toolResultCallbacks = [];

    /**
     * Heartbeat event callbacks.
     *
     * @var callable[]
     */
    private array $heartbeatCallbacks = [];

    /**
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

    }//end emitHeartbeat()
}//end class
