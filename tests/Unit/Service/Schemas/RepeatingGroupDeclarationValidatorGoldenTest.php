<?php

/**
 * OpenRegister - golden of the repeating group declaration validator's answers.
 *
 * Pins what the validator accepts and the exact refusal payload (code, key,
 * path, message) of each refusal, so a restructuring of its helpers cannot
 * change what a schema author is told.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\RepeatingGroupDeclarationValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\RepeatingGroupDeclarationValidator
 * @uses \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 */
final class RepeatingGroupDeclarationValidatorGoldenTest extends TestCase {

	/**
	 * Declarations the validator accepts.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function acceptedProvider(): array {
		$members = ['type' => 'object', 'properties' => ['naam' => ['type' => 'string']]];

		return [
			'no group, no group keys'        => [['type' => 'string']],
			'no group, null group keys'      => [['type' => 'string', 'groupOrdered' => null, 'groupLabel' => null]],
			'group without label'            => [['type' => 'array', 'repeatingGroup' => true, 'items' => $members]],
			'group with null label'          => [['type' => 'array', 'repeatingGroup' => true, 'groupLabel' => null, 'items' => $members]],
			'group with a declared label'    => [['type' => 'array', 'repeatingGroup' => true, 'groupLabel' => 'naam', 'items' => $members]],
			'repeatingGroup false, no keys'  => [['type' => 'array', 'repeatingGroup' => false]],
		];
	}

	/**
	 * Declarations the validator refuses, with the refusal's code and message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
	 */
	public static function refusedProvider(): array {
		$members = ['type' => 'object', 'properties' => ['naam' => ['type' => 'string'], 7 => ['type' => 'string']]];

		return [
			'groupOrdered without group' => [
				['type' => 'array', 'groupOrdered' => true],
				'repeating-group-not-declared',
				"'groupOrdered' and 'groupLabel' at '/p' need 'repeatingGroup: true'.",
			],
			'groupLabel without group'   => [
				['type' => 'array', 'repeatingGroup' => 'yes', 'groupLabel' => 'naam'],
				'repeating-group-not-declared',
				"'groupOrdered' and 'groupLabel' at '/p' need 'repeatingGroup: true'.",
			],
			'group not an array'         => [
				['type' => 'string', 'repeatingGroup' => true, 'items' => $members],
				'repeating-group-not-an-array',
				"A repeating group at '/p' has to be type 'array'. Rows are a list.",
			],
			'group without members'      => [
				['type' => 'array', 'repeatingGroup' => true, 'items' => ['type' => 'object']],
				'repeating-group-without-members',
				"A repeating group at '/p' has to declare its members under 'items.properties'.",
			],
			'group with empty members'   => [
				['type' => 'array', 'repeatingGroup' => true, 'items' => ['properties' => []]],
				'repeating-group-without-members',
				"A repeating group at '/p' has to declare its members under 'items.properties'.",
			],
			'label names unknown member' => [
				['type' => 'array', 'repeatingGroup' => true, 'groupLabel' => 'achternaam', 'items' => $members],
				'repeating-group-unknown-label-member',
				"'groupLabel' at '/p' names a member that is not declared. Members are: naam, 7.",
			],
			'label is not a string'      => [
				['type' => 'array', 'repeatingGroup' => true, 'groupLabel' => 7, 'items' => $members],
				'repeating-group-unknown-label-member',
				"'groupLabel' at '/p' names a member that is not declared. Members are: naam, 7.",
			],
		];
	}

	/**
	 * An accepted declaration passes without a refusal.
	 *
	 * @param array<string, mixed> $property The declaration.
	 *
	 * @dataProvider acceptedProvider
	 *
	 * @return void
	 */
	public function testAccepted(array $property): void {
		(new RepeatingGroupDeclarationValidator())->validate(property: $property, path: '/p');

		$this->addToAssertionCount(1);
	}

	/**
	 * A refused declaration carries its exact refusal payload.
	 *
	 * @param array<string, mixed> $property The declaration.
	 * @param string               $code     The expected refusal code.
	 * @param string               $message  The expected message.
	 *
	 * @dataProvider refusedProvider
	 *
	 * @return void
	 */
	public function testRefused(array $property, string $code, string $message): void {
		try {
			(new RepeatingGroupDeclarationValidator())->validate(property: $property, path: '/p');
		} catch (PropertyVocabularyException $exception) {
			$this->assertSame($message, $exception->getMessage());
			$this->assertSame(
				[['code' => $code, 'key' => 'repeatingGroup', 'path' => '/p', 'message' => $message]],
				$exception->getErrors()
			);
			return;
		}

		$this->fail('The declaration was accepted.');
	}
}
