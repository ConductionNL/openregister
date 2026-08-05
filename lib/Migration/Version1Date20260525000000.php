<?php

/**
 * Tier-2 deck integration migration.
 *
 * Ensures the `openregister_deck_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable)
 *  - board_id (bigint unsigned, not null)
 *  - stack_id (bigint unsigned, not null)
 *  - card_id (bigint unsigned, not null)
 *  - card_title (string 255, nullable)
 *  - due_date (datetime, nullable)
 *  - labels (text JSON, nullable)
 *  - assignees (text JSON, nullable)
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any
 * missing Tier-2 columns. Runs after Version1Date20260326100001
 * (which drops the Tier-1 table to honour the unfinished
 * linked-entity-types refactor); this migration re-creates the
 * table at the full Tier-2 shape so the Tier-2 deck-link service
 * has a stable home until that refactor lands.
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
 * Tier-2 deck-links table — create-or-extend.
 */
class Version1Date20260525000000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_deck_links') === false) {
            $this->createDeckLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_deck_links') === true
            && $this->extendDeckLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_deck_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createDeckLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_deck_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('stack_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('card_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('card_title', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('due_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('labels', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('assignees', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'card_id'], 'idx_deck_object_card');
        $table->addIndex(['object_uuid'], 'idx_deck_object');
        $table->addIndex(['board_id'], 'idx_deck_board');
        $table->addIndex(['schema_id'], 'idx_deck_schema');

        $output->info('Created openregister_deck_links table (Tier-2 schema)');
    }//end createDeckLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing deck_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendDeckLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_deck_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_deck_schema');
            $output->info('Added schema_id column to openregister_deck_links');
            $changed = true;
        }

        if ($table->hasColumn('due_date') === false) {
            $table->addColumn('due_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added due_date column to openregister_deck_links');
            $changed = true;
        }

        if ($table->hasColumn('labels') === false) {
            $table->addColumn('labels', Types::TEXT, ['notnull' => false, 'default' => null]);
            $output->info('Added labels column to openregister_deck_links');
            $changed = true;
        }

        if ($table->hasColumn('assignees') === false) {
            $table->addColumn('assignees', Types::TEXT, ['notnull' => false, 'default' => null]);
            $output->info('Added assignees column to openregister_deck_links');
            $changed = true;
        }

        return $changed;
    }//end extendDeckLinksTable()
}//end class
