<?php

/**
 * AppHost Routes::standard() canonical-table + merge tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use InvalidArgumentException;
use OCA\OpenRegister\AppHost\Routes;
use PHPUnit\Framework\TestCase;

/**
 * Covers the canonical route set, name parity, extra-merge and catch-all order.
 */
class RoutesTest extends TestCase {
	/**
	 * Extract route names from a Routes::standard() result.
	 *
	 * @param array<string, mixed> $result Routes::standard() output.
	 *
	 * @return array<int, string>
	 */
	private function names(array $result): array {
		return array_map(static fn ($r) => $r['name'], $result['routes']);
	}//end names()

	public function testCanonicalRouteNamesMatchPetstoreReference(): void {
		$names = $this->names(Routes::standard());

		// Bit-compatible with the petstore reference skeleton, plus the
		// canonical ADR-066 `settings#update` write verb (PUT /api/settings).
		$this->assertSame(
			[
				'dashboard#page',
				'settings#index',
				'settings#create',
				'settings#update',
				'settings#load',
				'preferences#getPreference',
				'preferences#setPreference',
				'metrics#index',
				'health#index',
				'store#search',
				'store#install',
				'dashboard#catchAll',
			],
			$names
		);
	}//end testCanonicalRouteNamesMatchPetstoreReference()

	/**
	 * The store routes sit BEFORE the SPA catch-all.
	 *
	 * Not a restatement of the list above: that assertion would still pass if
	 * the catch-all were merged first, and a `/{path}` route with `.+` matches
	 * `api/store/items`. The catch-all's position is the reason the store
	 * endpoints answer JSON rather than the Vue shell with HTTP 200.
	 *
	 * @return void
	 */
	public function testStoreRoutesPrecedeTheSpaCatchAll(): void {
		$names = $this->names(Routes::standard());

		$this->assertLessThan(
			array_search('dashboard#catchAll', $names, true),
			array_search('store#search', $names, true)
		);
		$this->assertLessThan(
			array_search('dashboard#catchAll', $names, true),
			array_search('store#install', $names, true)
		);
	}//end testStoreRoutesPrecedeTheSpaCatchAll()

	/**
	 * The install route bounds its slug at the router.
	 *
	 * A `{slug}` with no requirement accepts anything Symfony will match into
	 * a single segment, and the value reaches the registry URL. The controller
	 * guards it too; this is the outer bound.
	 *
	 * @return void
	 */
	public function testInstallRouteConstrainsTheSlug(): void {
		$routes = Routes::standard()['routes'];
		$install = null;
		foreach ($routes as $route) {
			if ($route['name'] === 'store#install') {
				$install = $route;
				break;
			}
		}

		$this->assertNotNull($install);
		$this->assertSame('[a-z0-9][a-z0-9-]*[a-z0-9]', $install['requirements']['slug']);
	}//end testInstallRouteConstrainsTheSlug()

	public function testSettingsUpdateRouteIsPutOnApiSettings(): void {
		$routes = Routes::standard()['routes'];
		$update = array_values(array_filter($routes, static fn ($r) => $r['name'] === 'settings#update'))[0];

		$this->assertSame('/api/settings', $update['url']);
		$this->assertSame('PUT', $update['verb']);
	}//end testSettingsUpdateRouteIsPutOnApiSettings()

	public function testCatchAllIsLastAndHasPathRequirement(): void {
		$routes = Routes::standard()['routes'];
		$last = end($routes);

		$this->assertSame('dashboard#catchAll', $last['name']);
		$this->assertSame('/{path}', $last['url']);
		$this->assertSame('(?!api/).+', $last['requirements']['path']);
	}//end testCatchAllIsLastAndHasPathRequirement()

	/**
	 * Being LAST in the `routes` array is not enough to keep the catch-all off
	 * the API, which is the trap this guards. Nextcloud's RouteParser processes
	 * `routes` BEFORE `resources` (RouteParser::parseDefaultRoutes) and Symfony
	 * matches in insertion order, so the catch-all still registers ahead of
	 * every route generated from a `resources` block. `.+` matches slashes, so
	 * without the lookahead it answers the SPA shell for GET api/<resource> —
	 * with HTTP 200, so a JSON caller silently receives HTML.
	 *
	 * Asserts the requirement as a REGEX against real paths rather than as a
	 * string, so it keeps holding if the spelling is ever rewritten.
	 */
	public function testCatchAllRequirementExcludesApiPaths(): void {
		$routes = Routes::standard()['routes'];
		$last = end($routes);
		$pattern = '#^' . $last['requirements']['path'] . '$#';

		foreach (['api/taken', 'api/klanten', 'api/zrc/zaken', 'api/registers/1'] as $apiPath) {
			$this->assertSame(0, preg_match($pattern, $apiPath), "catch-all must NOT match $apiPath");
		}

		foreach (['registers', 'schemas/12', 'features-roadmap', 'zaken/abc-123'] as $spaPath) {
			$this->assertSame(1, preg_match($pattern, $spaPath), "catch-all MUST match $spaPath");
		}
	}//end testCatchAllRequirementExcludesApiPaths()

	public function testIndexRouteIsGetSlash(): void {
		$routes = Routes::standard()['routes'];
		$this->assertSame('/', $routes[0]['url']);
		$this->assertSame('GET', $routes[0]['verb']);
		$this->assertSame('dashboard#page', $routes[0]['name']);
	}//end testIndexRouteIsGetSlash()

	public function testExtraRoutesAreInsertedBeforeCatchAll(): void {
		$result = Routes::standard([
			['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
		]);
		$names = $this->names($result);

		$petsIdx = array_search('pets#index', $names, true);
		$catchAllIdx = array_search('dashboard#catchAll', $names, true);

		$this->assertNotFalse($petsIdx);
		$this->assertLessThan($catchAllIdx, $petsIdx, 'extra routes must precede the SPA catch-all');
	}//end testExtraRoutesAreInsertedBeforeCatchAll()

	public function testExtraRouteOverridesCanonicalByName(): void {
		$result = Routes::standard([
			['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET', 'requirements' => ['custom' => '1']],
		]);

		$healthRoutes = array_values(array_filter($result['routes'], static fn ($r) => $r['name'] === 'health#index'));
		// Exactly one health#index — the override, not the canonical one.
		$this->assertCount(1, $healthRoutes);
		$this->assertSame(['custom' => '1'], $healthRoutes[0]['requirements']);
	}//end testExtraRouteOverridesCanonicalByName()

	public function testDuplicateNameWithinExtraThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		Routes::standard([
			['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
			['name' => 'pets#index', 'url' => '/api/pets/all', 'verb' => 'GET'],
		]);
	}//end testDuplicateNameWithinExtraThrows()
}//end class
