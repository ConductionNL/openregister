<?php

/**
 * Golden test for the exact findings AuthorizationDenyValidator reports.
 *
 * Pins every sentence and their order for refused blocks (grant-and-deny
 * collisions, an orphaned manage) and the empty list for allowed ones, so the
 * two checks can be split without changing what an administrator is told or
 * which blocks are stored.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused deny blocks, every sentence pinned.
 */
class AuthorizationDenyFindingsGoldenTest extends TestCase {

	/**
	 * The findings for one block.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return array<int, string> The findings.
	 */
	private static function findings(array $block): array {
		return (new AuthorizationDenyValidator(new DenyResolver(new DenyEntryMatcher())))->findings($block, 'the schema "zaak"');
	}//end findings()

	/**
	 * The collision sentence.
	 *
	 * @param string $action    The verb.
	 * @param string $principal The principal.
	 *
	 * @return string The sentence.
	 */
	private static function collision(string $action, string $principal): string {
		return 'The authorization of the schema "zaak" both grants and denies "' . $action . '" to "' . $principal . '" at the same level: '
			. 'the rule "' . $principal . '" in ' . $action . ' and the rule "' . $principal . '" in ' . $action . '.deny. '
			. 'Remove one of the two; a deny wins, so leaving both stored would grant nothing and read as a grant.';
	}//end collision()

	public function testRefusedBlocksNameEveryCollisionAndTheOrphanedManage(): void {
		$this->assertSame(
			[self::collision('read', 'c'), self::collision('read', 'b'), self::collision('update', 'b')],
			self::findings(
				[
					'read' => ['a', 'b', 'c'],
					'update' => ['b'],
					'roles' => ['b' => ['b']],
					'deny' => ['read' => ['c', 'b', 'x'], 'update' => ['b'], 'roles' => ['b' => ['b']], 'delete' => 'b'],
				]
			)
		);

		$findings = self::findings(['manage' => ['beheer'], 'deny' => ['manage' => ['beheer', 'extra']]]);
		$this->assertCount(2, $findings);
		$this->assertSame(self::collision('manage', 'beheer'), $findings[0]);
		$this->assertSame(
			'The authorization of the schema "zaak" would deny "manage" to "beheer", "extra" and leave no principal holding it, '
			. 'so nobody could edit the access rules of the schema "zaak" afterwards. '
			. 'Grant "manage" to another group first, then write the deny.',
			end($findings)
		);
	}//end testRefusedBlocksNameEveryCollisionAndTheOrphanedManage()

	public function testAllowedBlocksHaveNoFindings(): void {
		$this->assertSame([], self::findings(['read' => ['a'], 'deny' => ['read' => ['b']]]));
		$this->assertSame([], self::findings(['manage' => ['tweede'], 'deny' => ['manage' => ['beheer']]]));
		$this->assertSame([], self::findings(['manage' => ['beheer']]));
	}//end testAllowedBlocksHaveNoFindings()
}//end class
