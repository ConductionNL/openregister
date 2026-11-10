<?php

/**
 * Test fixture only — attributed methods exercising the optional
 * `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` params on
 * `#[McpTool]` (REQ-ATTR-005 — attribute-declared hints/scope reach both
 * MCP surfaces). One method declares all four, one declares none (proving
 * omission is never defaulted), and one declares an unrecognised `scope`
 * (proving scan-time rejection).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Fixtures;

use OCA\OpenRegister\Mcp\Attribute\McpTool;

/**
 * HintScopeFixtureService
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 */
class HintScopeFixtureService {

	/**
	 * Declares all four optional hint/scope params — exercises additive
	 * forwarding into the descriptor.
	 *
	 * @param string $id The lead id to delete.
	 *
	 * @return array{id: string}
	 */
	#[McpTool(readOnlyHint: false, destructiveHint: true, idempotentHint: false, scope: 'delete')]
	public function deleteLead(string $id): array {
		return ['id' => $id];
	}//end deleteLead()

	/**
	 * Declares none of the four optional params — the descriptor MUST
	 * carry NO hint/scope keys at all (never a fabricated default).
	 *
	 * @param string $id The lead id to fetch.
	 *
	 * @return array{id: string}
	 */
	#[McpTool]
	public function getLead(string $id): array {
		return ['id' => $id];
	}//end getLead()

	/**
	 * Declares an unrecognised `scope` value — the scanner MUST reject
	 * (skip + log) this tool entirely rather than register it.
	 *
	 * @param string $id The lead id.
	 *
	 * @return array{id: string}
	 */
	#[McpTool(scope: 'wipe-everything')]
	public function badScopeLead(string $id): array {
		return ['id' => $id];
	}//end badScopeLead()
}//end class
