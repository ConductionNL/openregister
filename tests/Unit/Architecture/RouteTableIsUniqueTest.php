<?php
/**
 * No two routes share a verb and path.
 *
 * The router answers a request with the first route whose verb and path match,
 * so a later route on the same verb and path never runs. Placeholder names do
 * not matter to the router: `{id}` and `{fileId}` are the same segment. The
 * `resources` block expands to index, show, create, update and destroy.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural: the route table.
 *
 * @coversNothing
 */
class RouteTableIsUniqueTest extends TestCase {

	/**
	 * Every declared route as [verb, path, name], resources expanded.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	private function declaredRoutes(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$routes   = [];
		foreach (($declared['resources'] ?? []) as $name => $resource) {
			$url = '/' . ltrim((string) $resource['url'], '/');
			$routes[] = ['GET', $url, $name . '#index'];
			$routes[] = ['GET', $url . '/{id}', $name . '#show'];
			$routes[] = ['POST', $url, $name . '#create'];
			$routes[] = ['PUT', $url . '/{id}', $name . '#update'];
			$routes[] = ['DELETE', $url . '/{id}', $name . '#destroy'];
		}

		foreach (($declared['routes'] ?? []) as $route) {
			$routes[] = [strtoupper((string) ($route['verb'] ?? 'GET')), (string) $route['url'], (string) $route['name']];
		}

		return $routes;
	}//end declaredRoutes()

	/**
	 * Each verb and path is declared once.
	 *
	 * @return void
	 */
	public function testNoTwoRoutesShareAVerbAndPath(): void {
		$byKey = [];
		foreach ($this->declaredRoutes() as [$verb, $url, $name]) {
			$byKey[$verb . ' ' . preg_replace(pattern: '/\{[^}]+\}/', replacement: '{}', subject: $url)][] = $name;
		}

		$duplicates = array_filter($byKey, static fn (array $names): bool => count($names) > 1);

		$this->assertSame(expected: [], actual: $duplicates, message: 'only the first of these routes is ever reached');
	}//end testNoTwoRoutesShareAVerbAndPath()
}//end class
