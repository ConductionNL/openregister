<?php

/**
 * FleetAppId — resolve a Conduction fleet app's installed id across the rename.
 *
 * The fleet is mid-rename: `openconnector` became `integriq`, `docudesk`
 * became `filinq`, and so on. The new id has landed on each app's
 * `development` branch, but `beta` and `main` still ship the old one, so at
 * any given moment the id a running instance answers to depends on which
 * branch that app was deployed from.
 *
 * That matters because every cross-app reference is a DUCK-TYPED RUNTIME
 * LOOKUP. `IAppManager::isInstalled('openconnector')` against an instance
 * running `integriq` does not error — it returns false, and the integration
 * silently does nothing. A hard swap to the new id has the same failure in
 * the other direction against a beta/main deployment.
 *
 * So neither id alone is correct. This resolver takes a LIST, newest first,
 * and returns whichever the instance actually has. Callers ask for the
 * canonical (new) name and get back the id that is really installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

use OCA\OpenRegister\Support\FleetApp\FleetAppCatalogue;
use OCA\OpenRegister\Support\FleetApp\FleetAppClass;
use OCA\OpenRegister\Support\FleetApp\FleetAppNames;
use OCA\OpenRegister\Support\FleetApp\FleetAppPath;
use OCA\OpenRegister\Support\FleetApp\FleetAppProbe;
use OCP\App\IAppManager;

/**
 * Resolves fleet app ids across the in-flight rename.
 */
final class FleetAppId
{


    /**
     * Every id an app has answered to, newest first.
     *
     * For the rare lookup that cannot ask the app manager which one is
     * installed, because the name it needs is stored data that moves on its
     * own schedule. integriq's register slug is one: a repair step renames it
     * from `openconnector` to `integriq` after the app id has already moved.
     * An app without a rename answers to its canonical name only.
     *
     * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
     *
     * @return list<string> The candidate ids, newest first.
     *
     * @spec openspec/specs/integration-registry/spec.md#requirement-external-strategy-providers-must-route-through-openconnector
     */
    public static function candidates(string $canonical): array
    {
        return FleetAppCatalogue::candidates(canonical: $canonical);

    }//end candidates()


    /**
     * The id this instance actually has installed, or null if none is.
     *
     * @param IAppManager $appManager The Nextcloud app manager.
     * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
     *
     * @return string|null The installed id, or null when the app is absent.
     */
    public static function resolve(IAppManager $appManager, string $canonical): ?string
    {
        return FleetAppProbe::resolve(appManager: $appManager, canonical: $canonical);

    }//end resolve()


    /**
     * Whether any candidate id for this app is installed.
     *
     * @param IAppManager $appManager The Nextcloud app manager.
     * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
     *
     * @return bool True when the app is present under some id.
     */
    public static function isInstalled(IAppManager $appManager, string $canonical): bool
    {
        return self::resolve(appManager: $appManager, canonical: $canonical) !== null;

    }//end isInstalled()


    /**
     * Whether the app is installed AND enabled for the current user.
     *
     * Resolves the id first so the enabled check runs against the same id the
     * instance actually has, rather than against a name it never registered.
     *
     * @param IAppManager $appManager The Nextcloud app manager.
     * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
     *
     * @return bool True when present and enabled for the current user.
     */
    public static function isEnabledForUser(IAppManager $appManager, string $canonical): bool
    {
        return FleetAppProbe::isEnabledForUser(appManager: $appManager, canonical: $canonical);

    }//end isEnabledForUser()


    /**
     * Build an app-scoped path using the id the instance actually has.
     *
     * A URL like `/apps/openconnector/api/sources` is a routing key: Nextcloud
     * mounts routes under the REGISTERED app id, so the path is only valid for
     * the id that is really installed. Hardcoding either name yields a 404 on
     * half the fleet.
     *
     * @param IAppManager  $appManager The Nextcloud app manager.
     * @param FleetAppPath $path       The canonical (new) app name, e.g. 'integriq', and the path after the app segment.
     *
     * @return string|null The path, or null when the app is not installed.
     */
    public static function appPath(IAppManager $appManager, FleetAppPath $path): ?string
    {
        return FleetAppNames::appPath(appManager: $appManager, path: $path);

    }//end appPath()


    /**
     * The PHP namespace prefix for one concrete installed id.
     *
     * Does NOT touch the autoloader, so a caller that must keep a cross-app
     * class name as inert data (an allow-list value handed to another app's
     * job runner, say) can build the name without loading the class.
     *
     * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
     * @param string $id        A concrete id from {@see FleetAppCatalogue::candidates()}.
     *
     * @return string|null The namespace prefix ending in a backslash, or null
     *                     when this repo has not read that app's namespace.
     */
    public static function namespaceForId(string $canonical, string $id): ?string
    {
        return FleetAppCatalogue::namespaceForId(canonical: $canonical, id: $id);

    }//end namespaceForId()


    /**
     * Build a cross-app FQCN against the id the instance actually has.
     *
     * Resolution is by INSTALLED ID, not by class existence, so the returned
     * name is inert: nothing is autoloaded and nothing is instantiated. When
     * the app is absent the canonical (newest) spelling is returned, because a
     * name handed to an app that is not there is never dereferenced anyway and
     * the newest spelling is the one the fleet is moving to.
     *
     * @param IAppManager   $appManager The Nextcloud app manager.
     * @param FleetAppClass $class      The canonical (new) app name, e.g. 'integriq', and the class path below the namespace.
     *
     * @return string|null The FQCN, or null when this repo has not read that
     *                     app's namespace.
     */
    public static function className(IAppManager $appManager, FleetAppClass $class): ?string
    {
        return FleetAppNames::className(appManager: $appManager, class: $class);

    }//end className()


    /**
     * The first spelling of a cross-app class that is actually loadable.
     *
     * Probes by `class_exists` rather than by installed id, which is the right
     * test for the `class_exists` + `Server::get` seam: what those callers need
     * to know is not which id is registered but which class the autoloader can
     * hand back. Returns null when no candidate resolves — the same answer the
     * caller already handles for "the optional app is not installed".
     *
     * @param string $canonical     Canonical (new) app name, e.g. 'keepiq'.
     * @param string $relativeClass Class path below the namespace, e.g. 'Service\SecretService'.
     *
     * @return string|null The loadable FQCN, or null when none is.
     */
    public static function resolveClass(string $canonical, string $relativeClass): ?string
    {
        return FleetAppProbe::resolveClass(canonical: $canonical, relativeClass: $relativeClass);

    }//end resolveClass()




}//end class
