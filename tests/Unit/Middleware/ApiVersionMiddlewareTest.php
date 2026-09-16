<?php

/**
 * ApiVersionMiddlewareTest — the deadline reaches a client through the calls
 * it is already making.
 *
 * Four facts under test, and the last two are the ones a reviewer should look
 * at hardest:
 *
 *  - a deprecated version still answers, and says when it stops;
 *  - a withdrawn version is refused with 410 and names the successor;
 *  - a page-shell request is NOT decorated, because an HTML response carrying
 *    an API-Version header is a lie about what it is;
 *  - and 410 is used rather than 404 on purpose, because a 404 reads as a bug
 *    in the caller's own routing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use Exception;
use OCA\OpenRegister\Middleware\ApiVersionMiddleware;
use OCA\OpenRegister\Middleware\Exception\ApiVersionRefusedException;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\ApiVersionMiddleware
 * @covers \OCA\OpenRegister\Middleware\Exception\ApiVersionRefusedException
 */
class ApiVersionMiddlewareTest extends TestCase {

	/**
	 * Build the middleware over a declaration and a request.
	 *
	 * @param array<int, array<string, mixed>> $declaration The declared versions.
	 * @param array<string, string> $headers The request headers.
	 * @param string $path The request path.
	 *
	 * @return ApiVersionMiddleware The middleware.
	 */
	private function build(array $declaration, array $headers = [], string $path = '/apps/openregister/api/objects'): ApiVersionMiddleware {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(json_encode($declaration));

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[$name] ?? '')
		);
		$request->method('getPathInfo')->willReturn($path);

		$negotiator = new ApiVersionNegotiator(
			new ApiVersionCatalogue($appConfig, $this->createMock(LoggerInterface::class))
		);

		return new ApiVersionMiddleware($request, $negotiator);
	}//end build()

	/**
	 * Version 1 deprecated with an end date, version 2 supported.
	 *
	 * @return array<int, array<string, mixed>> The declaration.
	 */
	private static function deprecatedAndSupported(): array {
		return [
			['id' => '1', 'status' => 'deprecated', 'deprecatedOn' => '2026-09-01', 'sunset' => '2027-03-01', 'successor' => '2'],
			['id' => '2', 'status' => 'supported'],
		];
	}//end deprecatedAndSupported()

	public function testEveryApiAnswerNamesTheVersionThatServedIt(): void {
		$middleware = $this->build(declaration: self::deprecatedAndSupported());

		$response = $middleware->afterController($this->createMock(Controller::class), 'index', new JSONResponse(['ok' => true]));

		$this->assertSame('2', $response->getHeaders()['API-Version']);
	}//end testEveryApiAnswerNamesTheVersionThatServedIt()

	public function testADeprecatedVersionAnswersAndCarriesItsEndDate(): void {
		$middleware = $this->build(
			declaration: self::deprecatedAndSupported(),
			headers: ['API-Version' => '1'],
		);
		$controller = $this->createMock(Controller::class);

		// It answers: beforeController does not refuse.
		$middleware->beforeController($controller, 'index');

		$response = $middleware->afterController($controller, 'index', new JSONResponse(['ok' => true]));
		$headers = $response->getHeaders();

		$this->assertSame('1', $headers['API-Version']);
		$this->assertSame('Tue, 01 Sep 2026 00:00:00 GMT', $headers['Deprecation']);
		$this->assertSame('Mon, 01 Mar 2027 00:00:00 GMT', $headers['Sunset']);
		$this->assertSame('</apps/openregister/api/versions/2/oas>; rel="successor-version"', $headers['Link']);
	}//end testADeprecatedVersionAnswersAndCarriesItsEndDate()

	public function testASupportedVersionCarriesNoSunset(): void {
		$middleware = $this->build(
			declaration: self::deprecatedAndSupported(),
			headers: ['API-Version' => '2'],
		);

		$response = $middleware->afterController($this->createMock(Controller::class), 'index', new JSONResponse([]));

		$this->assertArrayNotHasKey('Sunset', $response->getHeaders());
		$this->assertArrayNotHasKey('Deprecation', $response->getHeaders());
	}//end testASupportedVersionCarriesNoSunset()

	public function testAWithdrawnVersionIsRefusedWithGoneAndNamesItsSuccessor(): void {
		$middleware = $this->build(
			declaration: [
				['id' => '1', 'status' => 'withdrawn', 'successor' => '2'],
				['id' => '2', 'status' => 'supported'],
			],
			headers: ['API-Version' => '1'],
		);
		$controller = $this->createMock(Controller::class);

		$caught = null;
		try {
			$middleware->beforeController($controller, 'index');
		} catch (ApiVersionRefusedException $e) {
			$caught = $e;
		}

		$this->assertNotNull($caught, 'A withdrawn version that still answers is not withdrawn.');

		$response = $middleware->afterException($controller, 'index', $caught);

		$this->assertSame(
			Http::STATUS_GONE,
			$response->getStatus(),
			'404 reads as a bug in the caller and sends an integrator hunting through their own routing.'
		);
		$this->assertSame('2', $response->getData()['successorVersion']);
		$this->assertStringContainsString('Use version 2', $response->getData()['error']);
	}//end testAWithdrawnVersionIsRefusedWithGoneAndNamesItsSuccessor()

	public function testAnUndeclaredVersionIsRefusedWithBadRequestListingWhatIsServed(): void {
		$middleware = $this->build(
			declaration: self::deprecatedAndSupported(),
			headers: ['API-Version' => '9'],
		);
		$controller = $this->createMock(Controller::class);

		$caught = null;
		try {
			$middleware->beforeController($controller, 'index');
		} catch (ApiVersionRefusedException $e) {
			$caught = $e;
		}

		$this->assertNotNull($caught);

		$response = $middleware->afterException($controller, 'index', $caught);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['1', '2'], $response->getData()['servedVersions']);
		$this->assertSame('9', $response->getData()['requestedVersion']);
	}//end testAnUndeclaredVersionIsRefusedWithBadRequestListingWhatIsServed()

	public function testANonApiRequestIsNeitherRefusedNorDecorated(): void {
		$middleware = $this->build(
			declaration: self::deprecatedAndSupported(),
			headers: ['API-Version' => '9'],
			path: '/apps/openregister/',
		);
		$controller = $this->createMock(Controller::class);

		$middleware->beforeController($controller, 'page');

		$response = $middleware->afterController($controller, 'page', new JSONResponse([]));

		$this->assertArrayNotHasKey(
			'API-Version',
			$response->getHeaders(),
			'An HTML page shell carrying an API-Version header is a lie about what it is.'
		);
	}//end testANonApiRequestIsNeitherRefusedNorDecorated()

	public function testAnUnrelatedExceptionIsRethrown(): void {
		$middleware = $this->build(declaration: self::deprecatedAndSupported());

		$this->expectException(RuntimeException::class);

		$middleware->afterException($this->createMock(Controller::class), 'index', new RuntimeException('something else'));
	}//end testAnUnrelatedExceptionIsRethrown()

	public function testTheRefusalIsAnException(): void {
		$this->assertInstanceOf(
			Exception::class,
			ApiVersionRefusedException::unknown(requestedId: '9', acceptable: ['1'])
		);
	}//end testTheRefusalIsAnException()
}//end class
