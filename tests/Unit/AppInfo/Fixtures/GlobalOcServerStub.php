<?php
/**
 * Minimal, guarded global `\OC` fake for exercising the REAL
 * Application::getRegisteredAppContainer() seam under the local unit-test
 * bootstrap (which, unlike bootstrap-unit.php, does NOT load
 * tests/stubs/NextcloudInternalStubs.php and therefore leaves `\OC`
 * undefined). Guarded by class_exists() so it never redefines the NC-runtime
 * or integration-stub `\OC` when those are present.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

if (class_exists('OC', false) === false) {
    /**
     * Global service-locator fake — only the static `$server` seam is used.
     */
    class OC
    {

        /**
         * The server container fake (assigned per test).
         *
         * @var object
         */
        public static object $server;

    }//end class
}//end if
