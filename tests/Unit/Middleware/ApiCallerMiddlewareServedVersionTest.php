<?php

/**
 * The API caller middleware records the served version through the negotiation's interface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\ApiCallerMiddleware;
use OCA\OpenRegister\Middleware\Support\ApiCallerServices;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorderInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicyInterface;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiterInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiationInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiatorInterface;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\ApiCallerMiddleware
 */
final class ApiCallerMiddlewareServedVersionTest extends TestCase {

	/**
	 * A negotiation that only honours its interface still yields the recorded version.
	 *
	 * @return void
	 */
	public function testTheServedVersionIsReadThroughTheInterface(): void {
		$version = $this->createMock(ApiVersionInterface::class);
		$version->method('identifier')->willReturn('2');
		$negotiation = $this->createMock(ApiVersionNegotiationInterface::class);
		$negotiation->method('answeringVersion')->willReturn($version);
		$negotiator = $this->createMock(ApiVersionNegotiatorInterface::class);
		$negotiator->method('negotiate')->willReturn($negotiation);

		$recorder = $this->createMock(ApiCallRecorderInterface::class);
		$recorder->method('principal')->willReturn('leverancier');
		$recorder->expects($this->once())->method('record')->with('/apps/openregister/api/objects', 'GET', '2');

		$middleware = $this->middleware($negotiator, $recorder);
		$middleware->beforeController(null, 'index');
		$middleware->afterController(null, 'index', new Response());
	}//end testTheServedVersionIsReadThroughTheInterface()

	/**
	 * Build the middleware for one API request.
	 *
	 * @param ApiVersionNegotiatorInterface $negotiator The negotiator.
	 * @param ApiCallRecorderInterface      $recorder   The recorder.
	 *
	 * @return ApiCallerMiddleware
	 */
	private function middleware(ApiVersionNegotiatorInterface $negotiator, ApiCallRecorderInterface $recorder): ApiCallerMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/openregister/api/objects');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getRemoteAddress')->willReturn('203.0.113.7');
		$policy = $this->createMock(CallerPolicyInterface::class);
		$policy->method('allowsAddress')->willReturn(true);
		$limiter = $this->createMock(CallerRateLimiterInterface::class);
		$limiter->method('consume')->willReturn(null);

		return new ApiCallerMiddleware($request, new ApiCallerServices($recorder, $negotiator), $limiter, $policy);
	}//end middleware()
}//end class
