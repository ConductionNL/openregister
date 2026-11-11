<?php

/**
 * OpenRegister OasRequestValidatorRefLoopTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference that comes back to itself ends, and every other reference still judges the data.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasRequestValidator
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs
 * @uses   \OCA\OpenRegister\Service\Object\RefLoopGuard
 * @uses   \OCA\OpenRegister\Service\Object\RefLoopGuardParser
 */
final class OasRequestValidatorRefLoopTest extends TestCase {
	/**
	 * Every way a reference comes back to itself at one place in the data.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function loops(): array {
		$self = ['$ref' => '#/properties/a'];

		return [
			'itself'                   => [['properties' => ['a' => $self]]],
			'through another property' => [['properties' => ['a' => ['$ref' => '#/properties/b'], 'b' => $self]]],
			'through allOf'            => [['properties' => ['a' => ['allOf' => [$self]]]]],
			'through not'              => [['properties' => ['a' => ['not' => $self]]]],
			'the root'                 => [['$ref' => '#']],
			'a dynamic anchor'         => [['$dynamicAnchor' => 'node', '$dynamicRef' => '#node']],
		];
	}//end loops()

	/**
	 * A loop answers, and adds no error of its own.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return void
	 */
	#[DataProvider('loops')]
	public function testALoopEnds(array $schema): void {
		$this->assertSame([], (new OasRequestValidator())->validate((object)['a' => 1], $schema));
	}//end testALoopEnds()

	/**
	 * Inputs judged against an action's declared schema end the same way.
	 *
	 * @return void
	 */
	public function testAnActionInputSchemaWithALoopAnswers(): void {
		$inputs = new ObjectActionInputs(validator: new OasRequestValidator());

		$this->assertSame([], $inputs->errors(schema: ['properties' => ['a' => ['$ref' => '#/properties/a']]], inputs: ['a' => 1]));
	}//end testAnActionInputSchemaWithALoopAnswers()

	/**
	 * A constraint next to a loop still holds.
	 *
	 * @return void
	 */
	public function testAConstraintBesideALoopStillHolds(): void {
		$schema = ['properties' => ['a' => ['allOf' => [['$ref' => '#/properties/a'], ['type' => 'object']]]]];

		$this->assertNotSame([], (new OasRequestValidator())->validate((object)['a' => 'text'], $schema));
	}//end testAConstraintBesideALoopStillHolds()

	/**
	 * A reference that descends keeps judging every level of the data.
	 *
	 * @return void
	 */
	public function testRecursionThroughTheDataStillJudgesEachLevel(): void {
		$schema = [
			'type'       => 'object',
			'properties' => ['size' => ['type' => 'number'], 'next' => ['$ref' => '#']],
		];
		$validator = new OasRequestValidator();

		$fine = (object)['size' => 1, 'next' => (object)['size' => 2, 'next' => (object)['size' => 3]]];
		$wide = (object)['size' => 1, 'next' => (object)['size' => 2, 'next' => (object)['size' => 'wide']]];

		$this->assertSame([], $validator->validate($fine, $schema));
		$this->assertNotSame([], $validator->validate($wide, $schema));
	}//end testRecursionThroughTheDataStillJudgesEachLevel()

	/**
	 * Refusing to write a default into the data survives the guarded parser.
	 *
	 * @return void
	 */
	public function testValidationStillWritesNoDefaults(): void {
		$schema = [
			'properties'            => ['a' => ['type' => 'string']],
			'if'                    => ['properties' => ['a' => ['const' => 'x']]],
			'then'                  => ['properties' => ['b' => ['default' => 1]]],
			'unevaluatedProperties' => false,
		];

		$this->assertSame([], (new OasRequestValidator())->validate((object)['a' => 'x'], $schema));
	}//end testValidationStillWritesNoDefaults()
}//end class
