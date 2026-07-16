<?php

/**
 * Streaming tool-instance decorator.
 *
 * Wraps an arbitrary tool instance so that every function call from
 * LLPhant fans out a `tool_call` SSE frame BEFORE the wrapped call
 * runs and a `tool_result` SSE frame AFTER it returns. LLPhant
 * invokes tools via `$instance->{$funcName}(...$args)` (see
 * `OpenAIChat::callFunction()` and the matching streaming branch in
 * `createStreamedResponse`), so the `__call` magic method is the only
 * hook we need — no LLPhant patch required.
 *
 * The wrapper is request-scoped and only used on the streaming path
 * (when `ResponseGenerationHandler::generateResponse` receives a
 * non-null `StreamYieldChannel`). The non-streaming path passes raw
 * tool instances exactly as before — load-bearing for
 * `POST /api/chat/send` semantics.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use Throwable;

/**
 * StreamingToolInstanceWrapper
 *
 * Decorator with a single responsibility: surface the LLM's tool
 * invocations on the StreamYieldChannel as they happen so the SSE
 * controller can emit `tool_call` + `tool_result` frames before the
 * stream's `final` event.
 */
class StreamingToolInstanceWrapper
{

    /**
     * The wrapped tool instance.
     *
     * @var object
     */
    private object $wrapped;

    /**
     * Channel to forward tool events through.
     *
     * @var StreamYieldChannel
     */
    private StreamYieldChannel $channel;

    /**
     * Constructor.
     *
     * @param object             $wrapped The tool instance LLPhant will call
     * @param StreamYieldChannel $channel Channel that fans frames to the SSE controller
     *
     * @return void
     */
    public function __construct(object $wrapped, StreamYieldChannel $channel)
    {
        $this->wrapped = $wrapped;
        $this->channel = $channel;

    }//end __construct()

    /**
     * Catch every method call LLPhant dispatches and emit tool_call /
     * tool_result around the forwarded invocation.
     *
     * Argument shape: LLPhant unpacks the parsed JSON arguments via
     * `(...$arguments)`. For most MCP-flavoured tools that means a
     * single array as `$args[0]`. For tools that declare positional
     * parameters explicitly, every parameter becomes its own slot in
     * `$args`. We surface the most useful form in the SSE payload —
     * the single-array case is flattened, the positional case is kept
     * verbatim — so downstream consumers don't have to know the
     * difference.
     *
     * @param string            $functionName The function LLPhant resolved
     * @param array<int, mixed> $args         Positional or single-array argument list
     *
     * @return mixed Whatever the wrapped instance returned — preserved verbatim
     *
     * @throws Throwable Re-raised after emitting an isError tool_result frame
     */
    public function __call(string $functionName, array $args): mixed
    {
        $arguments = $this->normaliseArguments(args: $args);

        $this->channel->emitToolCall(
            payload: [
                'toolId'    => $functionName,
                'arguments' => $arguments,
            ]
        );

        try {
            $result = $this->wrapped->{$functionName}(...$args);
        } catch (Throwable $e) {
            // Surface the failure on the channel before re-raising so
            // the SSE consumer sees a tool_result frame even when the
            // tool throws. Mirrors McpToolsService's catch-all envelope.
            $this->channel->emitToolResult(
                payload: [
                    'toolId'  => $functionName,
                    'result'  => ['error' => $e->getMessage()],
                    'isError' => true,
                ]
            );
            throw $e;
        }

        $resultPayload = ['value' => $result];
        if (is_array($result) === true) {
            $resultPayload = $result;
        }

        $this->channel->emitToolResult(
            payload: [
                'toolId'  => $functionName,
                'result'  => $resultPayload,
                'isError' => $this->detectIsError(result: $result),
            ]
        );

        // LLPhant's CalledFunction::__construct requires the function
        // return to be ?string. MCP tools return associative arrays —
        // serialise to JSON so LLPhant can stuff the value into the
        // assistant's follow-up message. The structured array already
        // went out on the SSE channel; this is purely for the LLM
        // round-trip.
        if (is_array($result) === true) {
            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $result;

    }//end __call()

    /**
     * Flatten the argument array when it's the standard MCP shape
     * (single associative array passed as `$args[0]`). Positional
     * argument lists pass through verbatim.
     *
     * @param array<int, mixed> $args LLPhant's `(...$args)` payload
     *
     * @return array<string|int, mixed>
     */
    private function normaliseArguments(array $args): array
    {
        if (count($args) === 1 && is_array($args[0]) === true) {
            return $args[0];
        }

        return $args;

    }//end normaliseArguments()

    /**
     * MCP tool envelopes use `isError: true` for soft failures (see
     * `McpToolsService::callTool` and every per-app provider). Detect
     * that pattern so the SSE frame's `isError` flag matches.
     *
     * @param mixed $result Tool return value
     *
     * @return bool True when the result is an MCP isError envelope
     */
    private function detectIsError(mixed $result): bool
    {
        return is_array($result) === true
            && array_key_exists(key: 'isError', array: $result) === true
            && $result['isError'] === true;

    }//end detectIsError()
}//end class
