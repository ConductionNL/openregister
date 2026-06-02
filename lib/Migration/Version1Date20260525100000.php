<?php

/**
 * Tier-2 email integration migration.
 *
 * Ensures the `openregister_email_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null)
 *  - register_id (bigint unsigned, not null)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - mail_account_id (int, not null)
 *  - mail_message_id (int, not null)
 *  - mail_message_uid (string 255, nullable)
 *  - subject (string 512, nullable)
 *  - sender (string 255, nullable)
 *  - mail_date (datetime, nullable)
 *  - metadata (text JSON, nullable)
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any
 * missing Tier-2 columns. Runs after Version1Date20260326100001
 * (which drops the Tier-1 table to honour the unfinished
 * linked-entity-types refactor); this migration re-creates the
 * table at the full Tier-2 shape so the Tier-2 email-link service
 * has a stable home until that refactor lands.
 *
 * Adds a composite unique index on
 * `(object_uuid, mail_account_id, mail_message_id, mail_message_uid)`
 * so the picker's "link existing" upsert is idempotent and the same
 * message is never linked twice to the same object — even across
 * differing UID values for the same account/message id (Mail re-syncs).
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
 * Tier-2 email-links table — create-or-extend.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260525100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
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

        if ($schema->hasTable('openregister_email_links') === false) {
            $this->createEmailLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_email_links') === true
            && $this->extendEmailLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_email_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     * @param IOutput        $output Migration output.
     *
     * @return void
     */
    private function createEmailLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_email_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('mail_account_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('mail_message_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('mail_message_uid', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('sender', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('mail_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('metadata', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['object_uuid', 'mail_account_id', 'mail_message_id', 'mail_message_uid'],
            'idx_email_object_message'
        );
        $table->addIndex(['object_uuid'], 'idx_email_object');
        $table->addIndex(['mail_account_id', 'mail_message_id'], 'idx_email_account_message');
        $table->addIndex(['sender'], 'idx_email_sender');
        $table->addIndex(['schema_id'], 'idx_email_schema');

        $output->info('Created openregister_email_links table (Tier-2 schema)');
    }//end createEmailLinksTable()

    /**
     * Add any missing Tier-2 columns/indexes to an existing email_links table.
     *
     * Each column is checked individually before being added so the
     * migration is fully idempotent across deployments that retained
     * the Tier-1 table (Version1Date20260326000000) without ever running
     * the drop (Version1Date20260326100001).
     *
     * @param ISchemaWrapper $schema The schema wrapper.
     * @param IOutput        $output Migration output.
     *
     * @return bool True when a column or index was added.
     */
    private function extendEmailLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_email_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $output->info('Added schema_id column to openregister_email_links');
            $changed = true;
        }

        if ($table->hasIndex('idx_email_schema') === false) {
            $table->addIndex(['schema_id'], 'idx_email_schema');
            $output->info('Added idx_email_schema index to openregister_email_links');
            $changed = true;
        }

        if ($table->hasColumn('register_id') === false) {
            $table->addColumn(
                'register_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $output->info('Added register_id column to openregister_email_links');
            $changed = true;
        }

        if ($table->hasColumn('mail_date') === false) {
            $table->addColumn('mail_date', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added mail_date column to openregister_email_links');
            $changed = true;
        }

        if ($table->hasColumn('metadata') === false) {
            $table->addColumn('metadata', Types::TEXT, ['notnull' => false, 'default' => null]);
            $output->info('Added metadata column to openregister_email_links');
            $changed = true;
        }

        if ($table->hasIndex('idx_email_object_message') === false) {
            $table->addUniqueIndex(
                ['object_uuid', 'mail_account_id', 'mail_message_id', 'mail_message_uid'],
                'idx_email_object_message'
            );
            $output->info('Added idx_email_object_message unique index to openregister_email_links');
            $changed = true;
        }

        return $changed;
    }//end extendEmailLinksTable()
}//end class
