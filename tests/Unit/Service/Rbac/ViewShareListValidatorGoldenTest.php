<?php

/**
 * Golden findings of ViewShareListValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ViewShareListValidator;
use PHPUnit\Framework\TestCase;

/**
 * A share list is stored only when every share names one existing target once, with a read or write mode.
 */
class ViewShareListValidatorGoldenTest extends TestCase {

	/**
	 * Who exists: group `staff`, user `alice`.
	 *
	 * @return array<string, callable> Per kind, whether an id exists.
	 */
	private function exists(): array {
		return [
			'group' => static fn (string $id): bool => $id === 'staff',
			'user' => static fn (string $id): bool => $id === 'alice',
		];
	}//end exists()

	/**
	 * A valid list, and an absent one, may be stored.
	 *
	 * @return void
	 */
	public function testAValidListIsAdmitted(): void {
		$validator = new ViewShareListValidator();

		$this->assertSame([], $validator->findings(null, $this->exists()));
		$this->assertSame([], $validator->findings([], $this->exists()));
		$this->assertSame(
			[],
			$validator->findings([['group' => 'staff', 'mode' => 'read'], ['user' => 'alice', 'mode' => 'write']], $this->exists())
		);
	}//end testAValidListIsAdmitted()

	/**
	 * Every refusal, in the order a caller sees them.
	 *
	 * @return void
	 */
	public function testEveryRefusalInOrder(): void {
		$validator = new ViewShareListValidator();

		$this->assertSame(
			[['code' => 'share.not-a-list', 'message' => 'sharedWith must be a list of shares.']],
			$validator->findings('staff', $this->exists())
		);
		$this->assertSame(
			[
				['code' => 'share.not-an-object', 'message' => 'Share 0 is not an object.'],
				['code' => 'share.user-and-group', 'message' => 'Share 1 names both a user and a group.'],
				['code' => 'share.no-group', 'message' => 'Share 2 names no user or group.'],
				['code' => 'share.bad-mode', 'message' => 'Share with "staff" must be read or write, not "".'],
				['code' => 'share.bad-mode', 'message' => 'Share with "staff" must be read or write, not "admin".'],
				['code' => 'share.duplicate-group', 'message' => 'The group "staff" is shared with twice.'],
				['code' => 'share.unknown-user', 'message' => 'The user "bob" does not exist.'],
				['code' => 'share.duplicate-user', 'message' => 'The user "bob" is shared with twice.'],
				['code' => 'share.unknown-user', 'message' => 'The user "bob" does not exist.'],
			],
			$validator->findings(
				[
					'x',
					['group' => 'staff', 'user' => 'alice', 'mode' => 'read'],
					['mode' => 'read'],
					['group' => ' staff '],
					['group' => 'staff', 'mode' => 'admin'],
					['user' => 'bob', 'mode' => 'read'],
					['user' => 'bob', 'mode' => 'write'],
				],
				$this->exists()
			)
		);
	}//end testEveryRefusalInOrder()

	/**
	 * Mutation check: a group and a user with one id are two targets, not a duplicate.
	 *
	 * @return void
	 */
	public function testAGroupAndAUserWithOneIdAreNotADuplicate(): void {
		$exists = ['group' => static fn (string $id): bool => true, 'user' => static fn (string $id): bool => true];

		$this->assertSame(
			[],
			(new ViewShareListValidator())->findings([['group' => 'x', 'mode' => 'read'], ['user' => 'x', 'mode' => 'read']], $exists)
		);
	}//end testAGroupAndAUserWithOneIdAreNotADuplicate()
}//end class
