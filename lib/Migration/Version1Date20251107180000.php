<?php

/**
 * OpenRegister Migration - Add Tools and User Columns to Agents Table
 *
 * This migration adds support for LLphant function tools that agents can use
 * to interact with OpenRegister data (registers, schemas, objects).
 * Also adds a user column for cron/background job scenarios.
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
 * Add tools and user columns to agents table
 *
 * Columns added:
 * - tools: JSON array of enabled tool names (e.g., ['register', 'schema', 'objects'])
 * - user: User ID for cron/background scenarios when no session exists
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251107180000 extends SimpleMigrationStep
{
    /**
     * Modify the database schema
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return ISchemaWrapper|null The modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        if ($schema->hasTable('openregister_agents') === true) {
            $table = $schema->getTable('openregister_agents');

            // Add tools column (JSON array of enabled tool names).
            if ($table->hasColumn('tools') === false) {
                $table->addColumn(
                        'tools',
                        Types::TEXT,
                        [
                            'notnull' => false,
                            'default' => null,
                            'comment' => 'JSON array of enabled tool names for agent function calling',
                        ]
                        );
                $output->info(message: '✅ Added tools column to agents table');
                $updated = true;
            } else {
                $output->info(message: 'ℹ️  tools column already exists in agents table');
            }

            // Add user column (for cron/background job scenarios).
            if ($table->hasColumn('user') === false) {
                $table->addColumn(
                        'user',
                        Types::STRING,
                        [
                            'notnull' => false,
                            'length'  => 255,
                            'default' => null,
                            'comment' => 'User ID for running agent in cron/background scenarios',
                        ]
                        );
                $output->info(message: '✅ Added user column to agents table');
                $updated = true;
            } else {
                $output->info(message: 'ℹ️  user column already exists in agents table');
            }
        } else {
            $output->warning(message: '⚠️  openregister_agents table does not exist');
        }//end if

        if ($updated === true) {
            return $schema;
        }

        return null;

    }//end changeSchema()

    /**
     * Post-schema change hook
     *
     * @param IOutput $output        Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: '✅ Migration complete - Agents can now use LLphant function tools');
        $output->info('   Available tools: RegisterTool, SchemaTool, ObjectsTool');
        $output->info(message: '   Tools can be enabled per agent via the Edit Agent modal');

    }//end postSchemaChange()
}//end class
