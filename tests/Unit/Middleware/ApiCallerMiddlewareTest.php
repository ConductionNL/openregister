<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\ApiCallerMiddleware;
use OCA\OpenRegister\Middleware\Support\ApiCallerServices;
use OCA\OpenRegister\Service\ApiCaller\ApiCallObservation;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiter;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiation;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The caller record names the official client library from the User-Agent
 * (api-client-libraries task 1.2).
 *
 * @spec openspec/changes/api-client-libraries/tasks.md#task-1.2
 */
final class ApiCallerMiddlewareTest extends TestCase {

	public function testATypescriptClientIsRecordedWithItsVersion(): void {
		$this->assertSame('openregister-client-ts/1.4.0', $this->recordedClient(userAgent: 'openregister-client-ts/1.4.0 (node 22.11.0)'));
	}

	public function testAPythonClientIsRecordedWithItsVersion(): void {
		$this->assertSame('openregister-client-python/2.0.1', $this->recordedClient(userAgent: 'openregister-client-python/2.0.1'));
	}

	public function testABrowserIsRecordedWithoutAClient(): void {
		$this->assertSame(
			'',
			$this->recordedClient(userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36')
		);
	}

	public function testTheRestOfTheObservationIsUnchanged(): void {
		$observation = $this->observe(userAgent: 'openregister-client-ts/1.4.0');

		$this->assertSame('/api/objects/zaken/zaak', $observation->path);
		$this->assertSame('GET', $observation->method);
		$this->assertSame('1', $observation->apiVersion);
	}

	/**
	 * The client the middleware hands the recorder for one served call.
	 *
	 * @param string $userAgent The request's User-Agent.
	 *
	 * @return string The recorded client.
	 */
	private function recordedClient(string $userAgent): string {
		return $this->observe(userAgent: $userAgent)->client;
	}

	/**
	 * Serve one API call and return what was recorded.
	 *
	 * @param string $userAgent The request's User-Agent.
	 *
	 * @return ApiCallObservation The observation.
	 */
	private function observe(string $userAgent): ApiCallObservation {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/api/objects/zaken/zaak');
		$request->method('getRemoteAddress')->willReturn('10.0.0.9');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => strtolower($name) === 'user-agent' ? $userAgent : '');

		$policy = $this->createMock(CallerPolicy::class);
		$policy->method('allowsAddress')->willReturn(true);
		$limiter = $this->createMock(CallerRateLimiter::class);
		$limiter->method('consume')->willReturn(null);

		$recorded = [];
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->method('principal')->willReturn('leverancier');
		$recorder->expects($this->once())->method('recordCall')->willReturnCallback(
			static function (ApiCallObservation $call) use (&$recorded): void {
				$recorded[] = $call;
			}
		);

		$negotiator = $this->createMock(ApiVersionNegotiator::class);
		$served = new ApiVersion(id: '1', lifecycle: new ApiVersionLifecycle(status: ApiVersion::STATUS_SUPPORTED));
		$negotiator->method('negotiate')->willReturn(new ApiVersionNegotiation(null, $served, ApiVersionNegotiation::SOURCE_DEFAULT));

		$middleware = new ApiCallerMiddleware($request, new ApiCallerServices($recorder, $negotiator), $limiter, $policy);
		$middleware->beforeController(null, 'index');
		$response = new Response();
		$this->assertSame($response, $middleware->afterController(null, 'index', $response));

		return $recorded[0];
	}
}
