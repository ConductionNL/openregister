<?php

/**
 * SourceRecordResolver unit tests — embedded parity + reverse-FK query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Service\Survivorship;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceRecordResolverTest extends TestCase
{

    private ObjectService&MockObject $objectService;

    private SchemaMapper&MockObject $schemaMapper;

    private LoggerInterface&MockObject $logger;

    private SourceRecordResolver $resolver;

    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->resolver      = new SourceRecordResolver($this->objectService, $this->schemaMapper, $this->logger);
    }//end setUp()

    /**
     * Build an ObjectEntity carrying the given payload.
     *
     * @param array<string, mixed> $payload Object payload.
     *
     * @return ObjectEntity
     */
    private function entity(array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setObject($payload);
        return $entity;
    }//end entity()

    // ── Embedded mode (default) ────────────────────────────────────────────

    public function testEmbeddedReturnsInlineRecords(): void
    {
        $this->objectService->expects($this->never())->method('findAll');

        $sources = $this->resolver->resolveSources(
            masterData: ['sources' => [['sourceSystem' => 'crm', 'values' => ['name' => 'Acme']]]],
            masterUuid: 'master-1',
            config: ['sourceLinkField' => 'sources']
        );

        $this->assertCount(1, $sources);
        $this->assertSame('crm', $sources[0]['sourceSystem']);
    }//end testEmbeddedReturnsInlineRecords()

    public function testEmbeddedResolvesUuidReferences(): void
    {
        $this->objectService->method('find')->willReturn(
            $this->entity(['sourceSystem' => 'kvk', 'values' => ['name' => 'Acme BV']])
        );

        $sources = $this->resolver->resolveSources(
            masterData: ['sources' => ['uuid-a']],
            masterUuid: 'master-1',
            config: ['sourceLinkField' => 'sources']
        );

        $this->assertCount(1, $sources);
        $this->assertSame('kvk', $sources[0]['sourceSystem']);
    }//end testEmbeddedResolvesUuidReferences()

    public function testEmbeddedWithNoSourceLinkFieldReturnsEmpty(): void
    {
        $sources = $this->resolver->resolveSources(
            masterData: ['sources' => [['sourceSystem' => 'crm']]],
            masterUuid: 'master-1',
            config: []
        );

        $this->assertSame([], $sources);
        $this->assertFalse($this->resolver->isReverseFk([]));
    }//end testEmbeddedWithNoSourceLinkFieldReturnsEmpty()

    // ── Reverse-FK mode ────────────────────────────────────────────────────

    public function testReverseFkQueriesSourceSchemaByReferenceField(): void
    {
        $config = [
            'sourceLink' => [
                'mode'           => 'reverseFk',
                'sourceSchema'   => 'sourceRecord',
                'referenceField' => 'currentMasterEntity',
            ],
        ];

        $this->assertTrue($this->resolver->isReverseFk($config));

        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->callback(function (array $arg): bool {
                    return ($arg['filters']['schema'] ?? null) === 'sourceRecord'
                        && ($arg['filters']['currentMasterEntity'] ?? null) === 'master-1';
                })
            )
            ->willReturn([
                $this->entity(['currentMasterEntity' => 'master-1', 'sourceSystem' => 'crm', 'mappedAttributes' => ['name' => 'Acme']]),
                $this->entity(['currentMasterEntity' => 'master-1', 'sourceSystem' => 'kvk', 'mappedAttributes' => ['name' => 'Acme BV']]),
            ]);

        $sources = $this->resolver->resolveSources(masterData: [], masterUuid: 'master-1', config: $config);

        $this->assertCount(2, $sources);
        $this->assertSame(['crm', 'kvk'], array_column($sources, 'sourceSystem'));
    }//end testReverseFkQueriesSourceSchemaByReferenceField()

    public function testReverseFkFiltersOutNonMatchingBackReference(): void
    {
        $config = [
            'sourceLink' => [
                'mode'           => 'reverseFk',
                'sourceSchema'   => 'sourceRecord',
                'referenceField' => 'currentMasterEntity',
            ],
        ];

        // The store returns a stray object pointing at a different master; the
        // resolver must drop it in the PHP re-filter.
        $this->objectService->method('findAll')->willReturn([
            $this->entity(['currentMasterEntity' => 'master-1', 'sourceSystem' => 'crm']),
            $this->entity(['currentMasterEntity' => 'other-master', 'sourceSystem' => 'stray']),
        ]);

        $sources = $this->resolver->resolveSources(masterData: [], masterUuid: 'master-1', config: $config);

        $this->assertCount(1, $sources);
        $this->assertSame('crm', $sources[0]['sourceSystem']);
    }//end testReverseFkFiltersOutNonMatchingBackReference()

    public function testReverseFkWithEmptyMasterUuidReturnsEmpty(): void
    {
        $config = [
            'sourceLink' => [
                'mode'           => 'reverseFk',
                'sourceSchema'   => 'sourceRecord',
                'referenceField' => 'currentMasterEntity',
            ],
        ];

        $this->objectService->expects($this->never())->method('findAll');

        $this->assertSame([], $this->resolver->resolveSources(masterData: [], masterUuid: '', config: $config));
    }//end testReverseFkWithEmptyMasterUuidReturnsEmpty()

    public function testMalformedReverseFkFallsBackToEmbedded(): void
    {
        // reverseFk mode but missing referenceField → degrade to embedded.
        $config = [
            'sourceLinkField' => 'sources',
            'sourceLink'      => ['mode' => 'reverseFk', 'sourceSchema' => 'sourceRecord'],
        ];

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->objectService->expects($this->never())->method('findAll');
        $this->assertFalse($this->resolver->isReverseFk($config));

        $sources = $this->resolver->resolveSources(
            masterData: ['sources' => [['sourceSystem' => 'crm']]],
            masterUuid: 'master-1',
            config: $config
        );

        $this->assertCount(1, $sources);
        $this->assertSame('crm', $sources[0]['sourceSystem']);
    }//end testMalformedReverseFkFallsBackToEmbedded()

    public function testReverseFkDescriptorExposesValidatedShape(): void
    {
        $descriptor = $this->resolver->reverseFkDescriptor([
            'sourceLink' => [
                'mode'           => 'reverseFk',
                'sourceSchema'   => 'sourceRecord',
                'referenceField' => 'currentMasterEntity',
                'sourceRegister' => 'mdm',
            ],
        ]);

        $this->assertSame('sourceRecord', $descriptor['sourceSchema']);
        $this->assertSame('currentMasterEntity', $descriptor['referenceField']);
        $this->assertSame('mdm', $descriptor['sourceRegister']);
        $this->assertNull($this->resolver->reverseFkDescriptor([]));
    }//end testReverseFkDescriptorExposesValidatedShape()
}//end class
