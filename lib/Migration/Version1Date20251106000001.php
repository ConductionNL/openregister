<?php

/**
 * Add missing columns to agents table
 *
 * This migration adds request_quota, token_quota, and groups columns
 * that were missing from the original agents table creation.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add missing columns to agents table
 *
 * Adds columns that were in the original migration but not added
 * because the table already existed.
 */
class Version1Date20251106000001 extends SimpleMigrationStep
{
    /**
     * Add missing columns to agents table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $output->info(message: '🔧 Adding missing columns to agents table...');

        if ($schema->hasTable('openregister_agents') === true) {
            $table   = $schema->getTable('openregister_agents');
            $updated = false;

            // Add request_quota column if missing.
            if ($table->hasColumn('request_quota') === false) {
                $table->addColumn(
                    'request_quota',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'API request quota per day (0 = unlimited)',
                    ]
                );
                $output->info(message: '   ✓ Added request_quota column');
                $updated = true;
            }

            // Add token_quota column if missing.
            if ($table->hasColumn('token_quota') === false) {
                $table->addColumn(
                    'token_quota',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Token quota per request (0 = unlimited)',
                    ]
                );
                $output->info(message: '   ✓ Added token_quota column');
                $updated = true;
            }

            // Add groups column if missing.
            if ($table->hasColumn('groups') === false) {
                $table->addColumn(
                    'groups',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Nextcloud group IDs with access to this agent',
                    ]
                );
                $output->info(message: '   ✓ Added groups column');
                $updated = true;
            }

            if ($updated === false) {
                $output->info(message: 'ℹ️  All columns already exist');
                return null;
            }

            $output->info(message: '✅ Missing columns added successfully to agents table');
            return $schema;
        }//end if

        return null;
    }//end changeSchema()
}//end class
