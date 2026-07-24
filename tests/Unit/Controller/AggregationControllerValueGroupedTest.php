<?php

/**
 * Unit tests for `AggregationController::value()` / `grouped()` — the
 * `X-OR-Cache` header (REQ-AGG-105), the multi-field `groupBy` wiring
 * (REQ-AGG-101), and the `metrics` multi-metric list wiring (REQ-AGG-102).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AggregationController;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\AggregationController
 */
class AggregationControllerValueGroupedTest extends TestCase
{

    private AggregationRunner&MockObject $runner;

    private IRequest&MockObject $request;

    private TimeseriesRequestValidator&MockObject $validator;

    protected function setUp(): void
    {
        $this->request   = $this->createMock(IRequest::class);
        $this->runner    = $this->createMock(AggregationRunner::class);
        $this->validator = $this->createMock(TimeseriesRequestValidator::class);

    }//end setUp()

    /**
     * Build the controller with the current request/runner/validator mocks.
     *
     * @return AggregationController
     */
    private function makeController(): AggregationController
    {
        return new AggregationController(
            'openregister',
            $this->request,
            $this->runner,
            $this->validator
        );

    }//end makeController()

    /**
     * Stub `IRequest::getParam()` from a map of `name => value`. Params not
     * present in the map fall through to the caller-supplied default.
     *
     * @param array<string, mixed> $params
     *
     * @return void
     */
    private function stubParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $name, $default=null) use ($params) {
                return array_key_exists($name, $params) ? $params[$name] : $default;
            }
        );

    }//end stubParams()

    // -----------------------------------------------------------------------
    // X-OR-Cache header (REQ-AGG-105).
    // -----------------------------------------------------------------------

    public function testValueSurfacesCacheMissHeader(): void
    {
        $this->stubParams(['metric' => 'count']);
        $this->runner->method('runAdhocByRef')->willReturn(['value' => 5, 'backend' => 'postgres', 'cached' => false]);

        $response = $this->makeController()->value('reg', 'sch');

        $this->assertSame('miss', $response->getHeaders()['X-OR-Cache'] ?? null);

    }//end testValueSurfacesCacheMissHeader()

    public function testValueSurfacesCacheHitHeader(): void
    {
        $this->stubParams(['metric' => 'count']);
        $this->runner->method('runAdhocByRef')->willReturn(['value' => 5, 'backend' => 'postgres', 'cached' => true]);

        $response = $this->makeController()->value('reg', 'sch');

        $this->assertSame('hit', $response->getHeaders()['X-OR-Cache'] ?? null);

    }//end testValueSurfacesCacheHitHeader()

    public function testGroupedSurfacesCacheHeader(): void
    {
        $this->stubParams(['metric' => 'count', 'groupBy' => 'status']);
        $this->runner->method('runAdhocByRef')->willReturn(['groups' => [], 'backend' => 'postgres', 'cached' => true]);

        $response = $this->makeController()->grouped('reg', 'sch');

        $this->assertSame('hit', $response->getHeaders()['X-OR-Cache'] ?? null);

    }//end testGroupedSurfacesCacheHeader()

    public function testTimeseriesSurfacesCacheHeader(): void
    {
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $query  = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
        $this->runner->method('findSchema')->willReturn($schema);
        $this->validator->method('validate')->willReturn($query);
        $this->runner->method('runAdhocByRef')->willReturn(['groups' => [], 'backend' => 'postgres', 'cached' => true]);

        $response = $this->makeController()->timeseries('reg', 'sch');

        $this->assertSame('hit', $response->getHeaders()['X-OR-Cache'] ?? null);

    }//end testTimeseriesSurfacesCacheHeader()

    // -----------------------------------------------------------------------
    // Multi-field groupBy wiring (REQ-AGG-101).
    // -----------------------------------------------------------------------

    public function testGroupedSingleFieldBuildsLegacyFieldShape(): void
    {
        $this->stubParams(['metric' => 'count', 'groupBy' => 'status']);

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['groups' => [], 'backend' => 'postgres', 'cached' => false];
            }
        );

        $this->makeController()->grouped('reg', 'sch');

        $this->assertSame(['field' => 'status'], $captured->groupBy, 'a single groupBy field MUST keep the legacy {field: ...} shape');

    }//end testGroupedSingleFieldBuildsLegacyFieldShape()

    public function testGroupedCommaListBuildsMultiFieldShape(): void
    {
        $this->stubParams(['metric' => 'count', 'groupBy' => 'status,type']);

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['groups' => [], 'backend' => 'postgres', 'cached' => false];
            }
        );

        $this->makeController()->grouped('reg', 'sch');

        $this->assertSame(['fields' => ['status', 'type']], $captured->groupBy);
        $this->assertTrue($captured->isMultiFieldGroupBy());

    }//end testGroupedCommaListBuildsMultiFieldShape()

    public function testGroupedRepeatedArrayParamBuildsMultiFieldShape(): void
    {
        $this->stubParams(['metric' => 'count', 'groupBy' => ['status', 'type']]);

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['groups' => [], 'backend' => 'postgres', 'cached' => false];
            }
        );

        $this->makeController()->grouped('reg', 'sch');

        $this->assertSame(['status', 'type'], $captured->getGroupByFields());

    }//end testGroupedRepeatedArrayParamBuildsMultiFieldShape()

    public function testGroupedMissingGroupByReturns400(): void
    {
        $this->stubParams(['metric' => 'count', 'groupBy' => '']);
        $this->runner->expects($this->never())->method('runAdhocByRef');

        $response = $this->makeController()->grouped('reg', 'sch');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testGroupedMissingGroupByReturns400()

    // -----------------------------------------------------------------------
    // Multi-metric `metrics` list wiring (REQ-AGG-102).
    // -----------------------------------------------------------------------

    public function testValueParsesMetricsListIntoQuery(): void
    {
        $this->stubParams(
            [
                'metric'  => 'count',
                'metrics' => [
                    ['metric' => 'count'],
                    ['metric' => 'sum', 'field' => 'price'],
                ],
            ]
        );

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['values' => ['count' => 1, 'sum_price' => 2.0], 'backend' => 'postgres', 'cached' => false];
            }
        );

        $response = $this->makeController()->value('reg', 'sch');

        $this->assertTrue($captured->isMultiMetric());
        $this->assertSame(
            [
                ['metric' => 'count', 'field' => null],
                ['metric' => 'sum', 'field' => 'price'],
            ],
            $captured->getMetrics()
        );
        $this->assertSame(['count' => 1, 'sum_price' => 2.0], $response->getData()['values']);

    }//end testValueParsesMetricsListIntoQuery()

    public function testValueWithoutMetricsParamStaysLegacySingleMetric(): void
    {
        $this->stubParams(['metric' => 'sum', 'field' => 'price']);

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['value' => 42, 'backend' => 'postgres', 'cached' => false];
            }
        );

        $this->makeController()->value('reg', 'sch');

        $this->assertFalse($captured->isMultiMetric());
        $this->assertSame('sum', $captured->metric);
        $this->assertSame('price', $captured->field);

    }//end testValueWithoutMetricsParamStaysLegacySingleMetric()

    public function testGroupedParsesMetricsListIntoQuery(): void
    {
        $this->stubParams(
            [
                'metric'  => 'count',
                'groupBy' => 'status',
                'metrics' => [
                    ['metric' => 'count'],
                    ['metric' => 'avg', 'field' => 'amount'],
                ],
            ]
        );

        $captured = null;
        $this->runner->method('runAdhocByRef')->willReturnCallback(
            function ($reg, $sch, AggregationQuery $query) use (&$captured) {
                $captured = $query;
                return ['groups' => [], 'backend' => 'postgres', 'cached' => false];
            }
        );

        $this->makeController()->grouped('reg', 'sch');

        $this->assertTrue($captured->isMultiMetric());

    }//end testGroupedParsesMetricsListIntoQuery()
}//end class
