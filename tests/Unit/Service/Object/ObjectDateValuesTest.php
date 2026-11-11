<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\ObjectDateValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cases were recorded on the private ObjectService::normalizeDateValues().
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectDateValues
 */
class ObjectDateValuesTest extends TestCase {
	/**
	 * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: mixed}>
	 */
	public static function cases(): array {
		$date = ['format' => 'date'];
		$time = ['format' => 'date-time'];
		return [
			'date from datetime' => ['2024-01-15T10:30:00+02:00', $date, '2024-01-15'],
			'date already a date' => ['2024-01-15', $date, '2024-01-15'],
			'date that does not parse stays' => ['not a date', $date, 'not a date'],
			'empty date' => ['', $date, null],
			'whitespace date' => ['   ', $date, null],
			'date-time that parses stays' => ['2024-01-15T10:30:00+02:00', $time, '2024-01-15T10:30:00+02:00'],
			'date-time that does not parse clears' => ['not a date', $time, null],
			'empty date-time' => ['', $time, null],
			'other format' => ['2024-01-15T10:30:00+02:00', ['format' => 'uri'], '2024-01-15T10:30:00+02:00'],
			'no format' => ['', [], ''],
			'not a string' => [5, $date, 5],
		];
	}//end cases()

	/**
	 * @param mixed                $value      The stored value.
	 * @param array<string, mixed> $definition The property definition.
	 * @param mixed                $expected   The normalised value.
	 */
	#[DataProvider('cases')]
	public function testNormalisesAValue(mixed $value, array $definition, mixed $expected): void {
		$result = $this->normalize(['when' => $value, 'other' => 'kept'], ['when' => $definition]);

		$this->assertSame(['when' => $expected, 'other' => 'kept'], $result);
	}//end testNormalisesAValue()

	public function testAPlainDateIsNotParsed(): void {
		$dates = $this->createMock(DateTimeNormalizer::class);
		$dates->expects($this->never())->method('normalize');

		$result = (new ObjectDateValues($dates))->normalize(['when' => '2024-01-15'], ['when' => ['format' => 'date']]);

		$this->assertSame(['when' => '2024-01-15'], $result);
	}//end testAPlainDateIsNotParsed()

	public function testAPropertyTheObjectDoesNotHoldStaysAbsent(): void {
		$this->assertSame([], $this->normalize([], ['when' => ['format' => 'date']]));
	}//end testAPropertyTheObjectDoesNotHoldStaysAbsent()

	/**
	 * @param array<string, mixed> $object     The object data.
	 * @param array<string, mixed> $properties The schema properties.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize(array $object, array $properties): array {
		$dates = $this->createStub(DateTimeNormalizer::class);
		$dates->method('normalize')->willReturnCallback(
			static fn (string $value): ?DateTimeImmutable => str_contains($value, 'T') ? new DateTimeImmutable($value) : null
		);

		return (new ObjectDateValues($dates))->normalize($object, $properties);
	}//end normalize()
}//end class
