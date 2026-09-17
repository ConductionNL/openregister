<?php

/**
 * OpenRegister - a repeating group's declaration has to hold together.
 *
 * Checked when the schema is saved rather than when an object is written
 * against it, because a group with no declared members is a schema mistake and
 * only its author can fix it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler
 */
final class RepeatingGroupDeclarationTest extends TestCase {

	/**
	 * The save-time validator under test.
	 *
	 * @var PropertyValidatorHandler
	 */
	private PropertyValidatorHandler $validator;

	/**
	 * Wire the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PropertyValidatorHandler();
	}

	/**
	 * A whole group declaration saves.
	 *
	 * @return void
	 */
	public function testACompleteGroupSaves(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: [
					'type' => 'array',
					'repeatingGroup' => true,
					'groupOrdered' => true,
					'groupLabel' => 'naam',
					'maxItems' => 3,
					'items' => [
						'type' => 'object',
						'required' => ['naam'],
						'properties' => ['naam' => ['type' => 'string']],
					],
				],
				path: '/gemachtigden'
			),
			message: 'a complete repeating group must save'
		);
	}

	/**
	 * A null group key is a spelling, not a declaration.
	 *
	 * The convention every other check in the validator follows, and the one
	 * `PropertyVocabularyTest` probes the whole published vocabulary with: a
	 * key present and null says the name is right and asserts nothing about a
	 * value. Reading a null as a declaration made two published keys
	 * impossible to save on their own.
	 *
	 * @return void
	 */
	public function testANullGroupKeyIsNotADeclaration(): void {
		$this->assertTrue(
			condition: $this->validator->validateProperty(
				property: ['type' => 'string', 'groupOrdered' => null, 'groupLabel' => null],
				path: '/probe'
			),
			message: 'a null group key must not be read as a declaration'
		);
	}

	/**
	 * A real group key without the group itself is refused.
	 *
	 * The other half of the rule above. `groupOrdered: true` on a property
	 * nobody declared a group would do nothing at all, and a keyword that
	 * silently does nothing is worse than one that is refused.
	 *
	 * @return void
	 */
	public function testAGroupKeyWithoutTheGroupIsRefused(): void {
		$this->expectException(PropertyVocabularyException::class);

		$this->validator->validateProperty(
			property: ['type' => 'array', 'groupOrdered' => true],
			path: '/percelen'
		);
	}

	/**
	 * A group that is not a list is refused.
	 *
	 * @return void
	 */
	public function testAGroupThatIsNotAnArrayIsRefused(): void {
		$this->expectException(PropertyVocabularyException::class);

		$this->validator->validateProperty(
			property: ['type' => 'string', 'repeatingGroup' => true],
			path: '/gemachtigden'
		);
	}

	/**
	 * A group with no declared members is refused.
	 *
	 * Without members a row is an untyped blob, and the per-row refusal this
	 * change promises has nothing to name.
	 *
	 * @return void
	 */
	public function testAGroupWithoutMembersIsRefused(): void {
		$this->expectException(PropertyVocabularyException::class);

		$this->validator->validateProperty(
			property: ['type' => 'array', 'repeatingGroup' => true, 'items' => ['type' => 'object']],
			path: '/gemachtigden'
		);
	}

	/**
	 * A label pointing at a member nobody declared is refused.
	 *
	 * It would render blank on every row and read as a data problem for as
	 * long as anybody was willing to look.
	 *
	 * @return void
	 */
	public function testALabelNamingAnUndeclaredMemberIsRefused(): void {
		$this->expectException(PropertyVocabularyException::class);

		$this->validator->validateProperty(
			property: [
				'type' => 'array',
				'repeatingGroup' => true,
				'groupLabel' => 'achternaam',
				'items' => [
					'type' => 'object',
					'properties' => ['naam' => ['type' => 'string']],
				],
			],
			path: '/gemachtigden'
		);
	}
}
