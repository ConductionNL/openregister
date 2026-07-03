<?php

/**
 * QualityControllerTest
 *
 * Covers auth annotation presence, RBAC pass-through (the controller never
 * bypasses QualityStatisticsService scoping), and pagination/filter/sort
 * param handling for the read-only MDM quality surface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-4
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\QualityController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Quality\QualityStatisticsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\QualityController
 */
class QualityControllerTest extends TestCase
{

    private QualityStatisticsService&MockObject $statistics;

    /**
     * @var IRequest&MockObject
     */
    private $request;

    private QualityController $controller;

    protected function setUp(): void
    {
        $this->request    = $this->createMock(IRequest::class);
        $this->statistics = $this->createMock(QualityStatisticsService::class);
        $this->controller = new QualityController(
            'openregister',
            $this->request,
            $this->statistics
        );
    }//end setUp()

    /**
     * ADR-029 / ADR-005: both endpoints must declare @NoAdminRequired +
     *
     * @NoCSRFRequired via docblock (not PHP8 attributes) and must NOT be
     * @PublicPage,    matching AggregationController's style.
     *
     * @return void
     */
    public function testStatsAndIndexCarryAuthAnnotations(): void
    {
        $reflection = new ReflectionClass(QualityController::class);

        foreach (['stats', 'index'] as $method) {
            $doc = $reflection->getMethod($method)->getDocComment();
            $this->assertNotFalse($doc, "Missing docblock on {$method}()");
            $this->assertStringContainsString('@NoAdminRequired', $doc);
            $this->assertStringContainsString('@NoCSRFRequired', $doc);
            $this->assertStringNotContainsString('@PublicPage', $doc);
        }
    }//end testStatsAndIndexCarryAuthAnnotations()

    public function testStatsDelegatesToServiceWithRegisterAndSchema(): void
    {
        $this->statistics->expects($this->once())
            ->method('statisticsFor')
            ->with('reg', 'sch')
            ->willReturn(
                [
                    'average'   => 0.7,
                    'total'     => 3,
                    'buckets'   => ['good' => 1, 'fair' => 1, 'poor' => 1],
                    'histogram' => [],
                ]
            );

        $response = $this->controller->stats('reg', 'sch');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['total']);
    }//end testStatsDelegatesToServiceWithRegisterAndSchema()

    public function testStatsMapsNotAuthorizedToForbidden(): void
    {
        $this->statistics->method('statisticsFor')->willThrowException(
            new NotAuthorizedException(message: 'nope')
        );

        $response = $this->controller->stats('reg', 'sch');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame('nope', $response->getData()['error'] ?? null);
    }//end testStatsMapsNotAuthorizedToForbidden()

    public function testStatsMapsRuntimeExceptionToNotFound(): void
    {
        $this->statistics->method('statisticsFor')->willThrowException(new RuntimeException('missing'));

        $response = $this->controller->stats('reg', 'sch');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testStatsMapsRuntimeExceptionToNotFound()

    public function testIndexPassesFilterSortAndPaginationParamsThrough(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['qualityStatus', null, 'poor'],
                ['sort', 'qualityScore', 'qualityStatus'],
                ['order', 'asc', 'desc'],
                ['limit', 20, '5'],
                ['offset', 0, '10'],
            ]
        );

        $this->statistics->expects($this->once())
            ->method('lowestQuality')
            ->with('reg', 'sch', 'poor', 'qualityStatus', 'desc', 5, 10)
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 5, 'offset' => 10]);

        $response = $this->controller->index('reg', 'sch');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexPassesFilterSortAndPaginationParamsThrough()

    public function testIndexDefaultsWhenNoParamsSupplied(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['qualityStatus', null, null],
                ['sort', 'qualityScore', 'qualityScore'],
                ['order', 'asc', 'asc'],
                ['limit', 20, 20],
                ['offset', 0, 0],
            ]
        );

        $this->statistics->expects($this->once())
            ->method('lowestQuality')
            ->with('reg', 'sch', null, 'qualityScore', 'asc', 20, 0)
            ->willReturn(['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);

        $response = $this->controller->index('reg', 'sch');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testIndexDefaultsWhenNoParamsSupplied()

    public function testIndexMapsNotAuthorizedToForbidden(): void
    {
        $this->request->method('getParam')->willReturnArgument(1);
        $this->statistics->method('lowestQuality')->willThrowException(
            new NotAuthorizedException(message: 'denied')
        );

        $response = $this->controller->index('reg', 'sch');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testIndexMapsNotAuthorizedToForbidden()
}//end class
