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
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * AttributeToolProvider
 *
 * Built-in IMcpToolProvider that serves `#[McpTool]`-attributed service
 * methods for one owning app. See class docblock for the full contract.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Instantiated by McpProviderDiscovery::register()
 */
class AttributeToolProvider implements IMcpToolProvider {
	/**
	 * The declaring app's translations, for tool titles; null leaves them as declared.
	 *
	 * @var IL10N|null
	 */
	private ?IL10N $l10n = null;

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
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly array $entries,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * This provider translating its tool titles through the declaring app's IL10N.
	 *
	 * An app container that offers no IL10N leaves the titles as declared.
	 *
	 * @param ContainerInterface $appContainer The declaring app's own container.
	 *
	 * @return self The provider.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function translatedBy(ContainerInterface $appContainer): self {
		if ($appContainer->has(IL10N::class) === false) {
			return $this;
		}

		$translated = clone $this;
		$translated->l10n = $appContainer->get(IL10N::class);

		return $translated;
	}//end translatedBy()

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

			// 🔴 This method REBUILDS the descriptor from a fixed key list
			// rather than forwarding the entry, so any key not named here is
			// silently dropped. That is why `subject`/`action` declared on
			// `#[McpTool]` reached the scanner and then vanished before the
			// grant matrix: the attribute, the scanner and the bridge all
			// carried them, and this one copy step did not.
			// `annotations` (REQ-ATTR-007) rides the same list for the same reason.
			foreach (['subject', 'action', 'reach', 'annotations'] as $taxonomyKey) {
				if (array_key_exists($taxonomyKey, $entry) === true) {
					$descriptor[$taxonomyKey] = $entry[$taxonomyKey];
				}
			}

			$tools[] = ($descriptor + $this->titleOf(entry: $entry));
		}//end foreach

		return $tools;
	}//end getTools()

	/**
	 * The entry's declared title in the caller's language, as `['title' => ...]`, or an empty array.
	 *
	 * Translated through the declaring app's IL10N when the provider has one
	 * (translatedBy()); the declared text otherwise.
	 *
	 * @param array<string, mixed> $entry The scanned entry.
	 *
	 * @return array{title?: string} The title only.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	private function titleOf(array $entry): array {
		if (is_string($entry['title'] ?? null) === false) {
			return [];
		}

		if ($this->l10n !== null) {
			return ['title' => $this->l10n->t($entry['title'])];
		}

		return ['title' => $entry['title']];
	}//end titleOf()

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

		$callArguments = array_intersect_key($arguments, array_flip($entry['paramNames']));

		return $this->audit()->run(
			target: new ToolAuditTarget(toolId: $toolId),
			arguments: $arguments,
			call: fn (): array => $this->callEntry(entry: $entry, callArguments: $callArguments),
			summarise: static fn (array $result): array => ToolInvocationDigest::idSummary(result: $result)
		);
	}//end invokeTool()

	/**
	 * Call the entry's method on the owning app's own service instance.
	 *
	 * In-process call on the owning app's own, already-DI-resolved service
	 * instance — no HTTP, no RPC, no re-implementation (ADR-041).
	 * Named-argument unpacking maps JSON keys straight onto the method's
	 * declared parameters; the method itself is solely responsible for
	 * authorization/IDOR (REQ-ATTR-003). A scalar return is wrapped as
	 * `{result: value}`.
	 *
	 * @param array<string, mixed> $entry         The tool entry.
	 * @param array<string, mixed> $callArguments The arguments the method declares.
	 *
	 * @return array<string, mixed> The method's result as an array.
	 */
	private function callEntry(array $entry, array $callArguments): array {
		$result = $entry['instance']->{$entry['method']}(...$callArguments);
		if (is_array($result) === false) {
			$result = ['result' => $result];
		}

		return $result;
	}//end callEntry()

	/**
	 * The audit runner of this provider.
	 *
	 * @return ToolInvocationAudit The runner.
	 */
	private function audit(): ToolInvocationAudit {
		return new ToolInvocationAudit(auditTrailMapper: $this->auditTrailMapper, logger: $this->logger, source: 'AttributeToolProvider');
	}//end audit()

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

}//end class
