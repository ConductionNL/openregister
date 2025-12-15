<?php

/**
 * OpenRegister Drop Authorization Exceptions Table Migration
 *
 * This migration drops the authorization_exceptions table as the feature
 * has been discontinued in favor of the simpler group-based RBAC system
 * provided by MultiTenancyTrait.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to drop authorization exceptions table
 *
 * This migration removes the openregister_authorization_exceptions table
 * as the authorization exception feature has been discontinued. The simpler
 * group-based RBAC system in MultiTenancyTrait provides sufficient functionality
 * without the performance overhead and complexity of per-user/per-resource exceptions.
 *
 * @category Database
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link    https://www.OpenRegister.app
 */
class Version1Date20251216000000 extends SimpleMigrationStep
{


    /**
     * Perform the migration.
     *
     * @param IOutput $output        The output interface for logging.
     * @param Closure $schemaClosure Closure that returns the current schema.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The new schema or null if no changes.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Drop the authorization exceptions table if it exists.
        if ($schema->hasTable('openregister_authorization_exceptions') === true) {
            $schema->dropTable('openregister_authorization_exceptions');
            $output->info('Dropped openregister_authorization_exceptions table');
            return $schema;
        }

        return null;

    }//end changeSchema()


}//end class
