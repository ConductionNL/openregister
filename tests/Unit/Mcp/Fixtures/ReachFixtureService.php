<?php

/**
 * Test fixture only: attributed methods exercising the optional `reach`
 * param on `#[McpTool]` (REQ-ATTR-006). One method declares a reach, one
 * declares none (omission is never defaulted), and one declares a value
 * outside the closed vocabulary (rejected at scan time).
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
 *   (Requirement: REQ-ATTR-006 — A curated attribute tool declares its reach)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Fixtures;

use OCA\OpenRegister\Mcp\Attribute\McpTool;

class ReachFixtureService {

	#[McpTool(readOnlyHint: true, scope: 'read', reach: 'user')]
	public function getWorkload(string $userId): array {
		return ['userId' => $userId];
	}//end getWorkload()

	#[McpTool(scope: 'update')]
	public function reassignCase(string $id): array {
		return ['id' => $id];
	}//end reassignCase()

	#[McpTool(reach: 'everyone')]
	public function badReachTool(string $id): array {
		return ['id' => $id];
	}//end badReachTool()
}//end class
