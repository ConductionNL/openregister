<?php

/**
 * Characterisation tests for GraphQLController::execute() and the GraphiQL page.
 *
 * Pins the HTTP contract of POST /api/graphql: the 400 for a body without a
 * query, the 200 for data (even with partial errors), the 400 for errors only,
 * and the 429 with a Retry-After header for a rate-limited query. Also pins the
 * GraphiQL HTML byte for byte, so the page cannot drift when the method that
 * builds it is restructured.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- stream wrapper protocol fields.

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\OpenRegister\Search\Controller\GraphQLController;
use OCA\OpenRegister\Search\Controller\Support\GraphQLControllerServices;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Serves a fixed string as php://input while it is registered.
 */
final class GraphQLInputStream {
	public static string $body = '';

	public mixed $context = null;

	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}

	public function stream_stat(): array {
		return [];
	}
}

class GraphQLControllerCharacterisationTest extends TestCase {
	private GraphQLService&MockObject $graphQLService;

	private IURLGenerator&MockObject $urlGenerator;

	private GraphQLController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->graphQLService = $this->createMock(GraphQLService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$nonceManager = $this->createMock(ContentSecurityPolicyNonceManager::class);
		$nonceManager->method('getNonce')->willReturn('nonce-1');
		$token = $this->createMock(CsrfToken::class);
		$token->method('getEncryptedValue')->willReturn('token-1');
		$csrfManager = $this->createMock(CsrfTokenManager::class);
		$csrfManager->method('getToken')->willReturn($token);

		$this->controller = new GraphQLController(
			$this->createMock(IRequest::class),
			new GraphQLControllerServices($this->graphQLService, $this->urlGenerator, $nonceManager, $csrfManager)
		);
	}

	/**
	 * Run execute() with the given raw request body as php://input.
	 *
	 * @param string $body The raw request body.
	 *
	 * @return JSONResponse The controller's response.
	 */
	private function executeWithBody(string $body): JSONResponse {
		GraphQLInputStream::$body = $body;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', GraphQLInputStream::class);
		try {
			return $this->controller->execute();
		} finally {
			stream_wrapper_restore('php');
		}
	}

	private static function badRequest(): array {
		return [
			'errors' => [
				[
					'message' => 'Request body must be JSON with a "query" field',
					'extensions' => ['code' => 'BAD_REQUEST'],
				],
			],
		];
	}

	public function testABodyThatIsNotJsonIsA400(): void {
		$this->graphQLService->expects($this->never())->method('execute');

		$response = $this->executeWithBody('not json');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(self::badRequest(), $response->getData());
	}

	public function testABodyWithoutAQueryIsA400(): void {
		$this->graphQLService->expects($this->never())->method('execute');

		$response = $this->executeWithBody('{"variables": {"a": 1}}');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(self::badRequest(), $response->getData());
	}

	public function testDataIsA200AndPassesQueryVariablesAndOperationName(): void {
		$result = ['data' => ['zaken' => []]];
		$this->graphQLService->expects($this->once())->method('execute')
			->with('{ zaken { id } }', ['a' => 1], 'Op')
			->willReturn($result);

		$response = $this->executeWithBody('{"query": "{ zaken { id } }", "variables": {"a": 1}, "operationName": "Op"}');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($result, $response->getData());
		$this->assertArrayNotHasKey('Retry-After', $response->getHeaders());
	}

	public function testMissingVariablesAndOperationNameAreNull(): void {
		$this->graphQLService->expects($this->once())->method('execute')
			->with('{ a }', null, null)
			->willReturn(['data' => []]);

		$this->assertSame(200, $this->executeWithBody('{"query": "{ a }"}')->getStatus());
	}

	public function testDataWithPartialErrorsIsStillA200(): void {
		$result = ['data' => ['a' => null], 'errors' => [['message' => 'x', 'extensions' => ['code' => 'RATE_LIMITED']]]];
		$this->graphQLService->method('execute')->willReturn($result);

		$response = $this->executeWithBody('{"query": "{ a }"}');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('Retry-After', $response->getHeaders());
	}

	public function testErrorsOnlyIsA400(): void {
		$result = ['errors' => [['message' => 'Syntax Error', 'extensions' => ['code' => 'GRAPHQL_PARSE_FAILED']]]];
		$this->graphQLService->method('execute')->willReturn($result);

		$response = $this->executeWithBody('{"query": "{"}');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame($result, $response->getData());
		$this->assertArrayNotHasKey('Retry-After', $response->getHeaders());
	}

	public function testErrorsWithoutACodeIsA400(): void {
		$this->graphQLService->method('execute')->willReturn(['errors' => [['message' => 'x']]]);

		$this->assertSame(400, $this->executeWithBody('{"query": "{ a }"}')->getStatus());
	}

	public function testRateLimitedIsA429WithItsRetryAfter(): void {
		$this->graphQLService->method('execute')->willReturn(
			['errors' => [['message' => 'slow down', 'extensions' => ['code' => 'RATE_LIMITED', 'retryAfter' => 17]]]]
		);

		$response = $this->executeWithBody('{"query": "{ a }"}');

		$this->assertSame(429, $response->getStatus());
		$this->assertSame('17', $response->getHeaders()['Retry-After']);
	}

	public function testRateLimitedWithoutRetryAfterDefaultsTo60(): void {
		$this->graphQLService->method('execute')->willReturn(
			['errors' => [['message' => 'slow down', 'extensions' => ['code' => 'RATE_LIMITED']]]]
		);

		$response = $this->executeWithBody('{"query": "{ a }"}');

		$this->assertSame(429, $response->getStatus());
		$this->assertSame('60', $response->getHeaders()['Retry-After']);
	}

	private function graphiQlHtml(): string {
		return (new ReflectionMethod(GraphQLController::class, 'getGraphiQLHtml'))->invoke($this->controller);
	}

	public function testTheGraphiQlPageIsPinnedByteForByte(): void {
		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/openregister/api/graphql');

		$this->assertSame(self::expectedHtml(endpoint: '/index.php/apps/openregister/api/graphql'), $this->graphiQlHtml());
	}

	public function testTheGraphiQlEndpointGainsIndexPhpWhenTheRouteLacksIt(): void {
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister/api/graphql');

		$this->assertSame(self::expectedHtml(endpoint: '/index.php/apps/openregister/api/graphql'), $this->graphiQlHtml());
	}

	private static function expectedHtml(string $endpoint): string {
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>OpenRegister GraphQL Explorer</title>
    <style nonce="nonce-1">
        body { height: 100vh; margin: 0; overflow: hidden; }
        #graphiql { height: 100vh; }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/graphiql@3/graphiql.min.css" />
</head>
<body>
    <div id="graphiql">Loading...</div>
    <script nonce="nonce-1" src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script nonce="nonce-1" src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <script nonce="nonce-1" src="https://unpkg.com/graphiql@3/graphiql.min.js" crossorigin></script>
    <script nonce="nonce-1">
        const fetcher = GraphiQL.createFetcher({
            url: '{$endpoint}',
            headers: {
                'requesttoken': 'token-1',
            },
        });

        const root = ReactDOM.createRoot(document.getElementById('graphiql'));
        root.render(
            React.createElement(GraphiQL, {
                fetcher: fetcher,
                defaultEditorToolsVisibility: true,
            })
        );
    </script>
</body>
</html>
HTML;
	}
}
