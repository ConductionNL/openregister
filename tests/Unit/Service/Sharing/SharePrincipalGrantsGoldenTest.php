<?php

/**
 * Characterisation test for SharePrincipalDeriver::grants(): the cases the
 * existing tests leave open — an unqualified share counting as `use`, a group
 * id that equals the user id, a user id that equals a group name, an invalid
 * share list and a match further down the list.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Sharing\ShareGrantee;
use OCA\OpenRegister\Service\Sharing\ShareListCheck;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Pins grants() outcomes, allowed and refused.
 */
class SharePrincipalGrantsGoldenTest extends TestCase {

	/**
	 * Each case: share list, user id, user groups, permissions, expected outcome.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: array<int, string>, 3: array<int, string|null>, 4: bool}>
	 */
	public static function cases(): array {
		return [
			'unqualified share counts as use' => [[['type' => 'user', 'id' => 'alice']], 'alice', [], ['use', null], true],
			'unqualified share is not run' => [[['type' => 'user', 'id' => 'alice']], 'alice', [], ['run'], false],
			'group named like the user is not the user' => [
				[['type' => 'group', 'id' => 'alice', 'permission' => 'run']],
				'alice',
				[],
				['run'],
				false,
			],
			'user named like a group is not the group' => [
				[['type' => 'user', 'id' => 'finance', 'permission' => 'run']],
				'alice',
				['finance'],
				['run'],
				false,
			],
			'a later entry may grant' => [
				[
					['type' => 'user', 'id' => 'bob', 'permission' => 'run'],
					['type' => 'user', 'id' => 'alice', 'permission' => 'read'],
					['type' => 'group', 'id' => 'g', 'permission' => 'run'],
				],
				'alice',
				['g'],
				['run'],
				true,
			],
			'another user is refused' => [[['type' => 'user', 'id' => 'bob', 'permission' => 'run']], 'alice', ['g'], ['run'], false],
			'a non-array list grants nothing' => ['alice', 'alice', [], ['run', null], false],
			'an unknown type grants nothing' => [[['type' => 'role', 'id' => 'alice', 'permission' => 'run']], 'alice', [], ['run'], false],
			'no permissions asked grants nothing' => [[['type' => 'user', 'id' => 'alice', 'permission' => 'run']], 'alice', [], [], false],
			'anonymous with an unqualified share' => [[['type' => 'user', 'id' => '']], '', [], ['use', null], false],
		];
	}//end cases()

	/**
	 * Grants answers as recorded.
	 *
	 * @param mixed                   $sharedWith  The raw share list.
	 * @param string                  $userId      The acting user.
	 * @param array<int, string>      $groups      The acting user's groups.
	 * @param array<int, string|null> $permissions The permissions that satisfy the check.
	 * @param bool                    $expected    The recorded outcome.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGrants(mixed $sharedWith, string $userId, array $groups, array $permissions, bool $expected): void {
		$this->assertSame($expected, (new SharePrincipalDeriver())->grants(new ShareGrantee($userId, $groups), new ShareListCheck($sharedWith, $permissions)));
	}//end testGrants()
}//end class
