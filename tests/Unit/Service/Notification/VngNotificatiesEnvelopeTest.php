<?php

/**
 * Unit tests for VngNotificatiesEnvelope.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Notification\VngNotificatiesEnvelope;
use PHPUnit\Framework\TestCase;

class VngNotificatiesEnvelopeTest extends TestCase
{

    private VngNotificatiesEnvelope $mapper;


    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new VngNotificatiesEnvelope();

    }//end setUp()


    public function testEnvelopeForCreateActionIsVngCompliant(): void
    {
        $when     = new DateTimeImmutable('2026-04-01T12:00:00+0000', new DateTimeZone('UTC'));
        $envelope = $this->mapper->buildEnvelope(
            action: 'create',
            registerSlug: 'zaken',
            schemaSlug: 'zaak',
            objectUuid: 'abc-123',
            baseUrl: 'https://or.example.nl',
            timestamp: $when
        );

        $this->assertSame('zaken', $envelope['kanaal']);
        $this->assertSame('zaak', $envelope['resource']);
        $this->assertSame('https://or.example.nl/api/v1/zaken/abc-123', $envelope['hoofdObject']);
        $this->assertSame('https://or.example.nl/api/v1/zaak/abc-123', $envelope['resourceUrl']);
        $this->assertSame('create', $envelope['actie']);
        $this->assertSame('2026-04-01T12:00:00+00:00', $envelope['aanmaakdatum']);
        $this->assertSame([], $envelope['kenmerken']);

    }//end testEnvelopeForCreateActionIsVngCompliant()


    public function testEnvelopeStripsTrailingSlashFromBaseUrl(): void
    {
        $envelope = $this->mapper->buildEnvelope(
            action: 'create',
            registerSlug: 'zaken',
            schemaSlug: 'zaak',
            objectUuid: 'abc-123',
            baseUrl: 'https://or.example.nl/'
        );

        $this->assertSame('https://or.example.nl/api/v1/zaken/abc-123', $envelope['hoofdObject']);

    }//end testEnvelopeStripsTrailingSlashFromBaseUrl()


    public function testEnvelopeIncludesKenmerkenWhenProvided(): void
    {
        $envelope = $this->mapper->buildEnvelope(
            action: 'update',
            registerSlug: 'zaken',
            schemaSlug: 'zaak',
            objectUuid: 'abc-123',
            baseUrl: 'https://or.example.nl',
            kenmerken: ['zaaktype' => 'https://catalogi.example.nl/zaaktypen/abc', 'status' => 'open']
        );

        $this->assertSame(
            ['zaaktype' => 'https://catalogi.example.nl/zaaktypen/abc', 'status' => 'open'],
            $envelope['kenmerken']
        );

    }//end testEnvelopeIncludesKenmerkenWhenProvided()


    /**
     * @dataProvider provideActionAliases
     */
    public function testActionAliasesMapToVngActieValues(string $orAction, string $expectedVng): void
    {
        $this->assertSame($expectedVng, $this->mapper->mapAction(action: $orAction));

    }//end testActionAliasesMapToVngActieValues()


    public static function provideActionAliases(): array
    {
        return [
            'create'              => ['create',         'create'],
            'created (past tense)' => ['created',        'create'],
            'update'              => ['update',         'update'],
            'updated (past tense)' => ['updated',        'update'],
            'partial_update'      => ['partial_update', 'partial_update'],
            'patched alias'       => ['patched',        'partial_update'],
            'destroy'             => ['destroy',        'destroy'],
            'delete alias'        => ['delete',         'destroy'],
            'deleted alias'       => ['deleted',        'destroy'],
            'case-insensitive'    => ['CREATE',         'create'],
        ];

    }//end provideActionAliases()


    public function testRejectsUnknownAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported action');
        $this->mapper->mapAction(action: 'archive');

    }//end testRejectsUnknownAction()


    public function testTimestampDefaultsToNowInUtcWhenOmitted(): void
    {
        $envelope = $this->mapper->buildEnvelope(
            action: 'create',
            registerSlug: 'zaken',
            schemaSlug: 'zaak',
            objectUuid: 'abc-123',
            baseUrl: 'https://or.example.nl'
        );

        // ISO 8601 with timezone offset, e.g. "2026-04-01T12:00:00+00:00".
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}$/',
            $envelope['aanmaakdatum']
        );

    }//end testTimestampDefaultsToNowInUtcWhenOmitted()


}//end class
