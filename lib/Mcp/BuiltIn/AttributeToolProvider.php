<?php

/**
 * Attribute-derived MCP tool provider (ADR-063 chain 3/3).
 *
 * Thin `IMcpToolProvider`-shaped adapter around the descriptors
 * {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} builds from one app's
 * `#[McpTool]`-attributed service methods. One instance is registered per
 * OWNING app (mirrors {@see SchemaDerivedToolProvider}) so every emitted
 * tool id `{appId}.{toolName}` satisfies the IMcpToolProvider ABI's
 * "id prefix == getAppId()" invariant, and so the SAME dual-surface
 * registration path (`McpToolsService` JSON-RPC + `ToolRegistry`/
 * `McpProviderBridge` chat) that built-in and schema-derived tools already
 * use also carries attributed tools — no new serving code.
 *
 * In-process invocation (ADR-041 — no cross-app RPC): each descriptor
 * carries the already-DI-resolved owning service instance (resolved by
 * Application.php's discovery step from that app's own container binding)
 * and the method name; `invokeTool()` calls
 * `$instance->{$method}(...$namedArguments)` directly, in this same PHP
 * process, in the caller's ambient Nextcloud session. There is no HTTP
 * call, no message bus, and no OpenRegister-side re-implementation of the
 * method — OpenRegister is the registry/catalog and the blessed inbound
 * door, the app's own method runs and owns its own authorization/IDOR
 * (REQ-ATTR-003). This class deliberately does nothing to establish,
 * switch, or elevate a session/user — the resolved instance already runs
 * in the ambient request's DI scope.
 *
 * Every invocation — read, write, or failed — writes exactly one
 * immutable, hash-chained audit record via
 * `AuditTrailMapper::createToolInvocationEntry()`, identical to the derived
 * provider's audit contract (REQ-ATTR-004).
 *
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AttributeToolProvider
 *
 * Built-in IMcpToolProvider that serves `#[McpTool]`-attributed service
 * methods for one owning app. See class docblock for the full contract.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Instantiated by Application::registerMcpToolProviders()
 */
class AttributeToolProvider implements IMcpToolProvider {
	/**
	 * Constructor.
	 *
	 * `$entries` is a `list<array{id: string, name: string, description: string,
	 * inputSchema: array, outputSchema?: array, instance: object, method: string,
	 * paramNames: list<string>, readOnlyHint?: bool, destructiveHint?: bool,
	 * idempotentHint?: bool, scope?: string}>` — one entry per attributed
	 * method already resolved to a live service instance (ADR-041 in-process
	 * invocation). The optional hint/scope keys are set only when
	 * {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} forwarded them from
	 * the `#[McpTool]` declaration (REQ-ATTR-005).
	 *
	 * @param string $appId The owning app id; `getAppId()` and every id prefix.
	 * @param array $entries Resolved attributed-tool entries (see above).
	 * @param AuditTrailMapper $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly array $entries,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns the owning app id.
	 *
	 * @return string The owning app id this instance was constructed for.
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * Returns one tool descriptor per attributed method.
	 *
	 * Additively forwards the MCP 2025-11-25 annotation hints
	 * (`readOnlyHint`/`destructiveHint`/`idempotentHint`) and `scope` when
	 * {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} set them on the
	 * entry — omitted entirely otherwise, never defaulted (REQ-ATTR-005).
	 * These are ADVISORY UX metadata only; OpenRegister RBAC and the owning
	 * method's own authorization remain the sole authoritative invoke-time
	 * gate (ADR-063).
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, readOnlyHint?: bool, destructiveHint?: bool,
	 *         idempotentHint?: bool, scope?: string}>
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-002 — Attributed method becomes a catalog tool on both surfaces)
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
	 */
	public function getTools(): array {
		$tools = [];

		foreach ($this->entries as $entry) {
			$descriptor = [
				'id' => $entry['id'],
				'name' => $entry['name'],
				'description' => $entry['description'],
				'inputSchema' => $entry['inputSchema'],
			];

			if (isset($entry['outputSchema']) === true) {
				$descriptor['outputSchema'] = $entry['outputSchema'];
			}

			foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
				if (array_key_exists($hintKey, $entry) === true) {
					$descriptor[$hintKey] = $entry[$hintKey];
				}
			}

			if (array_key_exists('scope', $entry) === true) {
				$descriptor['scope'] = $entry['scope'];
			}

			$tools[] = $descriptor;
		}//end foreach

		return $tools;
	}//end getTools()

	/**
	 * Invoke an attributed tool by its `{appId}.{toolName}` id — an
	 * in-process call to the owning app's own service method (ADR-041).
	 *
	 * Writes exactly one immutable audit record per invocation — success or
	 * failure — before returning (or rethrowing). Does NOT catch and
	 * suppress an authorization failure raised by the owning method: it
	 * propagates unchanged (audited, then rethrown), exactly mirroring
	 * {@see SchemaDerivedToolProvider::invokeTool()}'s "no bypass" contract.
	 *
	 * @param string $toolId Namespaced tool id, e.g. "pipelinq.createLead".
	 * @param array<string, mixed> $arguments JSON-decoded tool arguments.
	 *
	 * @return array<string, mixed> JSON-encodable result.
	 *
	 * @throws InvalidArgumentException If the tool id is unknown.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-003 — Invocation is a direct in-process method call)
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-004 — Attributed-tool invocations obey the same audit + RBAC rules as derived tools)
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$entry = $this->findEntry(toolId: $toolId);
		if ($entry === null) {
			throw new InvalidArgumentException('Unknown tool: ' . $toolId);
		}

		$paramsDigest = hash('sha256', $this->canonicalArguments(arguments: $arguments));
		$callArguments = array_intersect_key($arguments, array_flip($entry['paramNames']));

		try {
			// In-process call on the owning app's own, already-DI-resolved
			// service instance — no HTTP, no RPC, no re-implementation
			// (ADR-041). Named-argument unpacking maps JSON keys straight
			// onto the method's declared parameters; the method itself is
			// solely responsible for authorization/IDOR (REQ-ATTR-003).
			$result = $entry['instance']->{$entry['method']}(...$callArguments);

			$resultArray = $result;
			if (is_array($resultArray) === false) {
				$resultArray = ['result' => $resultArray];
			}

			$this->writeAudit(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: $this->resultSummary(result: $resultArray),
				result: $resultArray
			);

			return $resultArray;
		} catch (Throwable $e) {
			$this->writeAudit(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: [
					'isError' => true,
					'errorClass' => get_class($e),
					'message' => substr($e->getMessage(), 0, 300),
				],
				result: null
			);

			throw $e;
		}//end try
	}//end invokeTool()

	/**
	 * Resolve a namespaced tool id back to its entry.
	 *
	 * @param string $toolId Namespaced tool id, e.g. "pipelinq.createLead".
	 *
	 * @return array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, instance: object, method: string, paramNames: list<string>}|null
	 */
	private function findEntry(string $toolId): ?array {
		foreach ($this->entries as $entry) {
			if ($entry['id'] === $toolId) {
				return $entry;
			}
		}

		return null;
	}//end findEntry()

	/**
	 * Build a small structured outcome summary for a successful invocation
	 * (never raw method-return payloads — REQ-ATTR-004).
	 *
	 * @param array<string, mixed> $result The method's (array-coerced) return value.
	 *
	 * @return array<string, mixed> The result summary.
	 */
	private function resultSummary(array $result): array {
		$id = null;
		if (is_string($result['id'] ?? null) === true) {
			$id = $result['id'];
		}

		return [
			'isError' => false,
			'id' => $id,
		];
	}//end resultSummary()

	/**
	 * Write exactly one immutable, hash-chained audit record for this
	 * invocation. Fail-soft: a broken audit write is logged and MUST NOT
	 * mask (or replace) the invocation's real result/error, mirroring
	 * {@see SchemaDerivedToolProvider::writeAudit()}.
	 *
	 * @param string $toolId Full namespaced tool id.
	 * @param string $paramsDigest SHA-256 hex digest of the invocation arguments.
	 * @param array<string, mixed> $resultSummary Structured outcome summary.
	 * @param array<string, mixed>|null $result The method's return value, or null on failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-004 — Attributed invocation is audited identically to a derived invocation)
	 */
	private function writeAudit(string $toolId, string $paramsDigest, array $resultSummary, ?array $result): void {
		try {
			$objectUuid = null;
			if ($result !== null && is_string($result['id'] ?? null) === true) {
				$objectUuid = $result['id'];
			}

			$this->auditTrailMapper->createToolInvocationEntry(
				toolId: $toolId,
				paramsDigest: $paramsDigest,
				resultSummary: $resultSummary,
				register: null,
				schema: null,
				objectUuid: $objectUuid
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[AttributeToolProvider] Failed to write invocation audit record',
				['toolId' => $toolId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end writeAudit()

	/**
	 * Deterministically canonicalise arguments (recursive key-sort) so the
	 * params digest is stable regardless of argument-array key order.
	 * Duplicated from {@see SchemaDerivedToolProvider} — no shared trait
	 * exists yet for this small helper (see design.md audit-parity note).
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 *
	 * @return string Canonical JSON representation.
	 */
	private function canonicalArguments(array $arguments): string {
		$encoded = json_encode(
			$this->deepKsort(value: $arguments),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}//end canonicalArguments()

	/**
	 * Recursively sort an array by key for deterministic serialisation.
	 *
	 * @param array<array-key, mixed> $value The array to sort.
	 *
	 * @return array<array-key, mixed> The sorted array.
	 */
	private function deepKsort(array $value): array {
		ksort($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->deepKsort(value: $item);
			}
		}

		return $value;
	}//end deepKsort()
}//end class
