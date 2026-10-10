<?php

/**
 * Test fixture only: attributed methods exercising the optional
 * `annotations` map on `#[McpTool]` (REQ-ATTR-007). One method declares a
 * mark, one declares none (an empty map is never forwarded), and one
 * declares a nested value (rejected at scan time).
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
 *   (Requirement: REQ-ATTR-007 — A curated attribute tool forwards free-form annotations)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Fixtures;

use OCA\OpenRegister\Mcp\Attribute\McpTool;

class AnnotationFixtureService {

	#[McpTool(scope: 'create', action: 'create', reach: 'user', annotations: ['citizenIntake' => true])]
	public function fileCase(string $title): array {
		return ['title' => $title];
	}//end fileCase()

	#[McpTool(scope: 'update')]
	public function reassignCase(string $id): array {
		return ['id' => $id];
	}//end reassignCase()

	#[McpTool(annotations: ['citizenIntake' => ['nested' => true]])]
	public function badAnnotationTool(string $id): array {
		return ['id' => $id];
	}//end badAnnotationTool()
}//end class
