<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Unit tests for DateTimeNormalizer — the canonical conversion point for
 * user-supplied datetime values introduced by
 * `fix-empty-string-date-conversion`.
 */
class DateTimeNormalizerTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	private DateTimeNormalizer $normalizer;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->normalizer = new DateTimeNormalizer($this->logger);
	}//end setUp()

	public function testNullInputReturnsNull(): void {
		$this->logger->expects($this->never())->method('debug');
		$this->assertNull($this->normalizer->normalize(null));
	}//end testNullInputReturnsNull()

	public function testEmptyStringReturnsNull(): void {
		$this->logger->expects($this->never())->method('debug');
		$this->assertNull($this->normalizer->normalize(''));
	}//end testEmptyStringReturnsNull()

	/**
	 * @dataProvider whitespaceOnlyProvider
	 */
	public function testWhitespaceOnlyReturnsNull(string $value): void {
		$this->logger->expects($this->never())->method('debug');
		$this->assertNull($this->normalizer->normalize($value));
	}//end testWhitespaceOnlyReturnsNull()

	public static function whitespaceOnlyProvider(): array {
		return [
			'spaces' => ['   '],
			'tab' => ["\t"],
			'newline' => ["\n"],
			'mixed' => [" \t\n "],
		];
	}//end whitespaceOnlyProvider()

	public function testIso8601WithOffsetReturnsImmutable(): void {
		$result = $this->normalizer->normalize('2026-04-20T14:00:00+02:00');
		$this->assertInstanceOf(DateTimeImmutable::class, $result);
		$this->assertSame('2026-04-20T14:00:00+02:00', $result->format(DateTimeInterface::ATOM));
	}//end testIso8601WithOffsetReturnsImmutable()

	public function testIso8601ZuluReturnsImmutable(): void {
		$result = $this->normalizer->normalize('2026-04-20T14:00:00Z');
		$this->assertInstanceOf(DateTimeImmutable::class, $result);
		$this->assertSame('2026-04-20T14:00:00+00:00', $result->format(DateTimeInterface::ATOM));
	}//end testIso8601ZuluReturnsImmutable()

	public function testDatabaseFormatReturnsImmutable(): void {
		$result = $this->normalizer->normalize('2026-04-20 14:00:00');
		$this->assertInstanceOf(DateTimeImmutable::class, $result);
		$this->assertSame('2026-04-20 14:00:00', $result->format('Y-m-d H:i:s'));
	}//end testDatabaseFormatReturnsImmutable()

	public function testDateOnlyReturnsImmutableAtMidnight(): void {
		$result = $this->normalizer->normalize('2026-04-20');
		$this->assertInstanceOf(DateTimeImmutable::class, $result);
		$this->assertSame('00:00:00', $result->format('H:i:s'));
		$this->assertSame('2026-04-20', $result->format('Y-m-d'));
	}//end testDateOnlyReturnsImmutableAtMidnight()

	public function testExistingDateTimeImmutablePassesThrough(): void {
		$input = new DateTimeImmutable('2026-04-20T14:00:00+00:00');
		$result = $this->normalizer->normalize($input);
		$this->assertSame($input, $result);
	}//end testExistingDateTimeImmutablePassesThrough()

	public function testMutableDateTimeConvertsToImmutable(): void {
		$input = new DateTime('2026-04-20T14:00:00+00:00');
		$result = $this->normalizer->normalize($input);
		$this->assertInstanceOf(DateTimeImmutable::class, $result);
		$this->assertSame($input->getTimestamp(), $result->getTimestamp());
	}//end testMutableDateTimeConvertsToImmutable()

	public function testGarbledStringReturnsNullAndLogs(): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('Unparseable'));
		$this->assertNull($this->normalizer->normalize('not-a-date'));
	}//end testGarbledStringReturnsNullAndLogs()

	/**
	 * @dataProvider nonStringNonDateTimeProvider
	 */
	public function testNonStringNonDateTimeReturnsNullAndLogs(mixed $value): void {
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->stringContains('Non-string'));
		$this->assertNull($this->normalizer->normalize($value));
	}//end testNonStringNonDateTimeReturnsNullAndLogs()

	public static function nonStringNonDateTimeProvider(): array {
		return [
			'integer' => [1745150400],
			'float' => [1745150400.5],
			'bool' => [true],
			'array' => [['2026-04-20']],
			'object' => [new stdClass()],
		];
	}//end nonStringNonDateTimeProvider()

	public function testFormatForDatabaseOnEmptyReturnsNull(): void {
		$this->assertNull($this->normalizer->formatForDatabase(''));
		$this->assertNull($this->normalizer->formatForDatabase(null));
		$this->assertNull($this->normalizer->formatForDatabase('   '));
	}//end testFormatForDatabaseOnEmptyReturnsNull()

	public function testFormatForDatabaseOnValidProducesYMDHIS(): void {
		$this->assertSame(
			'2026-04-20 14:00:00',
			$this->normalizer->formatForDatabase('2026-04-20T14:00:00+00:00')
		);
	}//end testFormatForDatabaseOnValidProducesYMDHIS()

	public function testFormatForDatabaseOnGarbledReturnsNull(): void {
		$this->logger->expects($this->once())->method('debug');
		$this->assertNull($this->normalizer->formatForDatabase('not-a-date'));
	}//end testFormatForDatabaseOnGarbledReturnsNull()

	public function testFormatForIso8601OnEmptyReturnsNull(): void {
		$this->assertNull($this->normalizer->formatForIso8601(''));
		$this->assertNull($this->normalizer->formatForIso8601(null));
		$this->assertNull($this->normalizer->formatForIso8601('   '));
	}//end testFormatForIso8601OnEmptyReturnsNull()

	public function testFormatForIso8601OnValidProducesIso8601WithOffset(): void {
		$result = $this->normalizer->formatForIso8601('2026-04-20 14:00:00');
		$this->assertIsString($result);
		// Must contain both date+time and a timezone offset.
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
			$result
		);
	}//end testFormatForIso8601OnValidProducesIso8601WithOffset()

	public function testFormatForIso8601OnGarbledReturnsNull(): void {
		$this->logger->expects($this->once())->method('debug');
		$this->assertNull($this->normalizer->formatForIso8601('not-a-date'));
	}//end testFormatForIso8601OnGarbledReturnsNull()

	// ------------------------------------------------------------------
	// WOO-567 — a date-time with a non-UTC offset must keep its INSTANT
	// across the database round-trip. `format()` renders in whatever
	// timezone the instance carries, so rendering `…T00:00:00+02:00`
	// straight to `Y-m-d H:i:s` dropped the offset instead of applying
	// it, and the offset-less column value was then read back as UTC —
	// moving a WMEBV objection deadline two hours forward.
	// ------------------------------------------------------------------

	/**
	 * @dataProvider offsetToUtcProvider
	 */
	public function testFormatForDatabaseConvertsOffsetToUtc(string $input, string $expected): void {
		$this->assertSame($expected, $this->normalizer->formatForDatabase($input));
	}//end testFormatForDatabaseConvertsOffsetToUtc()

	public static function offsetToUtcProvider(): array {
		return [
			// The reported case: a +02:00 deadline must move BACK two hours in
			// the column, not keep its clock time.
			'positive offset' => ['2026-10-20T00:00:00+02:00', '2026-10-19 22:00:00'],
			'positive offset midday' => ['2026-09-08T10:46:00+02:00', '2026-09-08 08:46:00'],
			// A negative offset must move forward, proving the conversion is
			// signed and not a hardcoded European shift.
			'negative offset' => ['2026-10-20T00:00:00-05:00', '2026-10-20 05:00:00'],
			// Already UTC, in both spellings: unchanged.
			'explicit utc offset' => ['2026-10-20T00:00:00+00:00', '2026-10-20 00:00:00'],
			'zulu' => ['2026-10-20T00:00:00Z', '2026-10-20 00:00:00'],
			// A naive value carries no offset, so it is taken as already being
			// in the column's timezone and is not shifted.
			'naive datetime' => ['2026-10-20 00:00:00', '2026-10-20 00:00:00'],
			'date only' => ['2026-10-20', '2026-10-20 00:00:00'],
		];
	}//end offsetToUtcProvider()

	public function testFormatForDatabaseConvertsDateTimeObjectToUtc(): void {
		$value = new DateTimeImmutable('2026-10-20T00:00:00+02:00');
		$this->assertSame('2026-10-19 22:00:00', $this->normalizer->formatForDatabase($value));

		$mutable = new DateTime('2026-10-20T00:00:00-05:00');
		$this->assertSame('2026-10-20 05:00:00', $this->normalizer->formatForDatabase($mutable));
	}//end testFormatForDatabaseConvertsDateTimeObjectToUtc()

	public function testFormatDatabaseValueForIso8601ReadsNaiveColumnAsUtc(): void {
		$this->assertSame(
			'2026-10-19T22:00:00+00:00',
			$this->normalizer->formatDatabaseValueForIso8601('2026-10-19 22:00:00')
		);
	}//end testFormatDatabaseValueForIso8601ReadsNaiveColumnAsUtc()

	public function testFormatDatabaseValueForIso8601OnEmptyReturnsNull(): void {
		$this->assertNull($this->normalizer->formatDatabaseValueForIso8601(''));
		$this->assertNull($this->normalizer->formatDatabaseValueForIso8601(null));
		$this->assertNull($this->normalizer->formatDatabaseValueForIso8601('   '));
	}//end testFormatDatabaseValueForIso8601OnEmptyReturnsNull()

	/**
	 * The end-to-end contract: write then read must land on the same instant.
	 *
	 * @dataProvider roundTripProvider
	 */
	public function testWriteThenReadPreservesTheInstant(string $input): void {
		$stored = $this->normalizer->formatForDatabase($input);
		$this->assertIsString($stored);

		$readBack = $this->normalizer->formatDatabaseValueForIso8601($stored);
		$this->assertIsString($readBack);

		$this->assertSame(
			(new DateTimeImmutable($input))->getTimestamp(),
			(new DateTimeImmutable($readBack))->getTimestamp(),
			'The round-trip moved the instant for input ' . $input
		);
	}//end testWriteThenReadPreservesTheInstant()

	public static function roundTripProvider(): array {
		return [
			'positive offset' => ['2026-10-20T00:00:00+02:00'],
			'negative offset' => ['2026-10-20T00:00:00-05:00'],
			'half-hour offset' => ['2026-10-20T00:00:00+05:30'],
			'zulu' => ['2026-10-20T00:00:00Z'],
			'winter time' => ['2026-01-15T09:30:00+01:00'],
		];
	}//end roundTripProvider()

	/**
	 * The round-trip must not depend on the server's `date.timezone`: the
	 * column carries no offset, so both directions have to agree on UTC
	 * rather than on whatever PHP happens to default to.
	 *
	 * @dataProvider serverTimezoneProvider
	 */
	public function testRoundTripIsIndependentOfServerTimezone(string $serverTimezone): void {
		$original = date_default_timezone_get();
		date_default_timezone_set($serverTimezone);

		try {
			$stored = $this->normalizer->formatForDatabase('2026-10-20T00:00:00+02:00');
			$this->assertSame('2026-10-19 22:00:00', $stored);

			$this->assertSame(
				'2026-10-19T22:00:00+00:00',
				$this->normalizer->formatDatabaseValueForIso8601($stored)
			);
		} finally {
			date_default_timezone_set($original);
		}
	}//end testRoundTripIsIndependentOfServerTimezone()

	public static function serverTimezoneProvider(): array {
		return [
			'utc' => ['UTC'],
			'amsterdam' => ['Europe/Amsterdam'],
			'new york' => ['America/New_York'],
			'kathmandu' => ['Asia/Kathmandu'],
		];
	}//end serverTimezoneProvider()

	public function testNormalizeAppliesAssumedTimezoneOnlyToNaiveStrings(): void {
		$utc = new DateTimeZone('UTC');

		// Naive: the assumed timezone is applied.
		$naive = $this->normalizer->normalize('2026-10-20 00:00:00', $utc);
		$this->assertInstanceOf(DateTimeImmutable::class, $naive);
		$this->assertSame('+00:00', $naive->format('P'));

		// Offset-bearing: the string's own offset wins, and the instant is
		// whatever the caller sent.
		$explicit = $this->normalizer->normalize('2026-10-20T00:00:00+02:00', $utc);
		$this->assertInstanceOf(DateTimeImmutable::class, $explicit);
		$this->assertSame('+02:00', $explicit->format('P'));
		$this->assertSame(
			(new DateTimeImmutable('2026-10-19T22:00:00Z'))->getTimestamp(),
			$explicit->getTimestamp()
		);
	}//end testNormalizeAppliesAssumedTimezoneOnlyToNaiveStrings()

}//end class
