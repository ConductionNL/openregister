<?php

/**
 * ProblemDetailsMiddlewareTest: the API's JSON errors arrive as RFC 9457
 * problem details, and nothing else changes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\ProblemDetailsMiddleware;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\ProblemDetailsMiddleware
 * @uses \OCA\OpenRegister\Middleware\Support\StandardProtocolPaths
 * @uses \OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder
 * @uses \OCA\OpenRegister\Service\Oas\ProblemDescription
 * @uses \OCA\OpenRegister\Service\Oas\ProblemStatement
 */
class ProblemDetailsMiddlewareTest extends TestCase {

	/**
	 * Run a response through the middleware for a request on a path.
	 *
	 * @param JSONResponse|DataDisplayResponse $response The controller's response.
	 * @param string                           $path     The request path.
	 *
	 * @return JSONResponse|DataDisplayResponse The response after the middleware.
	 */
	private function decorate(
		JSONResponse|DataDisplayResponse $response,
		string $path = '/apps/openregister/api/objects/1/2/3/files'
	): JSONResponse|DataDisplayResponse {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn('/index.php' . $path . '?token=secret');

		$middleware = new ProblemDetailsMiddleware(request: $request, problems: new ProblemDetailsBuilder());

		return $middleware->afterController(controller: $this->createMock(originalClassName: Controller::class), methodName: 'index', response: $response);
	}//end decorate()

	/**
	 * An error body gains type, title, status, detail and instance and keeps its own members.
	 *
	 * @return void
	 */
	public function testAnErrorBecomesProblemDetailsAndKeepsItsMembers(): void {
		$response = $this->decorate(response: new JSONResponse(['error' => 'Object not found', 'code' => 'nf'], 404));

		$this->assertSame(
			expected: [
				'type'     => 'about:blank',
				'title'    => 'Not Found',
				'status'   => 404,
				'detail'   => 'Object not found',
				'instance' => '/index.php/apps/openregister/api/objects/1/2/3/files',
				'error'    => 'Object not found',
				'code'     => 'nf',
			],
			actual: $response->getData()
		);
		$this->assertSame(expected: 'application/problem+json; charset=utf-8', actual: $response->getHeaders()['Content-Type']);
		$this->assertSame(expected: 404, actual: $response->getStatus());
	}//end testAnErrorBecomesProblemDetailsAndKeepsItsMembers()

	/**
	 * `message` is preferred over `error` as the detail.
	 *
	 * @return void
	 */
	public function testTheDetailComesFromMessageFirst(): void {
		$response = $this->decorate(response: new JSONResponse(['error' => 'rate_limited', 'message' => 'Slow down'], 429));

		$this->assertSame(expected: 'Slow down', actual: $response->getData()['detail']);
		$this->assertSame(expected: 'Too Many Requests', actual: $response->getData()['title']);
	}//end testTheDetailComesFromMessageFirst()

	/**
	 * A member the body already uses under a problem name keeps its value.
	 *
	 * @return void
	 */
	public function testAnExistingMemberIsNeverOverwritten(): void {
		$response = $this->decorate(response: new JSONResponse(['status' => 'error', 'message' => 'Broken'], 500));

		$this->assertSame(expected: 'error', actual: $response->getData()['status']);
		$this->assertSame(expected: 'Internal Server Error', actual: $response->getData()['title']);
		$this->assertSame(expected: 'application/problem+json; charset=utf-8', actual: $response->getHeaders()['Content-Type']);
	}//end testAnExistingMemberIsNeverOverwritten()

	/**
	 * A success response is left exactly as it was.
	 *
	 * @return void
	 */
	public function testASuccessIsUntouched(): void {
		$response = $this->decorate(response: new JSONResponse(['id' => 1], 200));

		$this->assertSame(expected: ['id' => 1], actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $response->getHeaders()['Content-Type']);
	}//end testASuccessIsUntouched()

	/**
	 * A standard protocol path keeps the error format its standard defines.
	 *
	 * @return void
	 */
	public function testAProtocolPathIsUntouched(): void {
		$body = ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null];

		$response = $this->decorate(response: new JSONResponse($body, 400), path: '/apps/openregister/api/mcp');

		$this->assertSame(expected: $body, actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $response->getHeaders()['Content-Type']);
	}//end testAProtocolPathIsUntouched()

	/**
	 * A page outside the API keeps its format.
	 *
	 * @return void
	 */
	public function testAPathOutsideTheApiIsUntouched(): void {
		$response = $this->decorate(response: new JSONResponse(['error' => 'x'], 404), path: '/apps/openregister/registers');

		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());
	}//end testAPathOutsideTheApiIsUntouched()

	/**
	 * A list body has no members to keep, and an existing problem document is not touched again.
	 *
	 * @return void
	 */
	public function testAListAndAProblemDocumentAreUntouched(): void {
		$list = $this->decorate(response: new JSONResponse(['a', 'b'], 400));
		$this->assertSame(expected: ['a', 'b'], actual: $list->getData());

		$existing = new JSONResponse(['type' => 'about:blank', 'title' => 'Validation failed', 'status' => 422], 422);
		$existing->addHeader('Content-Type', 'application/problem+json');
		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => 'Validation failed', 'status' => 422],
			actual: $this->decorate(response: $existing)->getData()
		);
	}//end testAListAndAProblemDocumentAreUntouched()

	/**
	 * An empty body still becomes a problem document.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyBecomesAProblem(): void {
		$response = $this->decorate(response: new JSONResponse([], 403));

		$this->assertSame(expected: 'Forbidden', actual: $response->getData()['title']);
		$this->assertArrayNotHasKey(key: 'detail', array: $response->getData());
	}//end testAnEmptyBodyBecomesAProblem()

	/**
	 * A response that is not JSON is left alone.
	 *
	 * @return void
	 */
	public function testANonJsonResponseIsUntouched(): void {
		$response = $this->decorate(response: new DataDisplayResponse('nope', 404, ['Content-Type' => 'text/plain']));

		$this->assertSame(expected: 'text/plain', actual: $response->getHeaders()['Content-Type']);
	}//end testANonJsonResponseIsUntouched()
}//end class
