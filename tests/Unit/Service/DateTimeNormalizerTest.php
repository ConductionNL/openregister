<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
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
class DateTimeNormalizerTest extends TestCase
{

    private LoggerInterface&MockObject $logger;

    private DateTimeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->normalizer = new DateTimeNormalizer($this->logger);
    }//end setUp()

    public function testNullInputReturnsNull(): void
    {
        $this->logger->expects($this->never())->method('debug');
        $this->assertNull($this->normalizer->normalize(null));
    }//end testNullInputReturnsNull()

    public function testEmptyStringReturnsNull(): void
    {
        $this->logger->expects($this->never())->method('debug');
        $this->assertNull($this->normalizer->normalize(''));
    }//end testEmptyStringReturnsNull()

    /**
     * @dataProvider whitespaceOnlyProvider
     */
    public function testWhitespaceOnlyReturnsNull(string $value): void
    {
        $this->logger->expects($this->never())->method('debug');
        $this->assertNull($this->normalizer->normalize($value));
    }//end testWhitespaceOnlyReturnsNull()

    public static function whitespaceOnlyProvider(): array
    {
        return [
            'spaces' => ['   '],
            'tab'    => ["\t"],
            'newline' => ["\n"],
            'mixed'  => [" \t\n "],
        ];
    }//end whitespaceOnlyProvider()

    public function testIso8601WithOffsetReturnsImmutable(): void
    {
        $result = $this->normalizer->normalize('2026-04-20T14:00:00+02:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-20T14:00:00+02:00', $result->format(DateTimeInterface::ATOM));
    }//end testIso8601WithOffsetReturnsImmutable()

    public function testIso8601ZuluReturnsImmutable(): void
    {
        $result = $this->normalizer->normalize('2026-04-20T14:00:00Z');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-20T14:00:00+00:00', $result->format(DateTimeInterface::ATOM));
    }//end testIso8601ZuluReturnsImmutable()

    public function testDatabaseFormatReturnsImmutable(): void
    {
        $result = $this->normalizer->normalize('2026-04-20 14:00:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2026-04-20 14:00:00', $result->format('Y-m-d H:i:s'));
    }//end testDatabaseFormatReturnsImmutable()

    public function testDateOnlyReturnsImmutableAtMidnight(): void
    {
        $result = $this->normalizer->normalize('2026-04-20');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('00:00:00', $result->format('H:i:s'));
        $this->assertSame('2026-04-20', $result->format('Y-m-d'));
    }//end testDateOnlyReturnsImmutableAtMidnight()

    public function testExistingDateTimeImmutablePassesThrough(): void
    {
        $input  = new DateTimeImmutable('2026-04-20T14:00:00+00:00');
        $result = $this->normalizer->normalize($input);
        $this->assertSame($input, $result);
    }//end testExistingDateTimeImmutablePassesThrough()

    public function testMutableDateTimeConvertsToImmutable(): void
    {
        $input  = new DateTime('2026-04-20T14:00:00+00:00');
        $result = $this->normalizer->normalize($input);
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($input->getTimestamp(), $result->getTimestamp());
    }//end testMutableDateTimeConvertsToImmutable()

    public function testGarbledStringReturnsNullAndLogs(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Unparseable'));
        $this->assertNull($this->normalizer->normalize('not-a-date'));
    }//end testGarbledStringReturnsNullAndLogs()

    /**
     * @dataProvider nonStringNonDateTimeProvider
     */
    public function testNonStringNonDateTimeReturnsNullAndLogs(mixed $value): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Non-string'));
        $this->assertNull($this->normalizer->normalize($value));
    }//end testNonStringNonDateTimeReturnsNullAndLogs()

    public static function nonStringNonDateTimeProvider(): array
    {
        return [
            'integer' => [1745150400],
            'float'   => [1745150400.5],
            'bool'    => [true],
            'array'   => [['2026-04-20']],
            'object'  => [new stdClass()],
        ];
    }//end nonStringNonDateTimeProvider()

    public function testFormatForDatabaseOnEmptyReturnsNull(): void
    {
        $this->assertNull($this->normalizer->formatForDatabase(''));
        $this->assertNull($this->normalizer->formatForDatabase(null));
        $this->assertNull($this->normalizer->formatForDatabase('   '));
    }//end testFormatForDatabaseOnEmptyReturnsNull()

    public function testFormatForDatabaseOnValidProducesYMDHIS(): void
    {
        $this->assertSame(
            '2026-04-20 14:00:00',
            $this->normalizer->formatForDatabase('2026-04-20T14:00:00+00:00')
        );
    }//end testFormatForDatabaseOnValidProducesYMDHIS()

    public function testFormatForDatabaseOnGarbledReturnsNull(): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->assertNull($this->normalizer->formatForDatabase('not-a-date'));
    }//end testFormatForDatabaseOnGarbledReturnsNull()

    public function testFormatForIso8601OnEmptyReturnsNull(): void
    {
        $this->assertNull($this->normalizer->formatForIso8601(''));
        $this->assertNull($this->normalizer->formatForIso8601(null));
        $this->assertNull($this->normalizer->formatForIso8601('   '));
    }//end testFormatForIso8601OnEmptyReturnsNull()

    public function testFormatForIso8601OnValidProducesIso8601WithOffset(): void
    {
        $result = $this->normalizer->formatForIso8601('2026-04-20 14:00:00');
        $this->assertIsString($result);
        // Must contain both date+time and a timezone offset.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $result
        );
    }//end testFormatForIso8601OnValidProducesIso8601WithOffset()

    public function testFormatForIso8601OnGarbledReturnsNull(): void
    {
        $this->logger->expects($this->once())->method('debug');
        $this->assertNull($this->normalizer->formatForIso8601('not-a-date'));
    }//end testFormatForIso8601OnGarbledReturnsNull()

}//end class
