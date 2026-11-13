<?php

/**
 * TimelineKindFields accepts only a kind's declared fields, typed and enumerated as declared.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Timeline;

use OCA\OpenRegister\Notifications\Db\AdministeredDeclaration;
use OCA\OpenRegister\Notifications\Db\TimelineKind;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindFields;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A timeline entry's fields against the properties and required list of its kind.
 */
#[CoversClass(TimelineKindFields::class)]
#[UsesClass(TimelineKind::class)]
#[UsesClass(AdministeredDeclaration::class)]
#[UsesClass(TimelineValidationException::class)]
final class TimelineKindFieldsTest extends TestCase {

	/**
	 * A "phone-call" kind.
	 *
	 * @param array<string|int, mixed>|null $properties The declared properties.
	 * @param array<int, string>|null       $required   The required property names.
	 *
	 * @return TimelineKind
	 */
	private function kind(?array $properties, ?array $required = null): TimelineKind {
		$kind = new TimelineKind();
		$kind->setSlug('phone-call');
		$kind->setProperties($properties);
		$kind->setRequired($required);
		return $kind;
	}//end kind()

	/**
	 * The field errors a validation raises, or null when it passes.
	 *
	 * @param TimelineKind         $kind   The kind.
	 * @param array<string, mixed> $fields The entry fields.
	 *
	 * @return array<string, string>|null
	 */
	private function errorsOf(TimelineKind $kind, array $fields): ?array {
		try {
			(new TimelineKindFields(kind: $kind, fields: $fields))->validated();
		} catch (TimelineValidationException $e) {
			return $e->getErrors();
		}

		return null;
	}//end errorsOf()

	public function testOnlyDeclaredFieldsAreKept(): void {
		$kind = $this->kind(['caller' => ['type' => 'string'], 'minutes' => ['type' => 'integer']]);

		$this->assertSame(
			['caller' => 'Jan', 'minutes' => 12],
			(new TimelineKindFields(kind: $kind, fields: ['minutes' => 12, 'caller' => 'Jan', 'secret' => 'x']))->validated()
		);
	}//end testOnlyDeclaredFieldsAreKept()

	public function testAMissingOptionalFieldIsFineAndAMissingRequiredOneIsNot(): void {
		$kind = $this->kind(['caller' => ['type' => 'string'], 'note' => ['type' => 'string']], ['caller']);

		$this->assertSame(['caller' => 'Jan'], (new TimelineKindFields(kind: $kind, fields: ['caller' => 'Jan']))->validated());
		$this->assertSame(['caller' => 'This field is required by kind phone-call'], $this->errorsOf($kind, ['note' => 'hi']));
	}//end testAMissingOptionalFieldIsFineAndAMissingRequiredOneIsNot()

	public function testEachDeclaredTypeIsEnforced(): void {
		$kind = $this->kind(
			[
				's' => ['type' => 'string'],
				'i' => ['type' => 'integer'],
				'n' => ['type' => 'number'],
				'b' => ['type' => 'boolean'],
				'a' => ['type' => 'array'],
				'o' => ['type' => 'object'],
			]
		);

		$this->assertSame(
			[
				's' => 'Expected a value of type string',
				'i' => 'Expected a value of type integer',
				'n' => 'Expected a value of type number',
				'b' => 'Expected a value of type boolean',
				'a' => 'Expected a value of type array',
				'o' => 'Expected a value of type object',
			],
			$this->errorsOf($kind, ['s' => 1, 'i' => '1', 'n' => '1.5', 'b' => 'yes', 'a' => 'x', 'o' => 3])
		);
		$this->assertNull($this->errorsOf($kind, ['s' => 'x', 'i' => 1, 'n' => 1.5, 'b' => false, 'a' => [1], 'o' => ['k' => 'v']]));
		$this->assertNull($this->errorsOf($kind, ['n' => 2]));
	}//end testEachDeclaredTypeIsEnforced()

	public function testAnUndeclaredTypeDefaultsToStringAndAnUnknownTypeAcceptsAnything(): void {
		$kind = $this->kind(['plain' => [], 'loose' => ['type' => 'date'], 'bare' => 'not an array']);

		$this->assertSame(
			['plain' => 'Expected a value of type string', 'bare' => 'Expected a value of type string'],
			$this->errorsOf($kind, ['plain' => 5, 'loose' => 5, 'bare' => 5])
		);
		$this->assertNull($this->errorsOf($kind, ['plain' => 'x', 'loose' => ['any'], 'bare' => 'y']));
	}//end testAnUndeclaredTypeDefaultsToStringAndAnUnknownTypeAcceptsAnything()

	public function testAnEnumAcceptsOnlyItsValuesStrictly(): void {
		$kind = $this->kind(['direction' => ['type' => 'string', 'enum' => ['in', 'out']], 'level' => ['type' => 'integer', 'enum' => [1, 2]]]);

		$this->assertNull($this->errorsOf($kind, ['direction' => 'in', 'level' => 2]));
		$this->assertSame(
			['direction' => 'Expected one of: in, out', 'level' => 'Expected one of: 1, 2'],
			$this->errorsOf($kind, ['direction' => 'sideways', 'level' => 3])
		);
		$numericStrings = $this->kind(['level' => ['type' => 'integer', 'enum' => ['1', '2']]]);
		$this->assertSame(['level' => 'Expected one of: 1, 2'], $this->errorsOf($numericStrings, ['level' => 1]));
	}//end testAnEnumAcceptsOnlyItsValuesStrictly()

	public function testATypeErrorIsReportedBeforeTheEnum(): void {
		$kind = $this->kind(['level' => ['type' => 'integer', 'enum' => [1, 2]]]);

		$this->assertSame(['level' => 'Expected a value of type integer'], $this->errorsOf($kind, ['level' => '1']));
	}//end testATypeErrorIsReportedBeforeTheEnum()

	public function testAllErrorsAreReportedTogetherInTheMessage(): void {
		$kind = $this->kind(['caller' => ['type' => 'string'], 'minutes' => ['type' => 'integer']], ['caller']);

		try {
			(new TimelineKindFields(kind: $kind, fields: ['minutes' => 'ten']))->validated();
			$this->fail('Invalid fields were accepted.');
		} catch (TimelineValidationException $e) {
			$this->assertSame(
				'This field is required by kind phone-call; Expected a value of type integer',
				$e->getMessage()
			);
		}
	}//end testAllErrorsAreReportedTogetherInTheMessage()

	public function testAKindWithoutPropertiesAcceptsNothing(): void {
		$this->assertSame([], (new TimelineKindFields(kind: $this->kind(null), fields: ['anything' => 1]))->validated());
		$this->assertSame([], (new TimelineKindFields(kind: $this->kind([0 => ['type' => 'string']]), fields: ['0' => 'x']))->validated());
	}//end testAKindWithoutPropertiesAcceptsNothing()
}//end class
