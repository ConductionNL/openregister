<?php

declare(strict_types=1);

/**
 * Add tools and user columns to agents table
 *
 * This migration adds support for LLphant function tools that agents can use
 * to interact with OpenRegister data (registers, schemas, objects).
 * Also adds a user column for cron/background job scenarios.
 * Also removes the is_default column from organisations table as it's no longer
 * used in the Organisation entity. The default organisation is now managed via
 * configuration instead of a database column.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git-id>
 *
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add tools and user columns to agents table and remove is_default from organisations
 *
 * Columns added:
 * - tools: JSON array of enabled tool names (e.g., ['register', 'schema', 'objects'])
 * - user: User ID for cron/background scenarios when no session exists
 *
 * Columns removed:
 * - is_default: Removed from organisations table (now managed via configuration)
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251107180000 extends SimpleMigrationStep
{
    /**
     * Modify the database schema
     *
     * @param IOutput $output   Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options  Options
     *
     * @return ISchemaWrapper|null The modified schema or null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $updated = false;

        if ($schema->hasTable('openregister_agents')) {
            $table = $schema->getTable('openregister_agents');
            
            // Add tools column (JSON array of enabled tool names)
            if (!$table->hasColumn('tools')) {
                $table->addColumn('tools', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                    'comment' => 'JSON array of enabled tool names for agent function calling'
                ]);
                $output->info('✅ Added tools column to agents table');
                $updated = true;
            } else {
                $output->info('ℹ️  tools column already exists in agents table');
            }

            // Add user column (for cron/background job scenarios)
            if (!$table->hasColumn('user')) {
                $table->addColumn('user', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                    'comment' => 'User ID for running agent in cron/background scenarios'
                ]);
                $output->info('✅ Added user column to agents table');
                $updated = true;
            } else {
                $output->info('ℹ️  user column already exists in agents table');
            }
        } else {
            $output->warning('⚠️  openregister_agents table does not exist');
        }

        // Remove is_default column from organisations table if it exists
        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            if ($table->hasColumn('is_default')) {
                $table->dropColumn('is_default');
                $output->info('✅ Removed is_default column from organisations table');
                $updated = true;
            } else {
                $output->info('ℹ️  is_default column does not exist in organisations table');
            }
        } else {
            $output->warning('⚠️  openregister_organisations table does not exist');
        }

        return $updated ? $schema : null;
    }

    /**
     * Post-schema change hook
     *
     * @param IOutput $output   Output handler
     * @param Closure $schemaClosure Schema closure
     * @param array   $options  Options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info('✅ Migration complete - Agents can now use LLphant function tools');
        $output->info('   Available tools: RegisterTool, SchemaTool, ObjectsTool');
        $output->info('   Tools can be enabled per agent via the Edit Agent modal');
        $output->info('✅ Removed is_default column from organisations table');
        $output->info('   Default organisation is now managed via configuration');
    }
}

