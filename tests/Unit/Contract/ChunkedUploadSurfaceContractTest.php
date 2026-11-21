<?php

/**
 * The API surface of uploads in parts (large-file-handling, task 3.4).
 *
 * Pins the four routes to their controller methods and verbs, and the OpenAPI
 * document to the same four paths with their bodies and refusals, so a rename
 * or a dropped route breaks this test before it breaks an integrator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ChunkedUploadController;
use OCA\OpenRegister\Tests\Unit\Support\DeclaredRoutes;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The routes, their controller methods and the documented surface.
 *
 * @coversNothing
 */
class ChunkedUploadSurfaceContractTest extends TestCase {

	private const OPENAPI = __DIR__ . '/../../../openapi.json';

	private const BASE = '/objects/{register}/{schema}/{id}/uploads';

	/**
	 * The four routes: name => [verb, url].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ROUTES = [
		'chunkedUpload#open' => ['POST', '/api' . self::BASE],
		'chunkedUpload#part' => ['PUT', '/api' . self::BASE . '/{uploadId}/parts/{n}'],
		'chunkedUpload#status' => ['GET', '/api' . self::BASE . '/{uploadId}'],
		'chunkedUpload#complete' => ['POST', '/api' . self::BASE . '/{uploadId}/complete'],
	];

	public function testTheFourRoutesAreDeclared(): void {
		$declared = [];
		foreach (DeclaredRoutes::unversioned() as $route) {
			if (str_starts_with((string) $route['name'], 'chunkedUpload#') === true) {
				$declared[$route['name']] = [$route['verb'], $route['url']];
			}
		}

		$this->assertSame(self::ROUTES, $declared);
	}

	public function testEveryRouteReachesAMethodThatDeclaresItsAuthPosture(): void {
		foreach (array_keys(self::ROUTES) as $name) {
			$method = new ReflectionMethod(ChunkedUploadController::class, explode('#', $name)[1]);

			$this->assertTrue($method->isPublic(), $name);
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class), $name);
		}
	}

	public function testTheApiDocumentsTheFourRoutesUnderBothPrefixes(): void {
		$spec = json_decode((string) file_get_contents(self::OPENAPI), true, 512, JSON_THROW_ON_ERROR);

		foreach (self::ROUTES as $name => [$verb, $url]) {
			$current = substr($url, strlen('/api'));
			foreach ([$current, $url] as $path) {
				$this->assertArrayHasKey($path, $spec['paths'], $name);
				$this->assertArrayHasKey(strtolower($verb), $spec['paths'][$path], "$name $path");
			}

			$this->assertSame(
				$spec['paths'][$current][strtolower($verb)]['responses'],
				$spec['paths'][$url][strtolower($verb)]['responses'],
				"$name: the deprecated twin answers the same"
			);
		}
	}

	public function testTheDocumentStatesTheBoundariesTheBodiesAndTheRefusals(): void {
		$spec  = json_decode((string) file_get_contents(self::OPENAPI), true, 512, JSON_THROW_ON_ERROR);
		$paths = $spec['paths'];

		$open = $paths[self::BASE]['post'];
		$this->assertSame('#/components/schemas/UploadSessionOpenRequest', $open['requestBody']['content']['application/json']['schema']['$ref']);
		$this->assertSame(['name', 'size', 'sha256'], $spec['components']['schemas']['UploadSessionOpenRequest']['required']);
		$this->assertSame('#/components/schemas/UploadSessionPlan', $open['responses']['201']['content']['application/json']['schema']['$ref']);
		$this->assertSame(
			['uploadId', 'partSize', 'partCount', 'parts', 'expiresAt'],
			$spec['components']['schemas']['UploadSessionPlan']['required']
		);

		$part = $paths[self::BASE . '/{uploadId}/parts/{n}']['put'];
		$this->assertSame(['application/octet-stream'], array_keys($part['requestBody']['content']));
		$this->assertArrayHasKey('400', $part['responses']);

		$status = $spec['components']['schemas']['UploadSessionStatus']['allOf'][1];
		$this->assertSame(['name', 'size', 'sha256', 'received', 'missing', 'complete'], $status['required']);

		$complete = $paths[self::BASE . '/{uploadId}/complete']['post'];
		$this->assertSame('#/components/schemas/File', $complete['responses']['201']['content']['application/json']['schema']['$ref']);
		$this->assertArrayHasKey('409', $complete['responses']);

		foreach ([self::BASE . '/{uploadId}' => 'get', self::BASE . '/{uploadId}/parts/{n}' => 'put', self::BASE . '/{uploadId}/complete' => 'post'] as $path => $verb) {
			$this->assertArrayHasKey('404', $paths[$path][$verb]['responses'], "$verb $path: another user's session is not found");
		}
	}
}
