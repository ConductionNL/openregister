<?php

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use OCA\OpenRegister\Service\GraphQL\Scalar\DateTimeType;
use OCA\OpenRegister\Service\GraphQL\Scalar\EmailType;
use OCA\OpenRegister\Service\GraphQL\Scalar\JsonType;
use OCA\OpenRegister\Service\GraphQL\Scalar\UriType;
use OCA\OpenRegister\Service\GraphQL\Scalar\UploadType;
use OCA\OpenRegister\Service\GraphQL\Scalar\UuidType;
use PHPUnit\Framework\TestCase;

class ScalarTypesTest extends TestCase
{
    // ── DateTime ──

    public function testDateTimeSerializesDateTimeInterface(): void
    {
        $scalar = new DateTimeType();
        $dt = new \DateTimeImmutable('2025-06-15T10:30:00+00:00');
        $this->assertSame('2025-06-15T10:30:00+00:00', $scalar->serialize($dt));
    }

    public function testDateTimeSerializesString(): void
    {
        $scalar = new DateTimeType();
        $this->assertSame('2025-06-15', $scalar->serialize('2025-06-15'));
    }

    public function testDateTimeRejectsInteger(): void
    {
        $scalar = new DateTimeType();
        $this->expectException(Error::class);
        $scalar->serialize(12345);
    }

    public function testDateTimeParsesIso8601(): void
    {
        $scalar = new DateTimeType();
        $this->assertSame('2025-06-15T10:30:00+00:00', $scalar->parseValue('2025-06-15T10:30:00+00:00'));
    }

    public function testDateTimeParsesDateOnly(): void
    {
        $scalar = new DateTimeType();
        $this->assertSame('2025-06-15', $scalar->parseValue('2025-06-15'));
    }

    public function testDateTimeRejectsInvalidString(): void
    {
        $scalar = new DateTimeType();
        $this->expectException(Error::class);
        $scalar->parseValue('not-a-date');
    }

    public function testDateTimeRejectsNonString(): void
    {
        $scalar = new DateTimeType();
        $this->expectException(Error::class);
        $scalar->parseValue(12345);
    }

    // ── UUID ──

    public function testUuidSerializesValidUuid(): void
    {
        $scalar = new UuidType();
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame($uuid, $scalar->serialize($uuid));
    }

    public function testUuidRejectsNonStringSerialize(): void
    {
        $scalar = new UuidType();
        $this->expectException(Error::class);
        $scalar->serialize(12345);
    }

    public function testUuidParsesValidUuid(): void
    {
        $scalar = new UuidType();
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertSame($uuid, $scalar->parseValue($uuid));
    }

    public function testUuidRejectsInvalidFormat(): void
    {
        $scalar = new UuidType();
        $this->expectException(Error::class);
        $scalar->parseValue('not-a-uuid');
    }

    public function testUuidRejectsNonString(): void
    {
        $scalar = new UuidType();
        $this->expectException(Error::class);
        $scalar->parseValue(12345);
    }

    // ── Email ──

    public function testEmailSerializesString(): void
    {
        $scalar = new EmailType();
        $this->assertSame('test@example.com', $scalar->serialize('test@example.com'));
    }

    public function testEmailParsesValidEmail(): void
    {
        $scalar = new EmailType();
        $this->assertSame('test@example.com', $scalar->parseValue('test@example.com'));
    }

    public function testEmailRejectsInvalidEmail(): void
    {
        $scalar = new EmailType();
        $this->expectException(Error::class);
        $scalar->parseValue('not-an-email');
    }

    // ── URI ──

    public function testUriSerializesString(): void
    {
        $scalar = new UriType();
        $this->assertSame('https://example.com', $scalar->serialize('https://example.com'));
    }

    public function testUriParsesValidUri(): void
    {
        $scalar = new UriType();
        $this->assertSame('https://example.com/path', $scalar->parseValue('https://example.com/path'));
    }

    public function testUriRejectsInvalidUri(): void
    {
        $scalar = new UriType();
        $this->expectException(Error::class);
        $scalar->parseValue('not a uri');
    }

    // ── JSON ──

    public function testJsonSerializesAnything(): void
    {
        $scalar = new JsonType();
        $this->assertSame(['key' => 'value'], $scalar->serialize(['key' => 'value']));
        $this->assertSame('string', $scalar->serialize('string'));
        $this->assertSame(42, $scalar->serialize(42));
        $this->assertTrue($scalar->serialize(true));
        $this->assertNull($scalar->serialize(null));
    }

    public function testJsonParsesAnything(): void
    {
        $scalar = new JsonType();
        $this->assertSame(['key' => 'value'], $scalar->parseValue(['key' => 'value']));
        $this->assertSame('string', $scalar->parseValue('string'));
    }

    // ── Upload ──

    public function testUploadSerializesValue(): void
    {
        $scalar = new UploadType();
        $value = ['filename' => 'test.pdf'];
        $this->assertSame($value, $scalar->serialize($value));
    }

    public function testUploadParsesArray(): void
    {
        $scalar = new UploadType();
        $value = ['tmp_name' => '/tmp/upload'];
        $this->assertSame($value, $scalar->parseValue($value));
    }

    public function testUploadParsesString(): void
    {
        $scalar = new UploadType();
        $this->assertSame('file-ref-uuid', $scalar->parseValue('file-ref-uuid'));
    }

    public function testUploadRejectsInteger(): void
    {
        $scalar = new UploadType();
        $this->expectException(Error::class);
        $scalar->parseValue(12345);
    }
}
