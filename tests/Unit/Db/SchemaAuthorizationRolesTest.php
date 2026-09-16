<?php

/**
 * A schema's role-to-groups assignment survives the write path.
 *
 * The notification dispatcher reads `authorization.roles` to turn a rule's
 * `{"kind": "role", "role": "behandelaar"}` into the people to tell. Until this
 * key was reserved, `Schema::hydrate()` refused it as an unknown action, so the
 * only map the dispatcher reads could not be written by any import. A role kind
 * that nothing can assign resolves to nobody and records `recipient-unresolved`,
 * which looks exactly like a quiet team.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-declared-role-assignment-is-writable-through-the-schema-req-nrg-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The role assignment on the way in.
 *
 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-declared-role-assignment-is-writable-through-the-schema-req-nrg-007
 */
class SchemaAuthorizationRolesTest extends TestCase {
	/**
	 * A schema that assigns a role is stored with the assignment intact.
	 *
	 * @return void
	 */
	public function testARoleAssignmentSurvivesHydrate(): void {
		$schema = new Schema();
		$schema->hydrate(
			object: [
				'slug' => 'case',
				'title' => 'Case',
				'authorization' => [
					'read' => ['dossiq-users'],
					Schema::ROLES_KEY => [
						'behandelaar' => ['dossiq-behandelaars'],
						'coordinator' => ['dossiq-coordinatoren', 'dossiq-teamleiders'],
					],
				],
			]
		);

		$this->assertSame(
			expected: [
				'behandelaar' => ['dossiq-behandelaars'],
				'coordinator' => ['dossiq-coordinatoren', 'dossiq-teamleiders'],
			],
			actual: ($schema->getAuthorization()[Schema::ROLES_KEY] ?? null),
			message: 'The dispatcher reads authorization.roles; a write that drops it addresses nobody.'
		);
	}//end testARoleAssignmentSurvivesHydrate()

	/**
	 * Reserving the key does not open the action vocabulary.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsStillRefused(): void {
		$schema = new Schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid authorization action 'raed'");

		$schema->hydrate(
			object: [
				'slug' => 'case',
				'authorization' => ['raed' => ['dossiq-users']],
			]
		);
	}//end testAnUnknownActionIsStillRefused()

	/**
	 * A role assigned no group is refused, naming the role.
	 *
	 * @return void
	 */
	public function testARoleWithNoGroupIsRefused(): void {
		$schema = new Schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Role 'behandelaar' in schema must list at least one group id");

		$schema->hydrate(
			object: [
				'slug' => 'case',
				'authorization' => [Schema::ROLES_KEY => ['behandelaar' => []]],
			]
		);
	}//end testARoleWithNoGroupIsRefused()

	/**
	 * A list of role names, rather than a map, is refused.
	 *
	 * @return void
	 */
	public function testAListOfRoleNamesIsRefused(): void {
		$schema = new Schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('names a role with no name');

		$schema->hydrate(
			object: [
				'slug' => 'case',
				'authorization' => [Schema::ROLES_KEY => ['behandelaar', 'coordinator']],
			]
		);
	}//end testAListOfRoleNamesIsRefused()

	/**
	 * A group id that is not a non-empty string is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyGroupIdIsRefused(): void {
		$schema = new Schema();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Role 'behandelaar' in schema lists a group id that is not a non-empty string");

		$schema->hydrate(
			object: [
				'slug' => 'case',
				'authorization' => [Schema::ROLES_KEY => ['behandelaar' => ['  ']]],
			]
		);
	}//end testAnEmptyGroupIdIsRefused()

	/**
	 * A schema that assigns no roles yet is accepted.
	 *
	 * @return void
	 */
	public function testAnEmptyAssignmentIsAccepted(): void {
		$schema = new Schema();
		$schema->hydrate(
			object: [
				'slug' => 'case',
				'authorization' => ['read' => ['dossiq-users'], Schema::ROLES_KEY => []],
			]
		);

		$this->assertSame(
			expected: [],
			actual: ($schema->getAuthorization()[Schema::ROLES_KEY] ?? null),
			message: 'A schema that assigns no roles yet says what a schema without the key says.'
		);
	}//end testAnEmptyAssignmentIsAccepted()
}//end class
