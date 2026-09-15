<?php

/**
 * Unit tests for GeneratedIdentifierDeclaration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use PHPUnit\Framework\TestCase;

/**
 * The format renders and parses, and the refusals refuse.
 *
 * The round trip is the test that matters most. A format that renders one way
 * and reads back another is an import that advances the wrong counter, or no
 * counter, and the collision does not surface until the hundred-and-twentieth
 * create. So every rendering case is read straight back.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 */
class GeneratedIdentifierDeclarationTest extends TestCase {

	/**
	 * A valid property carrying a declaration.
	 *
	 * @param array<string, mixed> $overrides Keys to replace on the annotation.
	 * @param string $type The property's type.
	 *
	 * @return array<string, mixed> The property definition.
	 */
	private function property(array $overrides = [], string $type = 'string'): array {
		return [
			'type' => $type,
			GeneratedIdentifierDeclaration::ANNOTATION => array_merge(
				['sequence' => 'case', 'format' => 'Z-{year}-{seq:5}', 'resetOn' => 'year'],
				$overrides
			),
		];
	}//end property()

	/**
	 * A property with no annotation answers null rather than throwing.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutTheAnnotationAnswersNull(): void {
		$this->assertNull(
			actual: GeneratedIdentifierDeclaration::fromProperty(
				property: ['type' => 'string'],
				path: 'identifier'
			)
		);

	}//end testAPropertyWithoutTheAnnotationAnswersNull()

	/**
	 * The scenario the delta names: two creates get consecutive identifiers.
	 *
	 * @return void
	 */
	public function testTwoValuesRenderConsecutively(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(),
			path: 'identifier'
		);
		$at = new DateTimeImmutable('2026-03-04 10:00:00');

		$this->assertSame(
			expected: 'Z-2026-00001',
			actual: $declaration->render(sequenceValue: 1, at: $at)
		);
		$this->assertSame(
			expected: 'Z-2026-00002',
			actual: $declaration->render(sequenceValue: 2, at: $at)
		);

	}//end testTwoValuesRenderConsecutively()

	/**
	 * A number past its padding grows rather than being truncated.
	 *
	 * Truncating would silently re-issue: the hundred-thousand-and-first case
	 * of a five-digit format would render as the first.
	 *
	 * @return void
	 */
	public function testANumberPastItsPaddingGrows(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(),
			path: 'identifier'
		);

		$this->assertSame(
			expected: 'Z-2026-100001',
			actual: $declaration->render(
				sequenceValue: 100001,
				at: new DateTimeImmutable('2026-03-04 10:00:00')
			)
		);

	}//end testANumberPastItsPaddingGrows()

	/**
	 * Every rendered value reads back to the number it was rendered from.
	 *
	 * @return void
	 */
	public function testRenderingAndParsingAgree(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['format' => 'CASE/{year}/{month}/{seq:4}']),
			path: 'identifier'
		);
		$at = new DateTimeImmutable('2026-11-04 10:00:00');

		foreach ([1, 42, 9999, 123456] as $value) {
			$rendered = $declaration->render(sequenceValue: $value, at: $at);
			$parsed = $declaration->parse(value: $rendered);

			$this->assertNotNull(actual: $parsed, message: 'did not parse back: '.$rendered);
			$this->assertSame(expected: $value, actual: $parsed['sequence'], message: $rendered);
			$this->assertSame(expected: '2026', actual: $parsed['period'], message: $rendered);
		}

	}//end testRenderingAndParsingAgree()

	/**
	 * The period comes from the value, not from the clock.
	 *
	 * An import landing in January carrying last year's numbers has to advance
	 * LAST year's counter. Reading the period off the clock instead would
	 * advance this year's, leaving last year's free to re-issue.
	 *
	 * @return void
	 */
	public function testTheParsedPeriodComesFromTheValue(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(),
			path: 'identifier'
		);

		$parsed = $declaration->parse(value: 'Z-2025-00120');

		$this->assertSame(expected: 120, actual: $parsed['sequence']);
		$this->assertSame(expected: '2025', actual: $parsed['period']);

	}//end testTheParsedPeriodComesFromTheValue()

	/**
	 * A value that does not match the format parses as null.
	 *
	 * @return void
	 */
	public function testAForeignValueDoesNotParse(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(),
			path: 'identifier'
		);

		$this->assertNull(actual: $declaration->parse(value: 'ZAAK-2026-7'));

	}//end testAForeignValueDoesNotParse()

	/**
	 * A never-resetting counter has one period, whatever the date.
	 *
	 * @return void
	 */
	public function testNeverResettingHasOnePeriod(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['format' => 'INV-{seq:6}', 'resetOn' => 'never']),
			path: 'number'
		);

		$this->assertSame(
			expected: '',
			actual: $declaration->periodAt(new DateTimeImmutable('2026-01-01'))
		);
		$this->assertSame(
			expected: '',
			actual: $declaration->periodAt(new DateTimeImmutable('2027-01-01'))
		);

	}//end testNeverResettingHasOnePeriod()

	/**
	 * A yearly counter has one period per year.
	 *
	 * @return void
	 */
	public function testAYearlyCounterHasOnePeriodPerYear(): void {
		$declaration = GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(),
			path: 'identifier'
		);

		$this->assertSame(
			expected: '2026',
			actual: $declaration->periodAt(new DateTimeImmutable('2026-12-31 23:59:59'))
		);
		$this->assertSame(
			expected: '2027',
			actual: $declaration->periodAt(new DateTimeImmutable('2027-01-01 00:00:00'))
		);

	}//end testAYearlyCounterHasOnePeriodPerYear()

	/**
	 * An unknown placeholder is refused at schema save.
	 *
	 * Left through, it renders as its own literal text, so every object gets
	 * the same characters where a varying part was meant.
	 *
	 * @return void
	 */
	public function testAnUnknownPlaceholderIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['format' => 'Z-{jaar}-{seq:5}']),
			path: 'identifier'
		);

	}//end testAnUnknownPlaceholderIsRefused()

	/**
	 * A format with no sequence in it is refused.
	 *
	 * @return void
	 */
	public function testAFormatWithoutASequenceIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['format' => 'Z-{year}']),
			path: 'identifier'
		);

	}//end testAFormatWithoutASequenceIsRefused()

	/**
	 * A yearly reset that does not render the year is refused.
	 *
	 * This is the worst of the three refusals, because it works for a year and
	 * then quietly re-issues every number from the year before.
	 *
	 * @return void
	 */
	public function testAYearlyResetWithoutTheYearIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['format' => 'Z-{seq:5}', 'resetOn' => 'year']),
			path: 'identifier'
		);

	}//end testAYearlyResetWithoutTheYearIsRefused()

	/**
	 * A declaration on a property that is not a string is refused.
	 *
	 * @return void
	 */
	public function testANonStringPropertyIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(type: 'integer'),
			path: 'identifier'
		);

	}//end testANonStringPropertyIsRefused()

	/**
	 * An unknown key on the annotation is refused.
	 *
	 * A misspelled `reset` beside a working `format` is a counter that never
	 * resets, and a year of case numbers that look right until January.
	 *
	 * @return void
	 */
	public function testAnUnknownAnnotationKeyIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['reset' => 'year']),
			path: 'identifier'
		);

	}//end testAnUnknownAnnotationKeyIsRefused()

	/**
	 * A missing sequence name is refused.
	 *
	 * @return void
	 */
	public function testAMissingSequenceNameIsRefused(): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		GeneratedIdentifierDeclaration::fromProperty(
			property: $this->property(['sequence' => '  ']),
			path: 'identifier'
		);

	}//end testAMissingSequenceNameIsRefused()

	/**
	 * The refusal is a PropertyVocabularyException, so the save answers 422.
	 *
	 * Asserted because it is the whole reason no controller had to learn about
	 * this annotation. If the inheritance goes, every one of these refusals
	 * becomes an unhandled 500 and nothing here would otherwise notice.
	 *
	 * @return void
	 */
	public function testTheRefusalIsAVocabularyRefusal(): void {
		try {
			GeneratedIdentifierDeclaration::fromProperty(
				property: $this->property(['format' => 'Z-{jaar}-{seq:5}']),
				path: 'identifier'
			);
			$this->fail('an unknown placeholder should have been refused');
		} catch (GeneratedIdentifierException $refusal) {
			$this->assertInstanceOf(
				expected: \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException::class,
				actual: $refusal
			);
			$this->assertNotEmpty(actual: $refusal->getErrors());
			$this->assertSame(
				expected: 'identifier',
				actual: ($refusal->getErrors()[0]['path'] ?? null)
			);
		}

	}//end testTheRefusalIsAVocabularyRefusal()
}//end class
