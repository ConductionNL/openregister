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

/**
 * Canonical AppHost route table builder.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-3.2
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
 */
class Routes
{
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
     * @param array<int, array<string, mixed>> $extra App-specific routes.
     *
     * @return array{routes: array<int, array<string, mixed>>}
     *
     * @throws \InvalidArgumentException When `$extra` contains duplicate route names.
     *
     * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
     */
    public static function standard(array $extra=[]): array
    {
        self::assertNoDuplicateNames(extra: $extra);

        $extraNames = [];
        foreach ($extra as $route) {
            if (isset($route['name']) === true) {
                $extraNames[(string) $route['name']] = true;
            }
        }

        // Canonical routes, minus the SPA catch-all (appended last).
        $canonical = [];
        foreach (self::canonicalRoutes() as $route) {
            // An $extra route with the same name overrides the canonical one.
            if (isset($extraNames[$route['name']]) === true) {
                continue;
            }

            $canonical[] = $route;
        }

        $merged   = array_merge($canonical, $extra);
        $merged[] = self::catchAllRoute();

        return ['routes' => $merged];
    }//end standard()

    /**
     * The canonical AppHost routes (everything except the SPA catch-all).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function canonicalRoutes(): array
    {
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
        ];
    }//end canonicalRoutes()

    /**
     * The SPA catch-all route — same controller as `dashboard#page`, distinct
     * name so it does not replace the index route in Symfony's router.
     *
     * @return array<string, mixed>
     */
    private static function catchAllRoute(): array
    {
        return [
            'name'         => 'dashboard#catchAll',
            'url'          => '/{path}',
            'verb'         => 'GET',
            'requirements' => ['path' => '.+'],
            'defaults'     => ['path' => ''],
        ];
    }//end catchAllRoute()

    /**
     * Guard against duplicate route names within the caller's `$extra` set.
     *
     * @param array<int, array<string, mixed>> $extra App-specific routes.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When two `$extra` routes share a name.
     */
    private static function assertNoDuplicateNames(array $extra): void
    {
        $seen = [];
        foreach ($extra as $route) {
            if (isset($route['name']) === false) {
                continue;
            }

            $name = (string) $route['name'];
            if (isset($seen[$name]) === true) {
                throw new \InvalidArgumentException(sprintf('Duplicate route name "%s" in AppHost Routes::standard($extra)', $name));
            }

            $seen[$name] = true;
        }
    }//end assertNoDuplicateNames()
}//end class
