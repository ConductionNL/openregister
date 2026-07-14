<?php

/**
 * MappingEngineTest
 *
 * Unit tests for `MappingEngine::mapRow()`: the transform matrix (trim,
 * date, bool-map, concat, lookup, const), required-field errors, the
 * literal-leak guard (an unresolved lookup key must ERROR the row, never
 * pass the raw source value through), idStrategy resolution, skipRows, and
 * JSON-Pointer source resolution for nested rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use PHPUnit\Framework\TestCase;

class MappingEngineTest extends TestCase
{
    private MappingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new MappingEngine();
    }

    private function pack(array $fieldMappings, array $extra=[]): array
    {
        return array_merge(
            [
                'fieldMappings' => $fieldMappings,
                'idStrategy'    => ['type' => 'generate'],
            ],
            $extra
        );
    }

    public function testIdentityMappingWithoutTransform(): void
    {
        $pack   = $this->pack([['source' => 'Name', 'target' => 'title']]);
        $result = $this->engine->mapRow($pack, ['Name' => 'Acme'], 1);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Acme', $result['data']['title']);
    }

    public function testTrimTransform(): void
    {
        $pack   = $this->pack([['source' => 'Name', 'target' => 'title', 'transform' => ['type' => 'trim']]]);
        $result = $this->engine->mapRow($pack, ['Name' => '  Acme  '], 1);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Acme', $result['data']['title']);
    }

    public function testDateTransformParsesSourceFormatAndEmitsTargetFormat(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'Start',
                    'target'    => 'startDate',
                    'transform' => ['type' => 'date', 'sourceFormat' => 'd-m-Y', 'targetFormat' => 'Y-m-d'],
                ],
            ]
        );
        $result = $this->engine->mapRow($pack, ['Start' => '05-01-2024'], 1);

        $this->assertSame([], $result['errors']);
        $this->assertSame('2024-01-05', $result['data']['startDate']);
    }

    public function testDateTransformErrorsOnUnparseableValue(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'Start',
                    'target'    => 'startDate',
                    'transform' => ['type' => 'date', 'sourceFormat' => 'd-m-Y'],
                ],
            ]
        );
        $result = $this->engine->mapRow($pack, ['Start' => 'not-a-date'], 3);

        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['row']);
        $this->assertArrayNotHasKey('startDate', $result['data']);
    }

    public function testBoolMapTransform(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'Actief',
                    'target'    => 'active',
                    'transform' => ['type' => 'bool-map', 'map' => ['J' => true, 'N' => false]],
                ],
            ]
        );

        $resultYes = $this->engine->mapRow($pack, ['Actief' => 'J'], 1);
        $this->assertSame([], $resultYes['errors']);
        $this->assertTrue($resultYes['data']['active']);

        $resultNo = $this->engine->mapRow($pack, ['Actief' => 'N'], 2);
        $this->assertSame([], $resultNo['errors']);
        $this->assertFalse($resultNo['data']['active']);
    }

    public function testBoolMapUsesDefaultWhenKeyUnresolved(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'Actief',
                    'target'    => 'active',
                    'transform' => ['type' => 'bool-map', 'map' => ['J' => true], 'default' => false],
                ],
            ]
        );

        $result = $this->engine->mapRow($pack, ['Actief' => 'onbekend'], 1);
        $this->assertSame([], $result['errors']);
        $this->assertFalse($result['data']['active']);
    }

    public function testConcatTransformJoinsPrimaryAndExtraFields(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'First',
                    'target'    => 'fullName',
                    'transform' => ['type' => 'concat', 'fields' => ['Last'], 'separator' => ' '],
                ],
            ]
        );

        $result = $this->engine->mapRow($pack, ['First' => 'Jan', 'Last' => 'Jansen'], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Jan Jansen', $result['data']['fullName']);
    }

    public function testConcatTreatsMissingExtraFieldAsEmptyString(): void
    {
        $pack = $this->pack(
            [
                ['source' => 'First', 'target' => 'fullName', 'transform' => ['type' => 'concat', 'fields' => ['Middle', 'Last']]],
            ]
        );

        $result = $this->engine->mapRow($pack, ['First' => 'Jan', 'Last' => 'Jansen'], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Jan  Jansen', $result['data']['fullName']);
    }

    public function testConstTransformAlwaysAppliesRegardlessOfSource(): void
    {
        $pack = $this->pack(
            [
                ['source' => 'AnythingOrNothing', 'target' => 'status', 'transform' => ['type' => 'const', 'value' => 'gemigreerd']],
            ]
        );

        $result = $this->engine->mapRow($pack, [], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('gemigreerd', $result['data']['status']);
    }

    /**
     * Literal-leak guard: an unresolved lookup key (source value present,
     * no matching map entry, no default configured) must ERROR the row —
     * never pass the raw source value through as the target's value.
     */
    public function testLookupTransformErrorsOnUnresolvedKeyWithNoDefault(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'ZaakType',
                    'target'    => 'zaakTypeCode',
                    'transform' => ['type' => 'lookup', 'map' => ['https://example.com/known' => 'KNOWN']],
                ],
            ]
        );

        $result = $this->engine->mapRow($pack, ['ZaakType' => 'https://example.com/unmapped'], 5);

        $this->assertCount(1, $result['errors']);
        $this->assertSame(5, $result['errors'][0]['row']);
        $this->assertSame('ZaakType', $result['errors'][0]['source']);
        $this->assertSame('lookup', $result['errors'][0]['transform']);
        $this->assertArrayNotHasKey('zaakTypeCode', $result['data']);
    }

    public function testLookupTransformResolvesKnownKey(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'ZaakType',
                    'target'    => 'zaakTypeCode',
                    'transform' => ['type' => 'lookup', 'map' => ['https://example.com/known' => 'KNOWN']],
                ],
            ]
        );

        $result = $this->engine->mapRow($pack, ['ZaakType' => 'https://example.com/known'], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('KNOWN', $result['data']['zaakTypeCode']);
    }

    public function testLookupTransformUsesDefaultWhenConfigured(): void
    {
        $pack = $this->pack(
            [
                [
                    'source'    => 'ZaakType',
                    'target'    => 'zaakTypeCode',
                    'transform' => ['type' => 'lookup', 'map' => ['a' => 'A'], 'default' => 'UNKNOWN'],
                ],
            ]
        );

        $result = $this->engine->mapRow($pack, ['ZaakType' => 'b'], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('UNKNOWN', $result['data']['zaakTypeCode']);
    }

    public function testRequiredFieldMissingIsAnError(): void
    {
        $pack = $this->pack([['source' => 'Name', 'target' => 'title', 'required' => true]]);

        $result = $this->engine->mapRow($pack, ['Name' => ''], 2);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Required', $result['errors'][0]['message']);
        $this->assertArrayNotHasKey('title', $result['data']);
    }

    public function testOptionalFieldMissingIsSilentlySkipped(): void
    {
        $pack   = $this->pack([['source' => 'Nickname', 'target' => 'nickname']]);
        $result = $this->engine->mapRow($pack, ['Name' => 'Acme'], 1);

        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('nickname', $result['data']);
    }

    public function testDefaultsSurviveWhenOptionalMappingIsEmpty(): void
    {
        $pack = $this->pack(
            [['source' => 'Nickname', 'target' => 'nickname']],
            ['defaults' => ['nickname' => 'n/a']]
        );

        $result = $this->engine->mapRow($pack, [], 1);
        $this->assertSame([], $result['errors']);
        $this->assertSame('n/a', $result['data']['nickname']);
    }

    public function testIdStrategyGenerateLeavesIdUnset(): void
    {
        $pack   = $this->pack([['source' => 'Name', 'target' => 'title']], ['idStrategy' => ['type' => 'generate']]);
        $result = $this->engine->mapRow($pack, ['Name' => 'Acme'], 1);

        $this->assertArrayNotHasKey('id', $result['data']);
    }

    public function testIdStrategySourceFieldSetsId(): void
    {
        $pack = $this->pack(
            [['source' => 'Name', 'target' => 'title']],
            ['idStrategy' => ['type' => 'sourceField', 'field' => 'Uuid']]
        );
        $result = $this->engine->mapRow($pack, ['Name' => 'Acme', 'Uuid' => 'abc-123'], 1);

        $this->assertSame('abc-123', $result['data']['id']);
    }

    public function testJsonPointerSourceResolvesNestedValue(): void
    {
        $pack   = $this->pack([['source' => '/zaak/status', 'target' => 'status']]);
        $result = $this->engine->mapRow($pack, ['zaak' => ['status' => 'open']], 1);

        $this->assertSame([], $result['errors']);
        $this->assertSame('open', $result['data']['status']);
    }

    public function testJsonPointerSourceMissingResolvesToNull(): void
    {
        $pack   = $this->pack([['source' => '/zaak/missing', 'target' => 'status']]);
        $result = $this->engine->mapRow($pack, ['zaak' => ['status' => 'open']], 1);

        $this->assertSame([], $result['errors']);
        $this->assertArrayNotHasKey('status', $result['data']);
    }

    public function testIsRowSkippedRespectsSkipRows(): void
    {
        $pack = ['skipRows' => [2, 4]];

        $this->assertFalse($this->engine->isRowSkipped($pack, 1));
        $this->assertTrue($this->engine->isRowSkipped($pack, 2));
        $this->assertFalse($this->engine->isRowSkipped($pack, 3));
        $this->assertTrue($this->engine->isRowSkipped($pack, 4));
    }

    public function testIsRowSkippedDefaultsToFalseWhenNoSkipRows(): void
    {
        $this->assertFalse($this->engine->isRowSkipped([], 1));
    }
}
