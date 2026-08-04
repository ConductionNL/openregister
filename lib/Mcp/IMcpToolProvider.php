<?php

/**
 * IMcpToolProvider — per-app MCP tool registration contract.
 *
 * Apps that wish to expose MCP tools to the AI companion implement this
 * interface and register their implementation via Nextcloud's service
 * container. OpenRegister's McpToolsService enumerates every registered
 * implementation in-process per turn without issuing extra HTTP requests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

/**
 * IMcpToolProvider
 *
 * Contract for per-app MCP tool providers. Tool ids returned by getTools()
 * MUST be namespaced as `{getAppId()}.{toolName}`. McpToolsService rejects
 * any descriptor whose id prefix does not match the provider's getAppId().
 *
 * Known limitation: if a single invokeTool() call blocks for >15 s, the
 * heartbeat emitted by ChatStreamController will not fire until the call
 * returns. Implementations expected to take >15 s SHOULD split work into
 * smaller increments or emit heartbeats internally.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 */
interface IMcpToolProvider
{
    /**
     * The Nextcloud app id that owns this provider (e.g. "opencatalogi").
     *
     * Used by McpToolsService to validate the namespace prefix on each
     * returned tool descriptor id. MUST match the app's `<id>` in info.xml.
     *
     * @return string App id string, e.g. "openregister"
     */
    public function getAppId(): string;

    /**
     * Tool descriptors enumerable by McpToolsService.
     *
     * Each descriptor id MUST start with "{getAppId()}.". Descriptors that
     * fail this check are silently dropped with a warning-level log entry and
     * MUST NOT be passed to the LLM.
     *
     * `scope` and the three annotation hint keys are OPTIONAL and were missing
     * from this contract, which described a closed four-key shape. Real
     * providers have always emitted them — FlowMcpToolProvider sets `scope`,
     * AttributeToolScanner copies it off the #[McpTool] attribute, and
     * AttributeToolProvider copies every McpAnnotationValidator::HINT_KEYS
     * entry — and consumers have always read them back
     * (McpProviderBridge::getFunctions()). Because the declared shape omitted
     * them, PHPStan resolved those reads against a shape that could not
     * contain the key and reported the live `scope` forwarding as dead code
     * ("array_key_exists() with 'scope' and array{...} will always evaluate to
     * false"). The code was right and the contract was wrong.
     *
     * The hint keys below are exactly McpAnnotationValidator::HINT_KEYS, which
     * has three entries — no more.
     *
     * These keys are ADVISORY metadata only. ObjectService RBAC remains the
     * sole authoritative invoke-time gate (ADR-063), so `scope` never widens
     * access on its own.
     *
     * @return list<array{
     *   id: string,
     *   name: string,
     *   description: string,
     *   inputSchema: array,
     *   scope?: string,
     *   readOnlyHint?: bool,
     *   destructiveHint?: bool,
     *   idempotentHint?: bool
     * }> Tool descriptors
     */
    public function getTools(): array;

    /**
     * Invoke a tool by id.
     *
     * Implementations MUST check Nextcloud auth and per-object IDOR
     * boundaries before returning data — the runtime passes through the
     * current user's session unchanged. McpToolsService MUST NOT impersonate,
     * elevate, or substitute a system or service account when delegating.
     *
     * @param string               $toolId    Namespaced tool id, e.g. "opencatalogi.searchCatalogues"
     * @param array<string, mixed> $arguments JSON-decoded tool arguments
     *
     * @return array<string, mixed> JSON-encodable result
     */
    public function invokeTool(string $toolId, array $arguments): array;
}//end interface
