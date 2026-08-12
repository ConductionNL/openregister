<?php

/**
 * #[McpTool] service-method attribute (ADR-063 chain 3/3).
 *
 * Net-new PHP attribute, php-mcp/server style: a developer marks a public
 * service method with `#[McpTool]` to expose it as an MCP tool without
 * writing a per-app IMcpToolProvider or a hand-written descriptor/dispatch.
 * OpenRegister's {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} discovers
 * attributed methods on an app's declared scannable service classes and
 * registers one tool per method, id `{appId}.{toolName}`, into the SAME
 * catalog as built-in and schema-derived tools.
 *
 * Both `name` and `description` are optional:
 * - `name` defaults to the method name.
 * - `description` defaults to the method's docblock summary line.
 *
 * The tool's `inputSchema`/`outputSchema` are NOT declared here — they are
 * inferred by the scanner from the method's parameter type hints + docblock
 * `@param` tags (input) and return type + `@return` (output, best-effort).
 *
 * Optional MCP 2025-11-25 annotation hints (`readOnlyHint`, `destructiveHint`,
 * `idempotentHint`) and an optional `scope` may also be declared. These reuse
 * the SAME vocabulary the `x-openregister-mcp` schema dialect already
 * canonicalised for schema-derived tools ({@see
 * \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::HINT_KEYS},
 * {@see \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::SCOPES}) — no
 * parallel vocabulary is introduced. Every one of the four defaults to
 * `null`/omitted; {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} forwards
 * only what the author actually set, never a fabricated default, into the
 * tool descriptor on BOTH the JSON-RPC and chat/facade serving surfaces
 * (REQ-ATTR-005). They are ADVISORY UX metadata only: OpenRegister RBAC and
 * the owning method's own authorization remain the sole authoritative
 * invoke-time gate (ADR-063) — no hint or scope value changes invocation
 * behaviour.
 *
 * RBAC contract: the attributed method is invoked in-process, in the
 * caller's ambient Nextcloud session (ADR-041 — no cross-app RPC). The
 * method itself is responsible for its own authorization/IDOR checks;
 * OpenRegister never impersonates or elevates the acting principal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Attribute
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
 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Attribute;

use Attribute;

/**
 * McpTool
 *
 * Method-targeting attribute marking a public service method for MCP
 * discovery. See class docblock for the full contract.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Attribute
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class McpTool {
	/**
	 * Constructor.
	 *
	 * @param string|null $name Local tool name; defaults to the method name when null.
	 * @param string|null $description LLM-facing description; defaults to the docblock summary when null.
	 * @param bool|null $readOnlyHint Optional MCP 2025-11-25 annotation hint; one of
	 *                                {@see \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::HINT_KEYS}.
	 *                                Omitted (null) when the author does not declare it — never inferred.
	 * @param bool|null $destructiveHint Optional MCP 2025-11-25 annotation hint (see `$readOnlyHint`).
	 * @param bool|null $idempotentHint Optional MCP 2025-11-25 annotation hint (see `$readOnlyHint`).
	 * @param string|null $scope Optional advisory scope; when set, MUST be one of
	 *                           {@see \OCA\OpenRegister\Service\Mcp\McpAnnotationValidator::SCOPES}
	 *                           (validated by {@see \OCA\OpenRegister\Mcp\AttributeToolScanner} at
	 *                           scan time, not here). Omitted (null) when not declared.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
	 */
	public function __construct(
		public readonly ?string $name = null,
		public readonly ?string $description = null,
		public readonly ?bool $readOnlyHint = null,
		public readonly ?bool $destructiveHint = null,
		public readonly ?bool $idempotentHint = null,
		public readonly ?string $scope = null,
	) {
	}//end __construct()
}//end class
