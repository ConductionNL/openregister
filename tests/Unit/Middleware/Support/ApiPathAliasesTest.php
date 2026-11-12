<?php

/**
 * ApiPathAliasesTest: every unversioned API route is also served under
 * `/api/v1/`, and the old paths keep working.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Support
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Support;

use OCA\OpenRegister\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\Support\ApiPathAliases
 * @uses \OCA\OpenRegister\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesTest extends TestCase {

	/**
	 * Paths and their successor, null when the path is not deprecated.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function successors(): array {
		return [
			'object list'          => ['/api/objects/a/b', '/api/v1/objects/a/b'],
			'request path'         => ['/apps/openregister/api/registers/1', '/apps/openregister/api/v1/registers/1'],
			'renamed multipart'    => ['/api/objects/a/b/c/filesMultipart', '/api/v1/objects/a/b/c/files-multipart'],
			'kebab multipart'      => ['/api/objects/a/b/c/files-multipart', '/api/v1/objects/a/b/c/files-multipart'],
			'already v1'           => ['/api/v1/objects/a/b', null],
			'already v2'           => ['/api/v2/audit', null],
			'versioned family'     => ['/api/mcp/v1/discover', null],
			'protocol'             => ['/api/graphql', null],
			'versions listing'     => ['/api/versions', '/api/v1/versions'],
			'page shell'           => ['/apps/openregister/registers', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor.
	 *
	 * @param string      $path      The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAPath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
		$this->assertSame(expected: $successor !== null, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testTheSuccessorOfAPath()

	/**
	 * Routes and resources gain `/api/v1/` aliases after the originals, which stay as they were.
	 *
	 * @return void
	 */
	public function testAliasesAreAddedAfterTheOriginals(): void {
		$original = [
			'resources' => ['Registers' => ['url' => 'api/registers']],
			'routes'    => [
				['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+']],
				['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT', 'postfix' => 'put'],
				['name' => 'files#createMultipart', 'url' => '/api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST'],
				['name' => 'mcpServer#handle', 'url' => '/api/mcp', 'verb' => 'POST'],
				['name' => 'auditQuery#query', 'url' => '/api/v2/audit', 'verb' => 'GET'],
				['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
			],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $original);

		$this->assertSame(expected: $original['resources'], actual: $declared['resources']);
		$this->assertSame(expected: $original['routes'], actual: array_slice($declared['routes'], 0, 6));
		$this->assertSame(
			expected: [
				[
					'name'         => 'objects#index',
					'url'          => '/api/v1/objects/{register}/{schema}',
					'verb'         => 'GET',
					'requirements' => ['register' => '[^/]+'],
					'postfix'      => 'v1',
				],
				['name' => 'settings#update', 'url' => '/api/v1/settings', 'verb' => 'PUT', 'postfix' => 'putv1'],
				['name' => 'registers#index', 'url' => '/api/v1/registers', 'verb' => 'GET', 'postfix' => 'v1'],
				['name' => 'registers#show', 'url' => '/api/v1/registers/{id}', 'verb' => 'GET', 'postfix' => 'v1'],
				['name' => 'registers#create', 'url' => '/api/v1/registers', 'verb' => 'POST', 'postfix' => 'v1'],
				['name' => 'registers#update', 'url' => '/api/v1/registers/{id}', 'verb' => 'PUT', 'postfix' => 'v1'],
				['name' => 'registers#destroy', 'url' => '/api/v1/registers/{id}', 'verb' => 'DELETE', 'postfix' => 'v1'],
			],
			actual: array_slice($declared['routes'], 6)
		);
	}//end testAliasesAreAddedAfterTheOriginals()

	/**
	 * The shipped routes.php serves every unversioned API route under `/api/v1/` too, under a new route name.
	 *
	 * @return void
	 */
	public function testTheShippedRoutesHaveVersionedAliases(): void {
		$declared = require dirname(__DIR__, 4).'/appinfo/routes.php';
		$byKey    = [];
		foreach ($declared['routes'] as $route) {
			$byKey[$route['verb'].' '.$route['url']] = $route;
		}

		$this->assertArrayHasKey(key: 'POST /api/objects/{register}/{schema}/{id}/filesMultipart', array: $byKey);
		$this->assertArrayHasKey(key: 'POST /api/objects/{register}/{schema}/{id}/files-multipart', array: $byKey);
		$this->assertSame(expected: 'files#createMultipart', actual: $byKey['POST /api/v1/objects/{register}/{schema}/{id}/files-multipart']['name']);
		$this->assertArrayNotHasKey(key: 'POST /api/v1/objects/{register}/{schema}/{id}/filesMultipart', array: $byKey);
		$this->assertArrayHasKey(key: 'GET /api/v1/registers/{id}', array: $byKey);
		$this->assertArrayHasKey(key: 'GET /api/v1/openapi.json', array: $byKey);
		$this->assertArrayNotHasKey(key: 'POST /api/v1/mcp', array: $byKey);
		$this->assertArrayNotHasKey(key: 'GET /api/v1/v2/audit', array: $byKey);

		$aliasNames    = [];
		$originalNames = [];
		foreach (array_keys($declared['resources']) as $resource) {
			foreach (['index', 'show', 'create', 'update', 'destroy'] as $action) {
				$originalNames[] = strtolower($resource.'#'.$action);
			}
		}

		foreach ($declared['routes'] as $route) {
			if (str_starts_with($route['url'], '/api/v1/') === true) {
				$aliasNames[] = strtolower($route['name'].$route['postfix']);
				continue;
			}

			$originalNames[] = strtolower($route['name'].($route['postfix'] ?? ''));

			$this->assertStringStartsNotWith(
				prefix: '/api/{',
				string: '/'.ltrim($route['url'], '/'),
				message: 'an unversioned route must not be able to match an /api/v1/ path'
			);
			if (ApiPathAliases::isDeprecated(path: $route['url']) === true && str_ends_with($route['url'], 'filesMultipart') === false) {
				$alias = $byKey[$route['verb'].' /api/v1'.substr('/'.ltrim($route['url'], '/'), 4)] ?? null;
				$this->assertNotNull(actual: $alias, message: $route['verb'].' '.$route['url'].' has no /api/v1/ alias');
				$this->assertSame(expected: $route['requirements'] ?? null, actual: $alias['requirements'] ?? null);
				$this->assertSame(expected: $route['defaults'] ?? null, actual: $alias['defaults'] ?? null);
			}
		}

		$this->assertGreaterThan(expected: 500, actual: count($aliasNames));
		$this->assertSame(
			expected: [],
			actual: array_values(array_intersect($aliasNames, $originalNames)),
			message: 'an alias reuses an existing route name'
		);
		$this->assertSame(expected: count($aliasNames), actual: count(array_unique($aliasNames)));
	}//end testTheShippedRoutesHaveVersionedAliases()
}//end class
