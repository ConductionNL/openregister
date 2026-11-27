<?php

/**
 * No route names a URL parameter the router keeps for itself.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's router keeps `action` (the controller method) and the `_`-prefixed
 * keys for itself. A route parameter of that name reaches the controller as null,
 * so a `string` parameter throws a TypeError and the route answers 400 with an
 * empty body. Unit tests that call the controller method directly never see it:
 * the object-action run routes were declared with `{action}` and could not be
 * called over HTTP at all.
 */
class RouteParametersAvoidRouterKeysTest extends TestCase {

	/**
	 * Every route of appinfo/routes.php, from all of its sections.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function routes(): array {
		$file = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($file, 'appinfo/routes.php must return an array');

		$routes = [];
		foreach ($file as $section) {
			if (is_array($section) === false) {
				continue;
			}

			foreach ($section as $route) {
				if (is_array($route) === true && isset($route['url']) === true) {
					$routes[] = $route;
				}
			}
		}

		return $routes;
	}//end routes()

	/**
	 * No URL parameter is `action` or starts with an underscore.
	 *
	 * @return void
	 */
	public function testNoRouteParameterIsARouterKey(): void {
		$routes = $this->routes();
		$this->assertNotEmpty($routes);

		$clashes = [];
		foreach ($routes as $route) {
			preg_match_all('/\{([^}]+)\}/', (string)$route['url'], $matches);
			foreach ($matches[1] as $parameter) {
				if ($parameter === 'action' || str_starts_with($parameter, '_') === true) {
					$clashes[] = ($route['name'] ?? '?') . ' ' . $route['url'];
				}
			}
		}

		$this->assertSame([], $clashes, 'These routes name a parameter the router keeps for itself');
	}//end testNoRouteParameterIsARouterKey()

	/**
	 * The two object-action run routes carry the action key as `{key}`.
	 *
	 * @return void
	 */
	public function testTheActionRunRoutesNameTheKey(): void {
		$urls = [];
		foreach ($this->routes() as $route) {
			// The first registration; the /api/v1/ aliases repeat the name.
			$urls[(string)($route['name'] ?? '')] ??= (string)$route['url'];
		}

		$this->assertSame('/api/objects/{register}/{schema}/{id}/actions/{key}', $urls['objectActions#invoke']);
		$this->assertSame('/api/object-actions/{register}/{schema}/{key}', $urls['objectActions#invokeOnSelection']);
	}//end testTheActionRunRoutesNameTheKey()
}//end class
