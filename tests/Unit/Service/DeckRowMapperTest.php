<?php

/**
 * Unit test for DeckRowMapper: the row it makes of a Deck label and a Deck
 * assignment, with the fallback for every getter that throws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DeckRowMapper;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\DeckRowMapper
 */
class DeckRowMapperTest extends TestCase {

	/**
	 * The accounts.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * Mocked accounts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
	}//end setUp()

	/**
	 * A Deck-like entity whose getters return the given values, or throw for '!throw'.
	 *
	 * @param array<string, mixed> $values Getter name => value.
	 *
	 * @return object
	 */
	private static function entity(array $values): object {
		return new class ($values) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $values Getter name => value.
			 */
			public function __construct(private readonly array $values) {
			}

			/**
			 * Answer a getter.
			 *
			 * @param string            $name The getter.
			 * @param array<int, mixed> $args Unused.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				$value = ($this->values[$name] ?? null);
				if ($value === '!throw') {
					throw new RuntimeException($name);
				}

				return $value;
			}
		};
	}//end entity()

	/**
	 * A label row carries its id as int, its title and colour as text.
	 *
	 * @return void
	 */
	public function testALabelRow(): void {
		$mapper = new DeckRowMapper(userManager: $this->users);
		$this->assertSame(
			['id' => 4, 'title' => 'Urgent', 'color' => 'ff0000'],
			$mapper->label(label: self::entity(values: ['getId' => '4', 'getTitle' => 'Urgent', 'getColor' => 'ff0000']))
		);
	}//end testALabelRow()

	/**
	 * A label without an id has a null id, and throwing getters fall back to null and ''.
	 *
	 * @return void
	 */
	public function testALabelFallsBackPerGetter(): void {
		$mapper = new DeckRowMapper(userManager: $this->users);
		$this->assertSame(
			['id' => null, 'title' => '', 'color' => ''],
			$mapper->label(label: self::entity(values: ['getTitle' => '!throw', 'getColor' => '!throw']))
		);
		$this->assertSame(
			['id' => null, 'title' => 'x', 'color' => ''],
			$mapper->label(label: self::entity(values: ['getId' => '!throw', 'getTitle' => 'x']))
		);
	}//end testALabelFallsBackPerGetter()

	/**
	 * A user assignment carries the account's display name.
	 *
	 * @return void
	 */
	public function testAUserAssigneeCarriesTheDisplayName(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Anna de Vries');
		$this->users->expects($this->once())->method('get')->with('anna')->willReturn($user);

		$this->assertSame(
			['uid' => 'anna', 'type' => 'user', 'displayName' => 'Anna de Vries'],
			(new DeckRowMapper(userManager: $this->users))->assignee(assignment: self::entity(values: ['getParticipant' => 'anna', 'getTypeString' => 'user']))
		);
	}//end testAUserAssigneeCarriesTheDisplayName()

	/**
	 * An unknown account, or a lookup that throws, shows the uid; a group or an empty uid is not looked up.
	 *
	 * @return void
	 */
	public function testAnAssigneeFallsBackToTheUid(): void {
		$this->users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'broken') {
					throw new RuntimeException('ldap');
				}

				return null;
			}
		);
		$mapper = new DeckRowMapper(userManager: $this->users);

		$this->assertSame(['uid' => 'ghost', 'type' => 'user', 'displayName' => 'ghost'], $mapper->assignee(assignment: self::entity(values: ['getParticipant' => 'ghost', 'getTypeString' => 'user'])));
		$this->assertSame(['uid' => 'broken', 'type' => 'user', 'displayName' => 'broken'], $mapper->assignee(assignment: self::entity(values: ['getParticipant' => 'broken', 'getTypeString' => '!throw'])));
	}//end testAnAssigneeFallsBackToTheUid()

	/**
	 * A group assignment and an assignment without a participant are not looked up.
	 *
	 * @return void
	 */
	public function testGroupsAndEmptyParticipantsAreNotLookedUp(): void {
		$this->users->expects($this->never())->method('get');
		$mapper = new DeckRowMapper(userManager: $this->users);

		$this->assertSame(['uid' => 'staff', 'type' => 'group', 'displayName' => 'staff'], $mapper->assignee(assignment: self::entity(values: ['getParticipant' => 'staff', 'getTypeString' => 'group'])));
		$this->assertSame(['uid' => '', 'type' => 'user', 'displayName' => ''], $mapper->assignee(assignment: self::entity(values: ['getParticipant' => '!throw', 'getTypeString' => 'user'])));
	}//end testGroupsAndEmptyParticipantsAreNotLookedUp()
}//end class
