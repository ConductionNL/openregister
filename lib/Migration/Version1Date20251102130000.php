<?php

declare(strict_types=1);

/*
 * OpenRegister Applications Groups Column Migration
 *
 * This migration adds the 'groups' column to the openregister_applications table
 * to support group-based access control for applications.
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
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add groups column to applications table
 *
 * This migration adds support for group-based access control:
 * - Applications can be restricted to specific Nextcloud groups
 * - Groups are stored as an array of group ID strings
 * - Empty array means all users have access
 */
class Version1Date20251102130000 extends SimpleMigrationStep
{


    /**
     * Add groups column to applications table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        $output->info('🔧 Adding groups column to applications table...');

        if ($schema->hasTable('openregister_applications')) {
            $table = $schema->getTable('openregister_applications');

            // Add groups column if it doesn't exist
            if (!$table->hasColumn('groups')) {
                $table->addColumn(
                        'groups',
                        Types::JSON,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'Array of Nextcloud group IDs that have access to this application',
                        ]
                        );

                $output->info('✅ Added groups column to openregister_applications table');
                $output->info('🎯 Applications now support:');
                $output->info('   • Group-based access control');
                $output->info('   • Restriction by Nextcloud group membership');
                $output->info('   • Empty array = all users have access');

                return $schema;
            } else {
                $output->info('ℹ️  Groups column already exists, skipping...');
            }//end if
        } else {
            $output->info('⚠️  Applications table not found!');
        }//end if

        return null;

    }//end changeSchema()


}//end class
