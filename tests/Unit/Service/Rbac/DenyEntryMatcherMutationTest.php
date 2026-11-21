<?php

/**
 * Mutation-killing tests for the matcher that decides which deny entries name a caller.
 *
 * Written against the mutants Infection left alive in DenyEntryMatcher: the
 * object-form entry by group and by user, the `mcp` scope that is never a
 * principal, an empty or non-string user, the `conditional` flag that tells
 * a hard deny from a match-scoped one, and that every matching entry is
 * returned, not only the first.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DenyEntryMatcherMutationTest extends TestCase {
	private const PRINCIPALS = ['stagiairs', 'mcp', 'user:jan'];

	/**
	 * @return array<string, array{0: mixed, 1: ?string}>
	 */
	public static function entries(): array {
		return [
			'group object naming a principal'     => [['group' => 'stagiairs'], 'stagiairs'],
			'group object naming nobody'          => [['group' => 'redactie'], null],
			'group object with the mcp scope'     => [['group' => 'mcp'], null],
			'group object with a non-string'      => [['group' => 7], null],
			'user object naming the caller'       => [['user' => 'jan'], 'user:jan'],
			'user object naming someone else'     => [['user' => 'piet'], null],
			'user object with an empty user'      => [['user' => ''], null],
			'user object with a non-string user'  => [['user' => 12], null],
			'group miss falls through to user'    => [['group' => 'redactie', 'user' => 'jan'], 'user:jan'],
			'string entry with the mcp scope'     => ['mcp', null],
			'string entry naming a principal'     => ['stagiairs', 'stagiairs'],
			'neither string nor array'            => [42, null],
		];
	}//end entries()

	#[DataProvider('entries')]
	public function testEntryNames(mixed $entry, ?string $expected): void {
		$this->assertSame($expected, (new DenyEntryMatcher())->entryNames($entry, self::PRINCIPALS));
	}//end testEntryNames()

	public function testEveryMatchingEntryIsReturnedWithItsConditionalFlag(): void {
		$hard        = 'stagiairs';
		$scoped      = ['user' => 'jan', 'match' => ['status' => 'concept']];
		$emptyMatch  = ['group' => 'stagiairs', 'match' => []];
		$scalarMatch = ['group' => 'stagiairs', 'match' => 'concept'];
		$miss        = ['group' => 'redactie', 'match' => ['status' => 'concept']];

		$matches = (new DenyEntryMatcher())->matchingDenials(
			[$hard, $scoped, $miss, $emptyMatch, $scalarMatch],
			new DenyQuestion(action: 'delete', principals: self::PRINCIPALS)
		);

		$this->assertSame(
			[
				['rule' => $hard, 'principal' => 'stagiairs', 'action' => 'delete', 'conditional' => false],
				['rule' => $scoped, 'principal' => 'user:jan', 'action' => 'delete', 'conditional' => true],
				['rule' => $emptyMatch, 'principal' => 'stagiairs', 'action' => 'delete', 'conditional' => false],
				['rule' => $scalarMatch, 'principal' => 'stagiairs', 'action' => 'delete', 'conditional' => false],
			],
			$matches
		);
	}//end testEveryMatchingEntryIsReturnedWithItsConditionalFlag()

	public function testNonStringUserNeverBecomesAPrincipal(): void {
		$this->assertNull((new DenyEntryMatcher())->entryNames(['user' => 12], ['user:12']));
	}//end testNonStringUserNeverBecomesAPrincipal()

	public function testNoPrincipalNamedMeansNoDenials(): void {
		$this->assertSame(
			[],
			(new DenyEntryMatcher())->matchingDenials([['group' => 'redactie'], 'mcp'], new DenyQuestion(action: 'read', principals: self::PRINCIPALS))
		);
	}//end testNoPrincipalNamedMeansNoDenials()
}//end class
