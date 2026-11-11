<?php

/**
 * Golden test for McpScope::strip().
 *
 * Pins the stripped block whole: the mcp scope gone from every rule list in
 * both spellings and inside the deny block, while toggles and array-shaped
 * control keys pass through untouched, keys and order kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\McpScope;
use PHPUnit\Framework\TestCase;

/**
 * The stripped block, pinned.
 */
class McpScopeStripGoldenTest extends TestCase {

	public function testTheScopeLeavesEveryRuleListAndNothingElseChanges(): void {
		$block = [
			'public' => true,
			'read' => ['mcp', 'behandelaars', ['group' => 'mcp'], ['group' => 'hr']],
			'roles' => ['beheerder' => ['mcp']],
			'matrix' => ['field' => 'department', 'rows' => [['group' => 'mcp']]],
			'deny' => ['delete' => ['mcp', 'stagiairs']],
			'update' => ['mcp'],
		];

		$this->assertSame(
			McpScope::strip(authorization: $block),
			McpScope::strip(authorization: McpScope::strip(authorization: $block)),
			'stripping is idempotent'
		);

		$this->assertSame(
			[
				'public' => true,
				'read' => ['behandelaars', ['group' => 'hr']],
				'roles' => ['beheerder' => ['mcp']],
				'matrix' => ['field' => 'department', 'rows' => [['group' => 'mcp']]],
				'deny' => ['delete' => ['stagiairs']],
				'update' => [],
			],
			McpScope::strip(authorization: $block)
		);

		$this->assertNull(McpScope::strip(authorization: null));
		$this->assertSame([], McpScope::strip(authorization: []));
	}//end testTheScopeLeavesEveryRuleListAndNothingElseChanges()
}//end class
