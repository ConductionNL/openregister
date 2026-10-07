<?php

/**
 * OpenRegister AppHost — Canonical Route Table
 *
 * A leaf app's `appinfo/routes.php` becomes a single statement:
 *
 *   return \OCA\OpenRegister\AppHost\Routes::standard();
 *
 * with any app-specific routes appended:
 *
 *   return \OCA\OpenRegister\AppHost\Routes::standard([
 *       ['name' => 'pets#index', 'url' => '/api/pets', 'verb' => 'GET'],
 *   ]);
 *
 * The returned set is bit-compatible with the petstore reference skeleton:
 * the route names (`dashboard#page`, `dashboard#catchAll`, `settings#index`,
 * `settings#create`, `settings#load`, `preferences#getPreference`,
 * `preferences#setPreference`, `metrics#index`, `health#index`), URLs and
 * verbs are unchanged, so info.xml navigation entries keep resolving. The SPA
 * catch-all is ordered LAST and carries a distinct name so it never shadows the
 * dashboard index route.
 *
 * ## What is, and is not, safe when OpenRegister is disabled
 *
 * This file's BODY references no `OCA\OpenRegister\…` symbol — it is a pure
 * array builder with no dependency on the rest of the app. An earlier version of
 * this docblock concluded from that that "requiring it from a leaf `routes.php`
 * is safe even when OpenRegister is disabled". That does not follow, and it is
 * wrong as written.
 *
 * Calling `\OCA\OpenRegister\AppHost\Routes::standard()` first requires
 * RESOLVING the symbol `Routes`, which is an ordinary autoload of an
 * `OCA\OpenRegister\` class. With OpenRegister absent or disabled that throws an
 * `\Error` out of the leaf's `appinfo/routes.php` — the route file being a pure
 * array builder does not help, because control never reaches the array.
 *
 * A leaf that wants to survive a disabled OpenRegister must therefore guard the
 * call, which is what the canonical form does:
 *
 *     if (class_exists('OCA\OpenRegister\AppHost\Routes') === true) {
 *         return \OCA\OpenRegister\AppHost\Routes::standard($extra);
 *     }
 *     return ['routes' => $ownRoutes];   // degraded, app-specific routes only
 *
 * A bare `return \OCA\OpenRegister\AppHost\Routes::standard([...]);` with no
 * guard is a hard dependency on OpenRegister being installed AND enabled.
 *
 * Route files are loaded by the router during request matching, long after every
 * app has registered, so — unlike `Application::register()` — they are NOT
 * exposed to the app-registration sort-order trap. See the load-order section in
 * `Bootstrap`'s docblock for that trap and the autoload prelude that closes it;
 * a leaf needs the prelude for its `register()` regardless of what its
 * `routes.php` does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use InvalidArgumentException;

/**
 * Canonical AppHost route table builder.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-3.2
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
 */
class Routes {
	/**
	 * Return the canonical route array, merging app-specific `$extra` routes.
	 *
	 * App-specific routes are inserted BEFORE the SPA catch-all so they keep
	 * priority over the `/{path}` fallback. A duplicate route name in `$extra`
	 * overrides the canonical entry of the same name (intentional: lets an app
	 * re-point one route at a local controller) — but a duplicate name WITHIN
	 * `$extra` itself throws, since Symfony silently replaces same-named routes
	 * and that is always a mistake.
	 *
	 * An app that also serves manifest-declared public pages calls
	 * {@see self::standardWithPublicPages()} instead. The two are separate
	 * entry points rather than one with a flag: the public-page route needs a
	 * `publicPage()` method on the app's dashboard controller, so the choice
	 * is about what the app HAS, not about a setting, and a call site reads
	 * better saying which table it wants than passing `true`.
	 *
	 * @param array<int, array<string, mixed>> $extra App-specific routes.
	 *
	 * @return array{routes: array<int, array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When `$extra` contains duplicate route names.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public static function standard(array $extra = []): array {
		return self::build(extra: $extra, publicPages: false);
	}//end standard()

	/**
	 * The canonical route table plus the public-page route.
	 *
	 * Adds ONE more route, `dashboard#publicPage` on `/public/{path}`, just
	 * before the catch-all. It is a separate entry point because it needs a
	 * `publicPage()` method on the app's dashboard controller: an app that
	 * aliases the generic one has it already, and an app that writes its own
	 * would answer HTTP 500 on a route it never asked for. What the route
	 * serves is still decided per page by the app's manifest, so calling this
	 * opens nothing by itself.
	 *
	 * @param array<int, array<string, mixed>> $extra App-specific routes.
	 *
	 * @return array{routes: array<int, array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When `$extra` contains duplicate route names.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public static function standardWithPublicPages(array $extra = []): array {
		return self::build(extra: $extra, publicPages: true);
	}//end standardWithPublicPages()

	/**
	 * Build the merged table, with or without the public-page route.
	 *
	 * @param array<int, array<string, mixed>> $extra       App-specific routes.
	 * @param boolean                          $publicPages Whether to append the public-page route.
	 *
	 * @return array{routes: array<int, array<string, mixed>>}
	 *
	 * @throws \InvalidArgumentException When `$extra` contains duplicate route names.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
	 */
	private static function build(array $extra, bool $publicPages): array {
		self::assertNoDuplicateNames(extra: $extra);

		$extraKeys = [];
		foreach ($extra as $route) {
			if (isset($route['name']) === true) {
				$extraKeys[self::registrationKey(route: $route)] = true;
			}
		}

		// Canonical routes, minus the SPA catch-all (appended last).
		$canonical = [];
		foreach (self::canonicalRoutes() as $route) {
			// An $extra route that registers under the same key overrides the
			// canonical one. The key, not the name: an $extra entry carrying a
			// `postfix` registers under a DIFFERENT name, so it replaces
			// nothing, and dropping the canonical entry for it would delete a
			// route no one asked to delete.
			if (isset($extraKeys[self::registrationKey(route: $route)]) === true) {
				continue;
			}

			$canonical[] = $route;
		}

		$merged = array_merge($canonical, $extra);
		if ($publicPages === true) {
			$merged[] = self::publicPageRoute();
		}

		$merged[] = self::catchAllRoute();

		// The canonical half, which the override above does not reach. The
		// catch-all and the public page route are appended AFTER `$extra`, so
		// an `$extra` entry registering under either name is silently replaced
		// by it rather than overriding it. Assert on the whole merged set, so
		// the answer is about what registers and not about what was declared.
		self::assertEveryRouteRegisters(routes: $merged);

		return ['routes' => $merged];
	}//end build()

	/**
	 * The route that serves a declared public page without a session.
	 *
	 * It sits before the catch-all so `/public/…` reaches the public shell
	 * rather than the authenticated one, and after `$extra` so an app's own
	 * route on a `/public/…` address still wins.
	 *
	 * @return array<string, mixed> The route.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public static function publicPageRoute(): array {
		return [
			'name' => 'dashboard#publicPage',
			'url' => '/public/{path}',
			'verb' => 'GET',
			'requirements' => ['path' => '.+'],
			'defaults' => ['path' => ''],
		];
	}//end publicPageRoute()

	/**
	 * The canonical AppHost routes (everything except the SPA catch-all).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function canonicalRoutes(): array {
		return [
			// Dashboard page.
			['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],

			// Settings API. `create` (POST) is the fleet's legacy write verb;
			// `update` (PUT) is the canonical ADR-066 write. Both dispatch to
			// the same write path on the generic controllers.
			['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
			['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
			['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
			['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'POST'],

			// Generic per-user preferences (shared nextcloud-vue widgets).
			['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
			['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

			// Observability (ADR-006 / ADR-040).
			['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
			['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

			// Store plane (ADR-080, ADR-114 Decision 4). Declaring these here
			// is only HALF the wiring: the leaf app's `Controller\StoreController`
			// must ALSO be aliased at the engine's GenericStoreController, or
			// the router resolves a class that does not exist and every store
			// request 500s at dispatch time.
			//
			// 🔴 AND NOT EVERY ADOPTER CALLS `Bootstrap::register()`. This
			// table is adopted independently of that bootstrap, and an app that
			// binds its controllers by hand gets the routes and none of the
			// bindings. Measured 2026-09-03 on a running instance: decidiq,
			// filinq and planninq each returned HTTP 500 here, on a route none
			// of them had asked for, while keepiq answered fine because it does
			// call `register()`. Such an app needs exactly one line,
			// `Bootstrap::aliasStoreController()`, which is public for this.
			//
			// An app that declares no `store` block in its manifest still gets
			// these routes, and the controller answers `not_configured` for
			// them. That is deliberate: a route table that varies per app by
			// manifest content would make the SPA catch-all's position depend
			// on configuration, and the catch-all's position is load-bearing.
			['name' => 'store#search', 'url' => '/api/store/items', 'verb' => 'GET'],
			[
				'name' => 'store#install',
				'url' => '/api/store/items/{slug}/install',
				'verb' => 'POST',
				'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]'],
			],
		];
	}//end canonicalRoutes()

	/**
	 * The SPA catch-all route — same controller as `dashboard#page`, distinct
	 * name so it does not replace the index route in Symfony's router.
	 *
	 * @return array<string, mixed>
	 */
	private static function catchAllRoute(): array {
		return [
			'name' => 'dashboard#catchAll',
			'url' => '/{path}',
			'verb' => 'GET',
			// ⚠️ `(?!api/)` is load-bearing for any adopter whose routes.php
			// also declares a `resources` block. Nextcloud's RouteParser
			// processes the `routes` array BEFORE the `resources` array
			// (RouteParser::parseDefaultRoutes) and Symfony matches in
			// insertion order, so this route registers ahead of every
			// resource-generated route no matter that it is appended LAST
			// here. `.+` matches slashes, so without the lookahead it
			// swallows GET api/<resource> and answers the SPA shell with
			// HTTP 200 — a JSON caller receives HTML and nothing errors
			// loudly. zaakafhandelapp lost all seventeen of its ZGW resource
			// routes that way. The SPA never needs an `api/` path.
			'requirements' => ['path' => '(?!api/).+'],
			'defaults' => ['path' => ''],
		];
	}//end catchAllRoute()

	/**
	 * The name Nextcloud actually registers a route under, minus the app id.
	 *
	 * `OC\AppFramework\Routing\RouteParser::processRoute()` builds
	 * `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
	 * and `RouteCollection::add()` OVERWRITES an entry of the same name.
	 * Neither the URL nor the verb is part of it, so two entries on one
	 * controller action are one route unless a `postfix` separates them, and
	 * the last one declared is the one that exists.
	 *
	 * @param array<string, mixed> $route One route entry.
	 *
	 * @return string The registration key.
	 */
	private static function registrationKey(array $route): string {
		return strtolower((string)($route['name'] ?? '') . (string)($route['postfix'] ?? ''));
	}//end registrationKey()

	/**
	 * Guard against two `$extra` routes registering under one name.
	 *
	 * Keyed on the registration key rather than on the name, because a
	 * `postfix` is exactly how an app gives a second route on the same
	 * controller action its own name. Comparing names alone refused that
	 * legitimate pair and so blocked the one available remedy.
	 *
	 * @param array<int, array<string, mixed>> $extra App-specific routes.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When two `$extra` routes register alike.
	 */
	private static function assertNoDuplicateNames(array $extra): void {
		$seen = [];
		foreach ($extra as $route) {
			if (isset($route['name']) === false) {
				continue;
			}

			$key = self::registrationKey(route: $route);
			if (isset($seen[$key]) === true) {
				throw new InvalidArgumentException(
					sprintf(
						'Duplicate route name "%s" in AppHost Routes::standard($extra). Give one of them its own "postfix".',
						$key
					)
				);
			}

			$seen[$key] = true;
		}
	}//end assertNoDuplicateNames()

	/**
	 * Every entry in the merged table must survive registration.
	 *
	 * @param array<int, array<string, mixed>> $routes The merged route table.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When two entries register alike.
	 */
	private static function assertEveryRouteRegisters(array $routes): void {
		$seen = [];
		foreach ($routes as $route) {
			$key = self::registrationKey(route: $route);
			if (isset($seen[$key]) === true) {
				throw new InvalidArgumentException(
					sprintf(
						'Route "%s" registers twice in AppHost Routes::standard(): %s %s replaces %s %s. Give one of them its own "postfix".',
						$key,
						(string)($route['verb'] ?? 'GET'),
						(string)($route['url'] ?? '?'),
						(string)($seen[$key]['verb'] ?? 'GET'),
						(string)($seen[$key]['url'] ?? '?')
					)
				);
			}

			$seen[$key] = $route;
		}
	}//end assertEveryRouteRegisters()
}//end class
