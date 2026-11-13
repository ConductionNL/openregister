<?php

/**
 * Unit tests for the OpenAPI document controller.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OpenApiController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins what GET /api/openapi.json serves.
 *
 * @covers \OCA\OpenRegister\Controller\OpenApiController
 */
class OpenApiControllerTest extends TestCase {

	/**
	 * Build the controller over an app directory.
	 *
	 * @param string $appPath The app directory.
	 *
	 * @return OpenApiController The controller.
	 */
	private function controller(string $appPath): OpenApiController {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);
		$appManager->method('getAppVersion')->willReturn('9.8.7');

		return new OpenApiController(
			request: $this->createMock(originalClassName: IRequest::class),
			appManager: $appManager
		);
	}//end controller()

	/**
	 * The shipped document is served with the installed version, readable from any origin.
	 *
	 * @return void
	 */
	public function testServesTheShippedDocument(): void {
		$response = $this->controller(appPath: dirname(__DIR__, 3))->show();
		$document = json_decode((string) json_encode($response->getData()), true);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: '*', actual: $this->headersOf(response: $response)['Access-Control-Allow-Origin']);
		$this->assertSame(expected: '9.8.7', actual: $document['info']['version']);
		$this->assertArrayHasKey(key: '/openapi.json', array: $document['paths']);
	}//end testServesTheShippedDocument()

	/**
	 * Every documented error response is problem details built on the Problem schema.
	 *
	 * @return void
	 */
	public function testErrorResponsesAreProblemDetails(): void {
		$document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi.json'), true);
		$this->assertArrayHasKey(key: 'Problem', array: $document['components']['schemas']);

		$errors = 0;
		foreach ($document['paths'] as $path => $item) {
			foreach ($item as $verb => $operation) {
				foreach (($operation['responses'] ?? []) as $code => $response) {
					if ((int) $code < 400) {
						continue;
					}

					$errors++;
					$this->assertSame(expected: ['application/problem+json'], actual: array_keys($response['content'] ?? []), message: "$verb $path $code");
					$this->assertStringContainsString(
						needle: '#/components/schemas/Problem"',
						haystack: (string) json_encode($response['content']['application/problem+json']['schema'], JSON_UNESCAPED_SLASHES),
						message: "$verb $path $code"
					);
				}
			}
		}

		$this->assertGreaterThan(expected: 0, actual: $errors);
	}//end testErrorResponsesAreProblemDetails()

	/**
	 * The document describes `/api/v1/` and lists every unversioned path as a deprecated twin.
	 *
	 * @return void
	 */
	public function testUnversionedPathsAreDeprecatedTwins(): void {
		$document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi.json'), true);
		$this->assertSame(expected: 'https://{host}/index.php/apps/openregister/api/v1', actual: $document['servers'][0]['url']);
		$this->assertArrayHasKey(key: '/objects/{register}/{schema}/{id}/files-multipart', array: $document['paths']);
		$this->assertArrayNotHasKey(key: '/objects/{register}/{schema}/{id}/filesMultipart', array: $document['paths']);
		$this->assertArrayNotHasKey(key: '/api/objects/{register}/{schema}/{id}/filesMultipart', array: $document['paths']);

		$twins = [];
		foreach (array_keys($document['paths']) as $path) {
			if (str_starts_with($path, '/api/') === false) {
				$twins['/api'.$path] = $path;
			}
		}

		$this->assertCount(
			expectedCount: 2 * count($twins),
			haystack: $document['paths'],
			message: 'one deprecated twin per path'
		);
		foreach ($twins as $twin => $current) {
			$this->assertArrayHasKey(key: $twin, array: $document['paths']);
			$this->assertSame(expected: 'https://{host}/index.php/apps/openregister', actual: $document['paths'][$twin]['servers'][0]['url']);
			foreach ($document['paths'][$current] as $verb => $operation) {
				if (is_array($operation) === false || isset($operation['responses']) === false) {
					continue;
				}

				$this->assertTrue(condition: $document['paths'][$twin][$verb]['deprecated'], message: "$verb $twin");
				$this->assertSame(expected: $operation['responses'], actual: $document['paths'][$twin][$verb]['responses'], message: "$verb $twin");
			}
		}
	}//end testUnversionedPathsAreDeprecatedTwins()

	/**
	 * Empty JSON objects in the document stay objects when served.
	 *
	 * @return void
	 */
	public function testEmptyObjectsStayObjects(): void {
		$dir = sys_get_temp_dir().'/openapi-'.uniqid();
		mkdir($dir);
		file_put_contents($dir.'/openapi.json', '{"openapi":"3.0.3","info":{"version":"0"},"paths":{"/x":{"get":{"security":[{}]}}}}');

		$response = $this->controller(appPath: $dir)->show();
		unlink($dir.'/openapi.json');
		rmdir($dir);

		$this->assertStringContainsString(needle: '"security":[{}]', haystack: (string) json_encode($response->getData()));
	}//end testEmptyObjectsStayObjects()

	/**
	 * Without a document the endpoint answers 404 rather than an empty document.
	 *
	 * @return void
	 */
	public function testMissingDocumentIsNotFound(): void {
		$response = $this->controller(appPath: sys_get_temp_dir().'/no-such-app-'.uniqid())->show();

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: '*', actual: $this->headersOf(response: $response)['Access-Control-Allow-Origin']);
	}//end testMissingDocumentIsNotFound()

	/**
	 * The headers a response set, read without the server container that
	 * Response::getHeaders() needs.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class
