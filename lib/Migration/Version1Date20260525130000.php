<?php

/**
 * Tier-2 polls integration migration.
 *
 * Ensures the `openregister_poll_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable)
 *  - poll_id (bigint unsigned, not null)
 *  - poll_title (string 255, nullable)
 *  - poll_type (string 64, nullable)
 *  - deadline (datetime, nullable)
 *  - voter_count (bigint unsigned, nullable — cached tally)
 *  - option_count (bigint unsigned, nullable — cached tally)
 *  - closed (boolean, default false)
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any
 * missing Tier-2 columns.  Companion to the Tier-2 deck/forms/email
 * link tables; the wrapping `PollLinkService` replaces the title-marker
 * convention from the original PollsProvider with a proper persistence
 * layer so links survive title edits + don't pollute Polls' UX.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tier-2 poll-links table — create-or-extend.
 */
class Version1Date20260525130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('openregister_poll_links') === false) {
            $this->createPollLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_poll_links') === true
            && $this->extendPollLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_poll_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createPollLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_poll_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('poll_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('poll_title', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('poll_type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('deadline', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('voter_count', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('option_count', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('closed', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'poll_id'], 'idx_poll_object_poll');
        $table->addIndex(['object_uuid'], 'idx_poll_object');
        $table->addIndex(['poll_id'], 'idx_poll_poll');
        $table->addIndex(['schema_id'], 'idx_poll_schema');

        $output->info('Created openregister_poll_links table (Tier-2 schema)');
    }//end createPollLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing poll_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendPollLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_poll_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_poll_schema');
            $output->info('Added schema_id column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('poll_title') === false) {
            $table->addColumn('poll_title', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added poll_title column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('poll_type') === false) {
            $table->addColumn('poll_type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $output->info('Added poll_type column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('deadline') === false) {
            $table->addColumn('deadline', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added deadline column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('voter_count') === false) {
            $table->addColumn('voter_count', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $output->info('Added voter_count column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('option_count') === false) {
            $table->addColumn('option_count', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $output->info('Added option_count column to openregister_poll_links');
            $changed = true;
        }

        if ($table->hasColumn('closed') === false) {
            $table->addColumn('closed', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $output->info('Added closed column to openregister_poll_links');
            $changed = true;
        }

        return $changed;
    }//end extendPollLinksTable()
}//end class
