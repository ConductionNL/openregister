<?php

/**
 * Test fixture only — an attributed tool whose explicit `name` collides
 * with a schema-derived tool id (`{appId}.lead.search`), used to prove the
 * discovery-time collision-rejection rule (REQ-ATTR-002 "Attributed↔derived
 * id collision is a discovery-time error").
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
 * @spec openspec/changes/or-mcp-tool-attribute/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Fixtures;

use OCA\OpenRegister\Mcp\Attribute\McpTool;

/**
 * CollidingFixtureService
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Fixtures
 */
class CollidingFixtureService {

	/**
	 * Deliberately named to collide with a schema-derived `{schema}.{verb}`
	 * id (`lead.search`) once namespaced under an app id.
	 *
	 * @return array<int, mixed>
	 */
	#[McpTool(name: 'lead.search')]
	public function search(): array {
		return [];
	}//end search()
}//end class
