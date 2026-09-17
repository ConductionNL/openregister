<?php

/**
 * OpenRegister - a value recorded as not supplied.
 *
 * Pins the two claims the spec makes: not supplied satisfies a required-value
 * rule, and it is distinguishable from empty.
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
use OCA\OpenRegister\Service\Object\NotSuppliedHandler;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 */
final class NotSuppliedHandlerTest extends TestCase {

	/**
	 * A schema with a required bsn and two administered reasons.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setTitle('Aanvraag');
		$schema->setProperties(
			[
				'bsn' => ['type' => 'string'],
				'toelichting' => ['type' => 'string'],
			]
		);
		$schema->setConfiguration(
			[
				Schema::NOT_SUPPLIED_REASONS_ANNOTATION => [
					'niet_van_toepassing' => 'Not applicable to this request',
					'onbekend_bij_aanvrager' => 'The applicant does not know it',
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * Honest incompleteness beats a typed "onbekend".
	 *
	 * @return void
	 */
	public function testAnAdministeredReasonIsAccepted(): void {
		$violations = (new NotSuppliedHandler())->validate(
			object: [Schema::NOT_SUPPLIED_KEY => ['bsn' => 'onbekend_bij_aanvrager']],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testAnAdministeredReasonIsAccepted()

	/**
	 * Not supplied satisfies a required-value rule.
	 *
	 * The excusing is the mechanism, so this is the assertion that the
	 * required rule actually stops applying: `bsn` leaves `required` and
	 * `toelichting` stays.
	 *
	 * @return void
	 */
	public function testNotSuppliedIsExcusedFromTheRequiredRule(): void {
		$schemaObject = new stdClass();
		$schemaObject->required = ['bsn', 'toelichting'];
		$schemaObject->properties = new stdClass();

		$excused = (new NotSuppliedHandler())->excuse(
			schemaObject: $schemaObject,
			declared: ['bsn' => 'onbekend_bij_aanvrager']
		);

		$this->assertSame(['toelichting'], $excused->required);

		// The schema object handed in is shared and cached per schema version,
		// so excusing one object must not excuse the next one.
		$this->assertSame(['bsn', 'toelichting'], $schemaObject->required);
	}//end testNotSuppliedIsExcusedFromTheRequiredRule()

	/**
	 * Not supplied is not empty.
	 *
	 * @return void
	 */
	public function testNotSuppliedIsDistinguishableFromEmpty(): void {
		$handler = new NotSuppliedHandler();

		$empty = $handler->declared(object: ['bsn' => '']);
		$marked = $handler->declared(object: [Schema::NOT_SUPPLIED_KEY => ['bsn' => 'niet_van_toepassing']]);

		$this->assertSame([], $empty);
		$this->assertSame(['bsn' => 'niet_van_toepassing'], $marked);
	}//end testNotSuppliedIsDistinguishableFromEmpty()

	/**
	 * A reason the schema does not administer is refused, naming the list.
	 *
	 * @return void
	 */
	public function testAnUnadministeredReasonIsRefused(): void {
		$violations = (new NotSuppliedHandler())->validate(
			object: [Schema::NOT_SUPPLIED_KEY => ['bsn' => 'geen_zin']],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('not-supplied-unknown-reason', $violations[0]['code']);
		$this->assertStringContainsString('niet_van_toepassing', $violations[0]['message']);
	}//end testAnUnadministeredReasonIsRefused()

	/**
	 * A property cannot carry a value and be marked not supplied.
	 *
	 * @return void
	 */
	public function testAValueAndTheMarkerTogetherAreRefused(): void {
		$violations = (new NotSuppliedHandler())->validate(
			object: [
				'bsn' => '123456782',
				Schema::NOT_SUPPLIED_KEY => ['bsn' => 'niet_van_toepassing'],
			],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('not-supplied-and-answered', $violations[0]['code']);
	}//end testAValueAndTheMarkerTogetherAreRefused()

	/**
	 * A schema that administers no reasons offers no not-supplied state.
	 *
	 * @return void
	 */
	public function testASchemaWithNoAdministeredReasonsRefuses(): void {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['type' => 'string']]);

		$violations = (new NotSuppliedHandler())->validate(
			object: [Schema::NOT_SUPPLIED_KEY => ['bsn' => 'onbekend_bij_aanvrager']],
			schema: $schema
		);

		$this->assertCount(1, $violations);
		$this->assertSame('not-supplied-no-reasons-administered', $violations[0]['code']);
	}//end testASchemaWithNoAdministeredReasonsRefuses()

	/**
	 * The validator sees neither the record nor the properties it names.
	 *
	 * @return void
	 */
	public function testTheRecordIsStrippedBeforeValidation(): void {
		$stripped = (new NotSuppliedHandler())->stripForValidation(
			object: [
				'toelichting' => 'Geen',
				'bsn' => null,
				Schema::NOT_SUPPLIED_KEY => ['bsn' => 'niet_van_toepassing'],
			]
		);

		$this->assertSame(['toelichting' => 'Geen'], $stripped);
	}//end testTheRecordIsStrippedBeforeValidation()
}//end class
