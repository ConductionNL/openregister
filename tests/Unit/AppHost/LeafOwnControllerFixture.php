<?php
/**
 * Fixture: a leaf app that ships its OWN DashboardController.
 *
 * AppHost must not alias its generic controller over this class. Declaring the
 * class here (rather than inside the test file) keeps BootstrapTest.php to a
 * single namespace and gives `class_exists()` something real to find.
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

namespace OCA\LeafWithOwnDashboard\Controller;

/**
 * Stands in for a consuming app's own dashboard controller — the class that
 * was being shadowed, taking its `summary()` route and its
 * `allowEvalWasm(true)` CSP with it.
 */
class DashboardController
{
    /**
     * A method that exists ONLY on the leaf's controller. Routed as
     * `dashboard#summary`, it 500'd while the generic controller was aliased
     * over this class.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [];

    }//end summary()
}//end class
