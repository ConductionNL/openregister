<?php

/**
 * Consolidated database migration for 7 new tables.
 *
 * Creates the following tables in a single idempotent migration:
 * - openregister_actions
 * - openregister_action_logs
 * - openregister_email_links
 * - openregister_contact_links
 * - openregister_deck_links
 * - openregister_selection_lists
 * - openregister_destruction_lists
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Creates 7 tables: actions, action_logs, email_links, contact_links,
 * deck_links, selection_lists, destruction_lists.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class Version1Date20260326000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema  = $schemaClosure();
        $changed = false;

        if ($this->createActionsTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createActionLogsTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createEmailLinksTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createContactLinksTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createDeckLinksTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createSelectionListsTable($schema, $output) === true) {
            $changed = true;
        }

        if ($this->createDestructionListsTable($schema, $output) === true) {
            $changed = true;
        }

        if ($changed === true) {
            return $schema;
        }

        return null;
    }//end changeSchema()

    /**
     * Create the openregister_actions table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createActionsTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_actions') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_actions');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('slug', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 20, 'default' => '1.0.0']);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'draft']);
        $table->addColumn('event_type', Types::TEXT, ['notnull' => true]);
        $table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 50]);
        $table->addColumn('workflow_id', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => 'sync']);
        $table->addColumn('execution_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('timeout', Types::INTEGER, ['notnull' => true, 'default' => 30]);
        $table->addColumn('on_failure', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'reject']);
        $table->addColumn('on_timeout', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'reject']);
        $table->addColumn('on_engine_down', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'allow']);
        $table->addColumn('filter_condition', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('configuration', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('mapping', Types::INTEGER, ['notnull' => false, 'default' => null]);
        $table->addColumn('schemas', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('registers', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('schedule', Types::STRING, ['notnull' => false, 'length' => 100, 'default' => null]);
        $table->addColumn('max_retries', Types::INTEGER, ['notnull' => true, 'default' => 3]);
        $table->addColumn('retry_policy', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'exponential']);
        $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('application', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('last_executed_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('execution_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('success_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('failure_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('updated', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('deleted', Types::DATETIME, ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'or_actions_uuid_idx');
        $table->addUniqueIndex(['slug'], 'or_actions_slug_idx');
        $table->addIndex(['status'], 'or_actions_status_idx');
        $table->addIndex(['enabled'], 'or_actions_enabled_idx');
        $table->addIndex(['schedule'], 'or_actions_schedule_idx');
        $table->addIndex(['deleted'], 'or_actions_deleted_idx');

        $output->info('Created openregister_actions table');

        return true;
    }//end createActionsTable()

    /**
     * Create the openregister_action_logs table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createActionLogsTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_action_logs') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_action_logs');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('action_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
        $table->addColumn('action_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
        $table->addColumn('schema_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
        $table->addColumn('register_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
        $table->addColumn('engine', Types::STRING, ['notnull' => true, 'length' => 50]);
        $table->addColumn('workflow_id', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20]);
        $table->addColumn('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null]);
        $table->addColumn('request_payload', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('response_payload', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('error_message', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('attempt', Types::INTEGER, ['notnull' => true, 'default' => 1]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['action_id'], 'or_actlog_action_id_idx');
        $table->addIndex(['action_uuid'], 'or_actlog_action_uuid_idx');
        $table->addIndex(['object_uuid'], 'or_actlog_object_uuid_idx');
        $table->addIndex(['status'], 'or_actlog_status_idx');

        $output->info('Created openregister_action_logs table');

        return true;
    }//end createActionLogsTable()

    /**
     * Create the openregister_email_links table.
     *
     * From feature/1001/mail-sidebar.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createEmailLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_email_links') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_email_links');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
            'length'        => 20,
        ]);
        $table->addColumn('mail_account_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('mail_message_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('mail_message_uid', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('subject', Types::STRING, [
            'notnull' => false,
            'length'  => 512,
        ]);
        $table->addColumn('sender', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('mail_date', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('object_uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('register_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('schema_id', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('linked_by', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('linked_at', Types::DATETIME, [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['mail_account_id', 'mail_message_id'], 'email_links_msg_idx');
        $table->addIndex(['sender'], 'email_links_sender_idx');
        $table->addIndex(['object_uuid'], 'email_links_obj_idx');
        $table->addUniqueIndex(
            ['mail_account_id', 'mail_message_id', 'object_uuid'],
            'email_links_unique_idx'
        );

        $output->info('Created openregister_email_links table');

        return true;
    }//end createEmailLinksTable()

    /**
     * Create the openregister_contact_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createContactLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_contact_links') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_contact_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('addressbook_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('contact_uri', Types::STRING, ['notnull' => true, 'length' => 512]);
        $table->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 64]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'idx_contact_object');
        $table->addIndex(['contact_uid'], 'idx_contact_uid');
        $table->addIndex(['role'], 'idx_contact_role');

        $output->info('Created openregister_contact_links table');

        return true;
    }//end createContactLinksTable()

    /**
     * Create the openregister_deck_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createDeckLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_deck_links') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_deck_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('stack_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('card_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('card_title', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'card_id'], 'idx_deck_object_card');
        $table->addIndex(['object_uuid'], 'idx_deck_object');
        $table->addIndex(['board_id'], 'idx_deck_board');

        $output->info('Created openregister_deck_links table');

        return true;
    }//end createDeckLinksTable()

    /**
     * Create the openregister_selection_lists table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createSelectionListsTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_selection_lists') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_selection_lists');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->addColumn('uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('category', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('retention_years', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('action', Types::STRING, [
            'notnull' => true,
            'length'  => 50,
            'default' => 'vernietigen',
        ]);
        $table->addColumn('description', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('schema_overrides', Types::TEXT, [
            'notnull' => false,
            'default' => null,
            'comment' => 'JSON map of schema UUID to override retention years',
        ]);
        $table->addColumn('organisation', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
            'default' => null,
        ]);
        $table->addColumn('created', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('updated', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'sl_uuid_idx');
        $table->addIndex(['category'], 'sl_category_idx');
        $table->addIndex(['organisation'], 'sl_organisation_idx');

        $output->info('Created openregister_selection_lists table');

        return true;
    }//end createSelectionListsTable()

    /**
     * Create the openregister_destruction_lists table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True if table was created
     */
    private function createDestructionListsTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        if ($schema->hasTable('openregister_destruction_lists') === true) {
            return false;
        }

        $table = $schema->createTable('openregister_destruction_lists');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->addColumn('uuid', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length'  => 255,
        ]);
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length'  => 50,
            'default' => 'pending_review',
        ]);
        $table->addColumn('objects', Types::TEXT, [
            'notnull' => false,
            'default' => null,
            'comment' => 'JSON array of object UUIDs',
        ]);
        $table->addColumn('approved_by', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
            'default' => null,
        ]);
        $table->addColumn('approved_at', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('notes', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('organisation', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
            'default' => null,
        ]);
        $table->addColumn('created', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('updated', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'dl_uuid_idx');
        $table->addIndex(['status'], 'dl_status_idx');
        $table->addIndex(['organisation'], 'dl_organisation_idx');

        $output->info('Created openregister_destruction_lists table');

        return true;
    }//end createDestructionListsTable()
}//end class
