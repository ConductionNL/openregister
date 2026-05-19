<?php

/**
 * VngNotificatiesEnvelopeTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Notification\VngNotificatiesEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VngNotificatiesEnvelope.
 */
class VngNotificatiesEnvelopeTest extends TestCase
{

    private VngNotificatiesEnvelope $envelope;

    protected function setUp(): void
    {
        $this->envelope = new VngNotificatiesEnvelope();
    }

    /**
     * Full create envelope shape.
     */
    public function testFullCreateEnvelopeShape(): void
    {
        $ts  = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $env = $this->envelope->build(
            action: 'create',
            register: 'zaken',
            schema: 'zaak',
            objectUuid: 'abc-123',
            baseUrl: 'https://example.com',
            timestamp: $ts
        );

        self::assertSame('zaken.zaak', $env['kanaal']);
        self::assertSame('https://example.com/api/registers/zaken/zaak/abc-123', $env['hoofdObject']);
        self::assertSame('zaak', $env['resource']);
        self::assertSame('https://example.com/api/registers/zaken/zaak/abc-123', $env['resourceUrl']);
        self::assertSame('create', $env['actie']);
        self::assertStringContainsString('2026-01-01', $env['aanmaakdatum']);
        self::assertSame([], $env['kenmerken']);
    }

    /**
     * Trailing slash in baseUrl is stripped.
     */
    public function testTrailingSlashNormalisation(): void
    {
        $env = $this->envelope->build(
            action: 'create',
            register: 'r',
            schema: 's',
            objectUuid: 'u',
            baseUrl: 'https://example.com/'
        );

        self::assertStringStartsWith('https://example.com/', $env['hoofdObject']);
        self::assertStringNotContainsString('//', str_replace('https://', '', $env['hoofdObject']));
    }

    /**
     * Kenmerken are passed through unchanged.
     */
    public function testKenmerkenPassthrough(): void
    {
        $kenmerken = ['bronorganisatie' => '051845623'];
        $env       = $this->envelope->build(
            action: 'create',
            register: 'r',
            schema: 's',
            objectUuid: 'u',
            baseUrl: 'https://example.com',
            kenmerken: $kenmerken
        );

        self::assertSame($kenmerken, $env['kenmerken']);
    }

    /**
     * @dataProvider actionMappingProvider
     */
    public function testActionMapping(string $input, string $expected): void
    {
        self::assertSame($expected, $this->envelope->mapAction(action: $input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function actionMappingProvider(): array
    {
        return [
            'create'         => ['create', 'create'],
            'created'        => ['created', 'create'],
            'update'         => ['update', 'update'],
            'updated'        => ['updated', 'update'],
            'partial_update' => ['partial_update', 'partial_update'],
            'patched'        => ['patched', 'partial_update'],
            'destroy'        => ['destroy', 'destroy'],
            'delete'         => ['delete', 'destroy'],
            'deleted'        => ['deleted', 'destroy'],
            'CREATE_UPPER'   => ['CREATE', 'create'],
        ];
    }

    /**
     * Unknown action throws InvalidArgumentException.
     */
    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->envelope->mapAction(action: 'frobulate');
    }

    /**
     * ISO 8601 timestamp default when none provided.
     */
    public function testIso8601TimestampDefault(): void
    {
        $env = $this->envelope->build(
            action: 'create',
            register: 'r',
            schema: 's',
            objectUuid: 'u',
            baseUrl: 'https://example.com'
        );

        // Should parse as a valid date.
        self::assertNotFalse(strtotime(datetime: $env['aanmaakdatum']));
    }
}
