<?php

/**
 * The 503 an open breaker's refusal becomes: status, Retry-After, the
 * problem+json content type, the dependency class, and that no host leaks
 * into the body (operate-load-shedding D-5).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\LoadSheddingMiddleware;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilderInterface;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\LoadSheddingMiddleware
 * @covers \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class LoadSheddingMiddlewareTest extends TestCase {

	/**
	 * The middleware over the real problem builder.
	 *
	 * @return LoadSheddingMiddleware The middleware.
	 */
	private function middleware(): LoadSheddingMiddleware {
		return new LoadSheddingMiddleware(problems: new ProblemDetailsBuilder(), logger: new NullLogger());
	}//end middleware()

	/**
	 * Run the middleware's afterException with an exception.
	 *
	 * @param \Exception $exception The exception.
	 *
	 * @return JSONResponse The response.
	 */
	private function after(\Exception $exception): JSONResponse {
		$response = $this->middleware()->afterException(
			controller: $this->createMock(Controller::class),
			methodName: 'index',
			exception: $exception
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		return $response;
	}//end after()

	/**
	 * An open source breaker becomes a 503 with Retry-After, the problem+json
	 * content type, and `dependency` `source` — with no host in the body.
	 *
	 * @return void
	 */
	public function testAnOpenSourceBreakerBecomesA503WithoutTheHost(): void {
		$response = $this->after(new DependencyUnavailableException(key: 'source:kadaster-db.internal:5432', retryAfter: 27));

		$this->assertSame(503, $response->getStatus());
		$headers = $response->getHeaders();
		$this->assertSame('27', $headers['Retry-After']);
		$this->assertSame(ProblemDetailsBuilderInterface::CONTENT_TYPE, $headers['Content-Type']);

		$body = $response->getData();
		$this->assertSame(503, $body['status']);
		$this->assertSame('source', $body['dependency']);
		$this->assertSame('Dependency unavailable', $body['title']);

		$encoded = json_encode($body);
		$this->assertStringNotContainsString('kadaster-db.internal', $encoded);
		$this->assertStringNotContainsString('5432', $encoded);
		$this->assertStringContainsString('27 seconds', $body['detail']);
	}//end testAnOpenSourceBreakerBecomesA503WithoutTheHost()

	/**
	 * Each dependency class gets its own 503, naming only the class.
	 *
	 * @return void
	 */
	public function testEachDependencyClassIsNamed(): void {
		foreach (['database', 'llm', 'outbound', 'webhook'] as $dependency) {
			$response = $this->after(new DependencyUnavailableException(key: $dependency . ':secret-host.example', retryAfter: 5));

			$body = $response->getData();
			$this->assertSame($dependency, $body['dependency']);
			$this->assertStringNotContainsString('secret-host.example', json_encode($body));
		}
	}//end testEachDependencyClassIsNamed()

	/**
	 * A Retry-After of zero is answered as at least one second.
	 *
	 * @return void
	 */
	public function testRetryAfterIsNeverBelowOne(): void {
		$response = $this->after(new DependencyUnavailableException(key: 'llm', retryAfter: 0));

		$this->assertSame('1', $response->getHeaders()['Retry-After']);
	}//end testRetryAfterIsNeverBelowOne()

	/**
	 * Any other exception is rethrown untouched.
	 *
	 * @return void
	 */
	public function testAnotherExceptionIsRethrown(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not mine');

		$this->middleware()->afterException(
			controller: $this->createMock(Controller::class),
			methodName: 'index',
			exception: new RuntimeException('not mine')
		);
	}//end testAnotherExceptionIsRethrown()
}//end class
