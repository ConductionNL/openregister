<?php

/**
 * AggregationController response header tests.
 *
 * Covers the `X-OR-Cache: hit|miss` response header surfaced for
 * downstream observability / reverse proxies. Closes the deferred
 * follow-up from `aggregations-backend-native` task 6.3.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AggregationController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\AggregationController
 */
class AggregationControllerTest extends TestCase
{

    private AggregationController $controller;

    private AggregationRunner&MockObject $runner;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $request          = $this->createMock(IRequest::class);
        $this->runner     = $this->createMock(AggregationRunner::class);
        $this->controller = new AggregationController(
            'openregister',
            $request,
            $this->runner
        );
    }//end setUp()

    /**
     * A miss verdict (no `cached` flag, or `cached: false`) surfaces
     * `X-OR-Cache: miss`.
     *
     * @return void
     */
    public function testHeaderMissOnFreshComputation(): void
    {
        $this->runner->method('run')->willReturn(
            [
                'metric'  => 'count',
                'value'   => 42,
                'backend' => 'postgres',
            ]
        );

        $response = $this->controller->aggregate('reg', 'sch', 'totals');

        $this->assertSame('miss', $response->getHeaders()['X-OR-Cache'] ?? null);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testHeaderMissOnFreshComputation()

    /**
     * A `cached: true` verdict surfaces `X-OR-Cache: hit`.
     *
     * @return void
     */
    public function testHeaderHitOnCacheReplay(): void
    {
        $this->runner->method('run')->willReturn(
            [
                'metric'  => 'count',
                'value'   => 42,
                'backend' => 'postgres',
                'cached'  => true,
            ]
        );

        $response = $this->controller->aggregate('reg', 'sch', 'totals');

        $this->assertSame('hit', $response->getHeaders()['X-OR-Cache'] ?? null);
    }//end testHeaderHitOnCacheReplay()

    /**
     * A 404 response (unknown aggregation) does not emit the cache
     * header — the underlying lookup never reached the cache layer.
     *
     * @return void
     */
    public function testNoHeaderOn404(): void
    {
        $this->runner->method('run')->willThrowException(new RuntimeException('unknown'));

        $response = $this->controller->aggregate('reg', 'sch', 'totals');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayNotHasKey('X-OR-Cache', $response->getHeaders());
    }//end testNoHeaderOn404()

    /**
     * R08 / F04 contract: when the runner denies access by throwing
     * `NotAuthorizedException`, the controller maps it to HTTP 403 and
     * surfaces the message in the structured `error` body. Pinning this
     * verdict guards against an accidental try/catch reorder silently
     * flipping 403 → 404 (or 500) on a future refactor.
     *
     * @return void
     */
    public function testReturnsForbiddenWhenRunnerDeniesAccess(): void
    {
        $this->runner->method('run')->willThrowException(
            new NotAuthorizedException(message: 'You do not have permission to aggregate schema "sch".')
        );

        $response = $this->controller->aggregate('reg', 'sch', 'totals');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $body = $response->getData();
        $this->assertIsArray($body);
        $this->assertSame(
            'You do not have permission to aggregate schema "sch".',
            $body['error'] ?? null
        );
        // Cache header is for compute-success only, not for denial.
        $this->assertArrayNotHasKey('X-OR-Cache', $response->getHeaders());
    }//end testReturnsForbiddenWhenRunnerDeniesAccess()
}//end class
