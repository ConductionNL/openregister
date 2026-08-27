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

use OCP\App\IAppManager;
use Throwable;

/**
 * Resolves fleet app ids across the in-flight rename.
 */
final class FleetAppId
{

    /**
     * Candidate ids per canonical app, NEWEST FIRST.
     *
     * Order is the contract: the first entry that is installed wins, so a
     * fully migrated instance resolves to the new id and a beta/main instance
     * falls back to the old one. Adding a new rename means prepending, never
     * replacing — dropping the old id here is what silently breaks the
     * integrations this class exists to protect.
     *
     * @var array<string, list<string>>
     */
    private const CANDIDATES = [
        'integriq' => ['integriq', 'openconnector'],
        'filinq'   => ['filinq', 'docudesk'],
        'thematiq' => ['thematiq', 'nldesign'],
        'stackiq'  => ['stackiq', 'softwarecatalog'],
        'larpinq'  => ['larpinq', 'larpingapp'],
        'dossiq'   => ['dossiq', 'procest'],
        'learniq'  => ['learniq', 'scholiq'],
        'decidiq'  => ['decidiq', 'decidesk'],
        'buildiq'  => ['buildiq', 'openbuild'],
        'keepiq'   => ['keepiq', 'doriath'],
    ];


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
        foreach ((self::CANDIDATES[$canonical] ?? [$canonical]) as $candidate) {
            try {
                if ($appManager->isInstalled($candidate) === true) {
                    return $candidate;
                }
            } catch (Throwable $e) {
                // An app manager that cannot answer for one candidate must not
                // abort the search — the next candidate may still resolve.
                continue;
            }
        }

        return null;

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
        $id = self::resolve(appManager: $appManager, canonical: $canonical);
        if ($id === null) {
            return false;
        }

        try {
            return $appManager->isEnabledForUser($id);
        } catch (Throwable $e) {
            return false;
        }

    }//end isEnabledForUser()


    /**
     * Build an app-scoped path using the id the instance actually has.
     *
     * A URL like `/apps/openconnector/api/sources` is a routing key: Nextcloud
     * mounts routes under the REGISTERED app id, so the path is only valid for
     * the id that is really installed. Hardcoding either name yields a 404 on
     * half the fleet.
     *
     * @param IAppManager $appManager The Nextcloud app manager.
     * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
     * @param string      $suffix     Path after the app segment, no leading slash.
     *
     * @return string|null The path, or null when the app is not installed.
     */
    public static function appPath(IAppManager $appManager, string $canonical, string $suffix = ''): ?string
    {
        $id = self::resolve(appManager: $appManager, canonical: $canonical);
        if ($id === null) {
            return null;
        }

        $path = '/apps/'.$id;
        if ($suffix !== '') {
            $path .= '/'.ltrim($suffix, '/');
        }

        return $path;

    }//end appPath()


}//end class
