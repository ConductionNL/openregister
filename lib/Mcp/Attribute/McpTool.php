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
final class McpTool
{
    /**
     * Constructor.
     *
     * @param string|null $name        Local tool name; defaults to the method name when null.
     * @param string|null $description LLM-facing description; defaults to the docblock summary when null.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
     */
    public function __construct(
        public readonly ?string $name=null,
        public readonly ?string $description=null,
    ) {
    }//end __construct()
}//end class
