<?php

/**
 * OpenRegister Claude CLI Chat Client
 *
 * Local-dev chat provider that delegates to the `claude -p` CLI via the
 * tools/dev-claude-bridge/server.js HTTP wrapper running on the WSL host.
 *
 * Mirrors enough of LLPhant's OllamaChat surface for the chat orchestrator
 * to drive it through the same code path. Tool-use is NOT supported in this
 * v1 — Claude's tool-use protocol differs enough from Ollama's that wiring
 * the MCP tools through would be a separate effort (see
 * tools/dev-claude-bridge/README.md).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

use Exception;
use GuzzleHttp\Client;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message as LLPhantMessage;
use Psr\Log\LoggerInterface;

/**
 * Talks to the host-side claude-bridge over HTTP.
 *
 * Bridge endpoint: POST {url}/api/chat with Ollama-shaped body.
 * Returns the assistant response as plain text.
 */
class ClaudeCliChat
{
    private Client $client;
    private string $model;
    private string $baseUrl;
    private float $temperature;
    private ?LoggerInterface $logger;
    private int $timeoutSeconds;

    /**
     * @param string               $baseUrl        Bridge base URL (e.g. http://host.docker.internal:11500)
     * @param string               $model          Claude model alias (sonnet|opus|haiku) or full id
     * @param float                $temperature    Sampling temperature (forwarded; bridge may ignore)
     * @param int                  $timeoutSeconds HTTP timeout. CLI bootstrap can be 10s on a fresh bridge;
     *                                             warm requests are 1-3s. 120s leaves headroom.
     * @param LoggerInterface|null $logger         Optional logger
     */
    public function __construct(
        string $baseUrl,
        string $model='sonnet',
        float $temperature=0.7,
        int $timeoutSeconds=120,
        ?LoggerInterface $logger=null
    ) {
        $this->baseUrl        = rtrim($baseUrl, '/');
        $this->model          = $model;
        $this->temperature    = $temperature;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->logger         = $logger;
        $this->client         = new Client([
            'base_uri' => $this->baseUrl.'/',
            'timeout'  => $this->timeoutSeconds,
        ]);
    }

    /**
     * No-op for now — Claude tool-use protocol is not wired through the
     * bridge yet. Defined so the orchestrator can call setTools() without
     * branching on provider type.
     *
     * @param array $tools Tool definitions (ignored)
     */
    public function setTools(array $tools): void
    {
        if (empty($tools) === false && $this->logger !== null) {
            $this->logger->info(
                '[ClaudeCliChat] Tools provided but ignored — bridge does not yet support tool-use',
                ['count' => count($tools)]
            );
        }
    }

    /**
     * Send a message history to the bridge and return the assistant text.
     *
     * If a $channel is supplied, any tool_call / tool_result events the
     * bridge reports get emitted through it AFTER the HTTP round-trip
     * returns. That's a deliberate trade-off vs Ollama's path: there,
     * StreamingToolInstanceWrapper fires the channel inline during
     * function invocation; here, Claude's MCP client invokes tools
     * inside the persistent subprocess and we only see them at the end.
     * The UI therefore renders the tool chips together with the final
     * assistant bubble rather than progressively — still informative,
     * just not live.
     *
     * @param LLPhantMessage[]|array  $messageHistory
     * @param StreamYieldChannel|null $channel Optional streaming channel
     *                                          for tool_call / tool_result
     *                                          frames.
     *
     * @return string Assistant content
     *
     * @throws Exception On HTTP/bridge failure
     */
    public function generateChat(array $messageHistory, ?StreamYieldChannel $channel=null): string
    {
        $payload = [
            'model'       => $this->model,
            'temperature' => $this->temperature,
            'messages'    => array_values(array_map([self::class, 'serialiseMessage'], $messageHistory)),
            'stream'      => false,
        ];

        try {
            $response = $this->client->post('api/chat', [
                'json'    => $payload,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            throw new Exception('claude-bridge call failed: '.$e->getMessage(), 502, $e);
        }

        $body = (string) $response->getBody();
        $json = json_decode($body, true);
        if (is_array($json) === false) {
            throw new Exception('claude-bridge returned non-JSON: '.substr($body, 0, 200));
        }
        if (isset($json['error']) === true) {
            throw new Exception('claude-bridge error: '.$json['error']);
        }

        if ($channel !== null) {
            $this->emitToolEvents(channel: $channel, body: $json);
        }

        return (string) ($json['message']['content'] ?? '');
    }

    /**
     * Translate the bridge's tool_calls / tool_results arrays into channel
     * emissions matching the shape StreamingToolInstanceWrapper produces
     * for Ollama, so the SSE controller + UI render them identically.
     *
     * Bridge wire format:
     *   tool_calls:   [{id, name: 'mcp__openregister__<slug>', arguments}]
     *   tool_results: [{id, name, isError, content: [{type:'text', text}]}]
     *
     * Channel expects:
     *   tool_call:   {toolId, arguments}
     *   tool_result: {toolId, result, isError}
     */
    private function emitToolEvents(StreamYieldChannel $channel, array $body): void
    {
        $resultsById = [];
        foreach (($body['tool_results'] ?? []) as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id !== '') {
                $resultsById[$id] = $r;
            }
        }

        foreach (($body['tool_calls'] ?? []) as $call) {
            $rawName  = (string) ($call['name'] ?? '');
            $toolId   = self::stripMcpPrefix(name: $rawName);
            $args     = (array) ($call['arguments'] ?? []);
            $channel->emitToolCall(payload: [
                'toolId'    => $toolId,
                'arguments' => $args,
            ]);

            $callId = (string) ($call['id'] ?? '');
            $r      = $resultsById[$callId] ?? null;
            if ($r === null) {
                continue;
            }
            $channel->emitToolResult(payload: [
                'toolId'  => $toolId,
                'result'  => self::extractResultPayload(content: $r['content'] ?? null),
                'isError' => (bool) ($r['isError'] ?? false),
            ]);
        }
    }

    /**
     * `mcp__openregister__decidesk_listRecentMeetings`
     *   → `decidesk_listRecentMeetings`
     *   → keep as-is (UI matches what Ollama emits via wrapper).
     *
     * Tools without the prefix pass through.
     */
    private static function stripMcpPrefix(string $name): string
    {
        $prefix = 'mcp__openregister__';
        if (str_starts_with($name, $prefix) === true) {
            return substr($name, strlen($prefix));
        }
        return $name;
    }

    /**
     * MCP responses wrap the tool payload in a content array of
     * `{type: 'text', text: '<json string>'}` entries. Unpack the first
     * text block and JSON-decode it so the UI's tool-result drawer can
     * render the structured result, not a string-of-JSON.
     *
     * Falls back to the raw value when the shape is unexpected.
     *
     * @param mixed $content
     */
    private static function extractResultPayload($content): array
    {
        if (is_array($content) === true && isset($content[0]['text']) === true) {
            $decoded = json_decode((string) $content[0]['text'], true);
            if (is_array($decoded) === true) {
                return $decoded;
            }
            return ['value' => $content[0]['text']];
        }
        if (is_array($content) === true) {
            return $content;
        }
        return ['value' => $content];
    }

    /**
     * Flatten an LLPhantMessage (or array form) into the wire shape the
     * bridge expects: {role: 'system'|'user'|'assistant', content: '...'}
     *
     * @param LLPhantMessage|array|string $message
     */
    private static function serialiseMessage($message): array
    {
        if ($message instanceof LLPhantMessage) {
            $role = $message->role instanceof ChatRole
                ? strtolower($message->role->value)
                : (string) $message->role;
            return [
                'role'    => $role,
                'content' => (string) $message->content,
            ];
        }
        if (is_array($message) === true) {
            $role = $message['role'] ?? '';
            if (is_array($role) === true) {
                $role = strtolower((string) ($role['value'] ?? $role['name'] ?? ''));
            }
            return [
                'role'    => (string) $role,
                'content' => (string) ($message['content'] ?? ''),
            ];
        }
        return ['role' => 'user', 'content' => (string) $message];
    }
}
