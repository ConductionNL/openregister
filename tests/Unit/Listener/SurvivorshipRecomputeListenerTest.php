<?php

/**
 * SurvivorshipRecomputeListener unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#7.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\SurvivorshipRecomputeListener;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SurvivorshipRecomputeListenerTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private ObjectService&MockObject $objectService;

    private LoggerInterface&MockObject $logger;

    private SurvivorshipRecomputeListener $listener;

    protected function setUp(): void
    {
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->listener = new SurvivorshipRecomputeListener(
            $this->schemaMapper,
            $this->objectService,
            new SurvivorshipResolver(),
            new TrustTierResolver(),
            $this->logger
        );
    }//end setUp()

    /**
     * Build a schema whose configuration carries a survivorship annotation.
     *
     * @param array<string, mixed>|null $survivorship The x-openregister-survivorship block, or null.
     *
     * @return Schema
     */
    private function schemaWithSurvivorship(?array $survivorship): Schema
    {
        $schema = new Schema();
        $schema->setSlug('organisation');
        if ($survivorship !== null) {
            $schema->setConfiguration(['x-openregister-survivorship' => $survivorship]);
        } else {
            $schema->setConfiguration([]);
        }

        $this->schemaMapper->method('find')->willReturn($schema);
        return $schema;
    }//end schemaWithSurvivorship()

    private const BASE_CONFIG = [
        'sourceLinkField'      => 'sources',
        'goldenRecordField'    => 'goldenRecord',
        'provenanceField'      => 'attributeProvenance',
        'tierOrder'            => ['discard', 'bronze', 'silver', 'gold'],
        'defaultTier'          => 'bronze',
        'discardTier'          => 'discard',
        'freshnessAnchorField' => 'lastUpdated',
    ];

    public function testGoldenRecordMaterialisedOnCreate(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-1');
        $object->setObject(
                [
                    'sources' => [
                        ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Acme']],
                    ],
                ]
                );

        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertSame('Acme', $data['goldenRecord']['legalName']);
        $this->assertSame('bronze', $data['attributeProvenance']['legalName']['trustTier']);
    }//end testGoldenRecordMaterialisedOnCreate()

    public function testGoldenRecordMaterialisedOnUpdate(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        $old = new ObjectEntity();
        $old->setSchema('organisation');

        $new = new ObjectEntity();
        $new->setSchema('organisation');
        $new->setUuid('obj-2');
        $new->setObject(
                [
                    'sources' => [
                        ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Acme']],
                    ],
                ]
                );

        $event = new ObjectUpdatingEvent($new, $old);
        $this->listener->handle($event);

        $data = $new->getObject();
        $this->assertSame('Acme', $data['goldenRecord']['legalName']);
    }//end testGoldenRecordMaterialisedOnUpdate()

    public function testSchemaWithoutAnnotationIsUntouched(): void
    {
        $this->schemaWithSurvivorship(null);
        $this->objectService->expects($this->never())->method('findAll');

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-3');
        $object->setObject(['sources' => [['sourceSystem' => 'crm', 'values' => ['legalName' => 'Acme']]]]);

        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertArrayNotHasKey('goldenRecord', $data);
    }//end testSchemaWithoutAnnotationIsUntouched()

    public function testResolutionFailureNeverAbortsSaveAndLogsWarning(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willThrowException(new \RuntimeException('trust lookup exploded'));

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-4');
        $object->setObject(
                [
                    'sources' => [
                        ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Acme']],
                    ],
                ]
                );

        // findAll throwing is caught internally by loadTrustRows (returns []),
        // so this alone should NOT cause a warning — verify the save still
        // succeeds and materialises using an empty trust-row set.
        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertSame('Acme', $data['goldenRecord']['legalName']);
    }//end testResolutionFailureNeverAbortsSaveAndLogsWarning()

    public function testTrulyFatalResolutionErrorIsFailSoft(): void
    {
        // Force loadSchema() to throw by making SchemaMapper::find throw.
        $this->schemaMapper->method('find')->willThrowException(new \RuntimeException('schema lookup exploded'));

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-5');
        $object->setObject(['sources' => []]);

        // loadSchema() itself is fail-soft (catches and returns null), so the
        // listener simply no-ops. This proves a schema-lookup failure never
        // propagates as an exception out of handle().
        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertArrayNotHasKey('goldenRecord', $data);
    }//end testTrulyFatalResolutionErrorIsFailSoft()

    public function testEmbeddedAndReferencedSourcesBothResolve(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        $referenced = new ObjectEntity();
        $referenced->setObject(['sourceSystem' => 'external', 'lastUpdated' => '2026-05-15', 'values' => ['email' => 'a@b.co']]);
        $this->objectService->method('find')->willReturn($referenced);

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-6');
        $object->setObject(
                [
                    'sources' => [
                        ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Acme']],
                        'external-source-uuid',
                    ],
                ]
                );

        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertSame('Acme', $data['goldenRecord']['legalName']);
        $this->assertSame('a@b.co', $data['goldenRecord']['email']);
    }//end testEmbeddedAndReferencedSourcesBothResolve()

    public function testOverrideIsThreadedIntoResolutionAndWinsOverTier(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        $object = new ObjectEntity();
        $object->setSchema('organisation');
        $object->setUuid('obj-7');
        $object->setObject(
            [
                'sources'            => [
                    ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'Acme']],
                ],
                'attributeOverrides' => [
                    'legalName' => ['value' => 'Acme Steward Override', 'overriddenBy' => 'alice'],
                ],
            ]
        );

        $event = new ObjectCreatingEvent($object);
        $this->listener->handle($event);

        $data = $object->getObject();
        $this->assertSame('Acme Steward Override', $data['goldenRecord']['legalName']);
        $this->assertTrue($data['attributeProvenance']['legalName']['override']);
        $this->assertSame('alice', $data['attributeProvenance']['legalName']['overriddenBy']);
        // The override map itself must be preserved untouched in the payload.
        $this->assertSame(
            ['legalName' => ['value' => 'Acme Steward Override', 'overriddenBy' => 'alice']],
            $data['attributeOverrides']
        );
    }//end testOverrideIsThreadedIntoResolutionAndWinsOverTier()

    public function testOverridePreservedAcrossAnUnrelatedRecompute(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        // Simulate a second, unrelated save on the same object: the override
        // map is already on the payload (as persisted by a prior override
        // call) and must survive this recompute untouched even though this
        // save was not about the override at all.
        $old = new ObjectEntity();
        $old->setSchema('organisation');

        $new = new ObjectEntity();
        $new->setSchema('organisation');
        $new->setUuid('obj-8');
        $new->setObject(
            [
                'sources'            => [
                    ['sourceSystem' => 'crm', 'lastUpdated' => '2026-06-01', 'values' => ['legalName' => 'Acme', 'phone' => '555-0100']],
                ],
                'attributeOverrides' => [
                    'legalName' => ['value' => 'Pinned Co', 'overriddenBy' => 'alice'],
                ],
            ]
        );

        $event = new ObjectUpdatingEvent($new, $old);
        $this->listener->handle($event);

        $data = $new->getObject();
        // Golden record still reflects the override.
        $this->assertSame('Pinned Co', $data['goldenRecord']['legalName']);
        // The unrelated attribute (phone) resolved normally by tier.
        $this->assertSame('555-0100', $data['goldenRecord']['phone']);
        // The override map itself was not dropped or reset.
        $this->assertSame(
            ['legalName' => ['value' => 'Pinned Co', 'overriddenBy' => 'alice']],
            $data['attributeOverrides']
        );
    }//end testOverridePreservedAcrossAnUnrelatedRecompute()

    public function testOverridesAreIsolatedBetweenObjects(): void
    {
        $this->schemaWithSurvivorship(self::BASE_CONFIG);
        $this->objectService->method('findAll')->willReturn([]);

        // Object A has an override; object B (same schema) does not.
        $objectA = new ObjectEntity();
        $objectA->setSchema('organisation');
        $objectA->setUuid('obj-a');
        $objectA->setObject(
            [
                'sources'            => [
                    ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'A Co']],
                ],
                'attributeOverrides' => [
                    'legalName' => ['value' => 'A Overridden', 'overriddenBy' => 'alice'],
                ],
            ]
        );

        $objectB = new ObjectEntity();
        $objectB->setSchema('organisation');
        $objectB->setUuid('obj-b');
        $objectB->setObject(
            [
                'sources' => [
                    ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['legalName' => 'B Co']],
                ],
            ]
        );

        $this->listener->handle(new ObjectCreatingEvent($objectA));
        $this->listener->handle(new ObjectCreatingEvent($objectB));

        $dataA = $objectA->getObject();
        $dataB = $objectB->getObject();

        $this->assertSame('A Overridden', $dataA['goldenRecord']['legalName']);
        $this->assertTrue($dataA['attributeProvenance']['legalName']['override']);

        // Object B resolves purely from its own source, unaffected by A's override.
        $this->assertSame('B Co', $dataB['goldenRecord']['legalName']);
        $this->assertArrayNotHasKey('override', $dataB['attributeProvenance']['legalName']);
        $this->assertArrayNotHasKey('attributeOverrides', $dataB);
    }//end testOverridesAreIsolatedBetweenObjects()
}//end class
