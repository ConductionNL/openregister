<?php

/**
 * OpenRegister - repeating groups of fields on an object.
 *
 * Pins the two things that separate an authorable repeating group from an
 * array of objects: the bounds bind, and a refusal names the row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RepeatingGroupValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\RepeatingGroupValidator
 */
final class RepeatingGroupValidatorTest extends TestCase {

	/**
	 * A schema with one repeating group of at most three gemachtigden.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setTitle('Zaak');
		$schema->setProperties(
			[
				'omschrijving' => ['type' => 'string'],
				'gemachtigden' => [
					'type' => 'array',
					Schema::REPEATING_GROUP_PROPERTY_KEYWORD => true,
					Schema::GROUP_ORDERED_PROPERTY_KEYWORD => true,
					Schema::GROUP_LABEL_PROPERTY_KEYWORD => 'naam',
					'minItems' => 1,
					'maxItems' => 3,
					'items' => [
						'type' => 'object',
						'required' => ['naam'],
						'properties' => [
							'naam' => ['type' => 'string'],
							'bsn' => ['type' => 'string'],
							'aandeel' => ['type' => 'integer'],
						],
					],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * Two gemachtigden on one record, both stored and both validated.
	 *
	 * @return void
	 */
	public function testTwoRowsAreAccepted(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: [
				'omschrijving' => 'Bezwaar',
				'gemachtigden' => [
					['naam' => 'De Vries', 'aandeel' => 50],
					['naam' => 'Yilmaz', 'aandeel' => 50],
				],
			],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testTwoRowsAreAccepted()

	/**
	 * A violation says which row and which field.
	 *
	 * @return void
	 */
	public function testAViolationNamesTheRowAndTheMember(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: [
				'gemachtigden' => [
					['naam' => 'De Vries'],
					['bsn' => '123456782'],
				],
			],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame(2, $violations[0]['position']);
		$this->assertSame('naam', $violations[0]['member']);
		$this->assertSame('repeating-group-member-required', $violations[0]['code']);
		$this->assertStringContainsString('Row 2', $violations[0]['message']);
		$this->assertStringContainsString('naam', $violations[0]['message']);
	}//end testAViolationNamesTheRowAndTheMember()

	/**
	 * The maximum is enforced, and the refusal names it.
	 *
	 * @return void
	 */
	public function testTheMaximumIsEnforced(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: [
				'gemachtigden' => [
					['naam' => 'Een'],
					['naam' => 'Twee'],
					['naam' => 'Drie'],
					['naam' => 'Vier'],
				],
			],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('repeating-group-above-maximum', $violations[0]['code']);
		$this->assertNull($violations[0]['position']);
		$this->assertStringContainsString('3', $violations[0]['message']);
	}//end testTheMaximumIsEnforced()

	/**
	 * The minimum is enforced, so an emptied group is refused.
	 *
	 * @return void
	 */
	public function testTheMinimumIsEnforced(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: ['gemachtigden' => []],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('repeating-group-below-minimum', $violations[0]['code']);
	}//end testTheMinimumIsEnforced()

	/**
	 * A member holding the wrong kind of value is refused, by row.
	 *
	 * @return void
	 */
	public function testAMemberOfTheWrongTypeIsRefused(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: ['gemachtigden' => [['naam' => 'De Vries', 'aandeel' => 'de helft']]],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('repeating-group-member-type', $violations[0]['code']);
		$this->assertSame(1, $violations[0]['position']);
		$this->assertSame('aandeel', $violations[0]['member']);
	}//end testAMemberOfTheWrongTypeIsRefused()

	/**
	 * A group the payload does not mention is nobody's business here.
	 *
	 * A partial update that never names the group would otherwise be refused
	 * by the minimum, which would make every other field on the object
	 * un-editable for as long as the group was short.
	 *
	 * @return void
	 */
	public function testAnOmittedGroupIsNotAViolation(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: ['omschrijving' => 'Bezwaar'],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testAnOmittedGroupIsNotAViolation()

	/**
	 * An existing array of objects keeps working.
	 *
	 * The regression that task 1.3 asks for: a schema that declares an array
	 * of objects and never says `repeatingGroup` gains no bounds, no row
	 * checks and no refusals. Everything in this change is opt-in.
	 *
	 * @return void
	 */
	public function testAnUndeclaredArrayOfObjectsIsUntouched(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'percelen' => [
					'type' => 'array',
					'maxItems' => 1,
					'items' => [
						'type' => 'object',
						'required' => ['kadastraleAanduiding'],
						'properties' => ['kadastraleAanduiding' => ['type' => 'string']],
					],
				],
			]
		);

		$violations = (new RepeatingGroupValidator())->validate(
			object: ['percelen' => [['oppervlakte' => 120], ['oppervlakte' => 240]]],
			schema: $schema
		);

		$this->assertSame([], $violations);
	}//end testAnUndeclaredArrayOfObjectsIsUntouched()

	/**
	 * A group sent as something other than a list is refused as a whole.
	 *
	 * @return void
	 */
	public function testAGroupThatIsNotAListIsRefused(): void {
		$violations = (new RepeatingGroupValidator())->validate(
			object: ['gemachtigden' => ['naam' => 'De Vries']],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('repeating-group-not-a-list', $violations[0]['code']);
	}//end testAGroupThatIsNotAListIsRefused()
}//end class
