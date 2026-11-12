<?php

/**
 * UnversionedPathDeprecationMiddlewareTest: an unversioned API path keeps
 * answering and says which path replaces it.
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

use OCA\OpenRegister\Middleware\UnversionedPathDeprecationMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\UnversionedPathDeprecationMiddleware
 * @uses \OCA\OpenRegister\Middleware\Support\ApiPathAliases
 * @uses \OCA\OpenRegister\Middleware\Support\StandardProtocolPaths
 */
class UnversionedPathDeprecationMiddlewareTest extends TestCase {

	/**
	 * The headers of a response after the middleware, for a request on a path.
	 *
	 * @param string $path The path info.
	 *
	 * @return array<string, string> The response headers.
	 */
	private function headersFor(string $path): array {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn('/index.php' . $path . '?_limit=5');

		$response = (new UnversionedPathDeprecationMiddleware(request: $request))->afterController(
			controller: $this->createMock(originalClassName: Controller::class),
			methodName: 'index',
			response: new JSONResponse(data: ['ok' => true])
		);

		return $response->getHeaders();
	}//end headersFor()

	/**
	 * An unversioned API path is marked deprecated and links its successor.
	 *
	 * @return void
	 */
	public function testAnUnversionedPathIsDeprecated(): void {
		$headers = $this->headersFor(path: '/apps/openregister/api/objects/a/b/c/filesMultipart');

		$this->assertSame(expected: '@1794441600', actual: $headers['Deprecation']);
		$this->assertSame(
			expected: '</index.php/apps/openregister/api/v1/objects/a/b/c/files-multipart>; rel="successor-version"',
			actual: $headers['Link']
		);
	}//end testAnUnversionedPathIsDeprecated()

	/**
	 * A versioned path, a protocol path and a page are not marked.
	 *
	 * @return void
	 */
	public function testOtherPathsAreNotMarked(): void {
		foreach (['/apps/openregister/api/v1/objects/a/b', '/apps/openregister/api/mcp', '/apps/openregister/registers', ''] as $path) {
			$this->assertArrayNotHasKey(key: 'Deprecation', array: $this->headersFor(path: $path), message: $path);
		}
	}//end testOtherPathsAreNotMarked()
}//end class
