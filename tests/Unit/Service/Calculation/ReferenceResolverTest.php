<?php

/**
 * OpenRegister ReferenceResolverTest
 *
 * Unit coverage for the cross-object reference pre-resolver feeding the
 * calculation engine's `@ref.<name>.<field>` payload tokens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-4
 */
class ReferenceResolverTest extends TestCase
{
    /** @var ObjectService&MockObject */
    private $objectService;

    /** @var LoggerInterface&MockObject */
    private $logger;

    private ReferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->resolver      = new ReferenceResolver($this->objectService, $this->logger);
    }//end setUp()

    private function entity(array $data): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid('ref-uuid');
        $e->setRegister('reg-1');
        $e->setSchema('FixedAsset');
        $e->setOwner('admin');
        $e->setObject($data);
        return $e;
    }//end entity()

    public function testResolveRelatedObjectByFk(): void
    {
        $captured = null;
        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured = $args;
                return $this->entity(['acquisitionCost' => 10000]);
            });

        $payload = [
            'fixedAssetId' => 'fa-1',
            '@self'        => ['uuid' => 'obj-1'],
        ];
        $refs = $this->resolver->resolveAll(
            $payload,
            ['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
            'reg-1'
        );

        $this->assertSame(10000, $refs['asset']['acquisitionCost']);
        $this->assertSame('ref-uuid', $refs['asset']['@self']['uuid']);
        // RBAC + multitenancy are never bypassed on the FK read.
        $this->assertNotNull($captured);
        $this->assertTrue(($captured['_rbac'] ?? $captured[5] ?? null) === true);
        $this->assertTrue(($captured['_multitenancy'] ?? $captured[6] ?? null) === true);
    }//end testResolveRelatedObjectByFk()

    public function testResolveLookupEffectiveDated(): void
    {
        $captured = null;
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->willReturnCallback(function (array $config, bool $rbac, bool $multi) use (&$captured) {
                $captured = [$config, $rbac, $multi];
                // Most-recent valid row first (DESC sort applied by resolver).
                return [$this->entity(['ratePerKm' => 0.21, 'fiscalYear' => 2026])];
            });

        $payload = [
            'vehicleType' => 'car',
            'country'     => 'NL',
            'journeyDate' => '2026-03-10',
            '@self'       => [
                'vehicleType' => 'car',
                'country'     => 'NL',
                'journeyDate' => '2026-03-10',
            ],
        ];
        $refs = $this->resolver->resolveAll(
            $payload,
            [
                'rate' => [
                    'schema'        => 'MileageRate',
                    'mode'          => 'lookup',
                    'filters'       => [
                        'fiscalYear'  => ['year' => '@self.journeyDate'],
                        'vehicleType' => '@self.vehicleType',
                        'country'     => '@self.country',
                    ],
                    'effectiveDate' => ['field' => 'fiscalYear', 'op' => 'lte', 'value' => '@self.journeyDate'],
                ],
            ],
            'reg-1'
        );

        $this->assertSame(0.21, $refs['rate']['ratePerKm']);
        // Criteria parameterised by @self: year(journeyDate) => 2026.
        $this->assertSame(2026, $captured[0]['filters']['fiscalYear']);
        $this->assertSame('car', $captured[0]['filters']['vehicleType']);
        $this->assertSame('MileageRate', $captured[0]['filters']['schema']);
        // RBAC + multitenancy never bypassed.
        $this->assertTrue($captured[1]);
        $this->assertTrue($captured[2]);
        // Effective-date selector applies a DESC sort to pick the latest row.
        $this->assertSame('DESC', $captured[0]['sort']['fiscalYear']);
    }//end testResolveLookupEffectiveDated()

    public function testMissingFkInjectsNull(): void
    {
        $this->objectService->expects($this->never())->method('find');
        $refs = $this->resolver->resolveAll(
            ['@self' => []],
            ['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
            'reg-1'
        );
        $this->assertNull($refs['asset']);
    }//end testMissingFkInjectsNull()

    public function testEmptyLookupInjectsNull(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $refs = $this->resolver->resolveAll(
            ['vehicleType' => 'car', '@self' => ['vehicleType' => 'car']],
            ['rate' => ['schema' => 'MileageRate', 'mode' => 'lookup', 'filters' => ['vehicleType' => '@self.vehicleType']]],
            'reg-1'
        );
        $this->assertNull($refs['rate']);
    }//end testEmptyLookupInjectsNull()

    public function testExceptionDuringResolutionInjectsNullAndLogs(): void
    {
        $this->objectService->method('find')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning');
        $refs = $this->resolver->resolveAll(
            ['fixedAssetId' => 'fa-1', '@self' => []],
            ['asset' => ['schema' => 'FixedAsset', 'mode' => 'relatedObject', 'field' => 'fixedAssetId']],
            'reg-1'
        );
        $this->assertNull($refs['asset']);
    }//end testExceptionDuringResolutionInjectsNullAndLogs()
}//end class
