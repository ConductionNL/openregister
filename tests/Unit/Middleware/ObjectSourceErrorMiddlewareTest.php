<?php

/**
 * Unit tests for ObjectSourceErrorMiddleware.
 *
 * Proves the request-level failure semantics of the dbal-virtual-registers
 * change: a DbalObjectSourceException raised during a read against an
 * external-database-backed schema surfaces as a JSONResponse with its declared
 * 503 (unreachable) or 502 (upstream error) status — never a bare 500 — while
 * every other exception is rethrown untouched.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use Exception;
use OCA\OpenRegister\Middleware\ObjectSourceErrorMiddleware;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for the object-source error middleware.
 */
class ObjectSourceErrorMiddlewareTest extends TestCase
{


    /**
     * Build the middleware under test.
     *
     * @return ObjectSourceErrorMiddleware The middleware.
     */
    private function middleware(): ObjectSourceErrorMiddleware
    {
        return new ObjectSourceErrorMiddleware(logger: new NullLogger());
    }//end middleware()


    /**
     * An unreachable external database maps to a request-level 503, not a 500.
     *
     * @return void
     */
    public function testUnreachableDatabaseMapsTo503(): void
    {
        $response = $this->middleware()->afterException(
            controller: $this->createMock(Controller::class),
            methodName: 'index',
            exception: new DbalObjectSourceException('The external database is unreachable.', 503)
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(503, $response->getStatus());
        $this->assertSame(
            ['error' => 'The external database is unreachable.'],
            $response->getData()
        );
    }//end testUnreachableDatabaseMapsTo503()


    /**
     * An upstream query error maps to a request-level 502.
     *
     * @return void
     */
    public function testUpstreamErrorMapsTo502(): void
    {
        $response = $this->middleware()->afterException(
            controller: $this->createMock(Controller::class),
            methodName: 'show',
            exception: new DbalObjectSourceException('The external database returned an error.', 502)
        );

        $this->assertSame(502, $response->getStatus());
    }//end testUpstreamErrorMapsTo502()


    /**
     * Every other exception is rethrown untouched.
     *
     * @return void
     */
    public function testUnrelatedExceptionIsRethrown(): void
    {
        $original = new Exception('unrelated');

        $this->expectExceptionObject($original);

        $this->middleware()->afterException(
            controller: $this->createMock(Controller::class),
            methodName: 'index',
            exception: $original
        );
    }//end testUnrelatedExceptionIsRethrown()


}//end class
