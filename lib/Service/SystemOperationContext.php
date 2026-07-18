<?php

/**
 * OpenRegister SystemOperationContext
 *
 * Scoped elevation for trusted, code-initiated operations (app configuration
 * imports, repair steps, background maintenance) that run without a user
 * session. RBAC fails closed for anonymous principals (#1955), which is
 * correct for requests — but Nextcloud boots apps BEFORE the session user is
 * resolved and webcron requests never have a user at all, so legitimate
 * app-initiated writes (importing the app's own shipped register config,
 * migrating its own objects) are denied as "Anonymous" on every boot.
 *
 * The existing `PHP_SAPI === 'cli'` trust in MultiTenancyTrait covers occ and
 * CLI cron only; this class extends the same trust to explicitly-scoped code
 * blocks regardless of SAPI. Elevation is only enterable from PHP code (never
 * from request data), applies to the current PHP request only, and ends with
 * the callable — including on exceptions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

final class SystemOperationContext
{

    /**
     * Nesting depth of active system-operation scopes.
     *
     * A depth counter (not a boolean) so nested run() calls compose: the
     * elevation only ends when the OUTERMOST scope exits.
     *
     * @var integer
     */
    private static int $depth = 0;

    /**
     * This class is a static scope holder and must not be instantiated.
     */
    private function __construct()
    {
    }//end __construct()

    /**
     * Run a callable inside a trusted system-operation scope.
     *
     * While the callable executes, RBAC checks in MultiTenancyTrait and
     * PermissionHandler treat the caller as a trusted system principal —
     * mirroring the existing CLI trust. The scope is released in a finally
     * block, so an exception inside the operation cannot leak elevation.
     *
     * @param callable $operation The trusted operation to execute.
     *
     * @return mixed Whatever the callable returns.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-14
     */
    public static function run(callable $operation)
    {
        self::$depth++;

        try {
            return $operation();
        } finally {
            self::$depth--;
        }
    }//end run()

    /**
     * Whether a system-operation scope is currently active.
     *
     * @return bool True when executing inside run().
     */
    public static function isActive(): bool
    {
        return self::$depth > 0;
    }//end isActive()
}//end class
