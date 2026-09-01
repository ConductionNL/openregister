<?php

/**
 * Tests for the `user` string format.
 *
 * A user id is syntactically just a string, so a pattern can assert nothing
 * about it — the backend is the only authority on whether the person exists.
 * That makes the negative case the whole point of this format: without it a
 * schema could carry a deleted account's id indefinitely while every consumer
 * resolved it to nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Formats;

use OCA\OpenRegister\Formats\UserFormat;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Proves the format asks the backend, and rejects what it does not confirm.
 */
class UserFormatTest extends TestCase {

	/**
	 * Build the format over a backend that knows exactly one user.
	 *
	 * @return UserFormat The format under test.
	 */
	private function formatKnowing(string $knownUid): UserFormat {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')
			->willReturnCallback(
				static function (string $uid) use ($knownUid): bool {
					return $uid === $knownUid;
				}
			);

		return new UserFormat(userManager: $users);
	}//end formatKnowing()

	/**
	 * An existing user id validates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testExistingUserIsAccepted(): void {
		$this->assertTrue($this->formatKnowing('alice')->validate('alice'));

	}//end testExistingUserIsAccepted()

	/**
	 * A well-formed id for an account that does not exist is rejected.
	 *
	 * This is the assertion that earns the format its keep: the value is a
	 * perfectly good string, and only the backend can say it names nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testUnknownUserIsRejected(): void {
		$this->assertFalse($this->formatKnowing('alice')->validate('bob'));

	}//end testUnknownUserIsRejected()

	/**
	 * Empty and whitespace-only values never reach the backend.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testBlankValuesAreRejectedWithoutAskingTheBackend(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('userExists');

		$format = new UserFormat(userManager: $users);

		$this->assertFalse($format->validate(''));
		$this->assertFalse($format->validate('   '));

	}//end testBlankValuesAreRejectedWithoutAskingTheBackend()

	/**
	 * A non-string value is rejected rather than coerced.
	 *
	 * The validator is handed raw decoded JSON, so a number or an array can
	 * arrive where a user id was declared. Coercing 42 to "42" and asking the
	 * backend about it would turn a type error into a lookup miss, which reads
	 * as "no such user" and hides the real fault.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testNonStringValuesAreRejected(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('userExists');

		$format = new UserFormat(userManager: $users);

		$this->assertFalse($format->validate(42));
		$this->assertFalse($format->validate(null));
		$this->assertFalse($format->validate(['alice']));

	}//end testNonStringValuesAreRejected()

	/**
	 * Surrounding whitespace is trimmed before the lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function testSurroundingWhitespaceIsTrimmed(): void {
		$this->assertTrue($this->formatKnowing('alice')->validate('  alice  '));

	}//end testSurroundingWhitespaceIsTrimmed()
}//end class
