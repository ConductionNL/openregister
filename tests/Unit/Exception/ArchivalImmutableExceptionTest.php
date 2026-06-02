<?php

/**
 * Unit tests for ArchivalImmutableException.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace Unit\Exception;

use OCA\OpenRegister\Exception\ArchivalImmutableException;
use PHPUnit\Framework\TestCase;

final class ArchivalImmutableExceptionTest extends TestCase
{
    public function testExceptionCarriesStatus403(): void
    {
        $exception = new ArchivalImmutableException(schemaIdentifier: 'call_log');
        self::assertSame(403, $exception->getCode());
    }//end testExceptionCarriesStatus403()

    public function testExceptionExposesSchemaIdentifier(): void
    {
        $exception = new ArchivalImmutableException(schemaIdentifier: 'call_log');
        self::assertSame('call_log', $exception->getSchemaIdentifier());
    }//end testExceptionExposesSchemaIdentifier()

    public function testStructuredResponseBody(): void
    {
        $exception = new ArchivalImmutableException(schemaIdentifier: 'call_log');
        $body      = $exception->toResponseBody();

        self::assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', $body['error']);
        self::assertSame('call_log', $body['schema']);
        self::assertSame('delete', $body['operation']);
        self::assertStringContainsString('archival', strtolower($body['message']));
        self::assertStringContainsString('ArchivalRetentionTask', $body['hint']);
    }//end testStructuredResponseBody()
}//end class
