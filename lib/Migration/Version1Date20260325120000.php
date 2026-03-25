<?php

/**
 * Database migration to create entity relation link tables.
 *
 * Creates openregister_email_links, openregister_contact_links, and
 * openregister_deck_links tables for the Nextcloud Entity Relations feature.
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
 * Creates the entity relation link tables for email, contact, and deck integrations.
 *
 * Calendar events use CalDAV properties only (same as tasks) and do not need a table.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Migration requires defining multiple tables
 */
class Version1Date20260325120000 extends SimpleMigrationStep
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
        $schema  = $schemaClosure();
        $changed = false;

        // Email links table.
        if ($schema->hasTable('openregister_email_links') === false) {
            $table = $schema->createTable('openregister_email_links');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('mail_account_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('mail_message_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('mail_message_uid', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 512]);
            $table->addColumn('sender', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('date', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['object_uuid', 'mail_message_id'], 'idx_email_object_msg');
            $table->addIndex(['object_uuid'], 'idx_email_object_uuid');
            $table->addIndex(['sender'], 'idx_email_sender');
            $output->info('Created table openregister_email_links');
            $changed = true;
        }

        // Contact links table.
        if ($schema->hasTable('openregister_contact_links') === false) {
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
            $output->info('Created table openregister_contact_links');
            $changed = true;
        }

        // Deck links table.
        if ($schema->hasTable('openregister_deck_links') === false) {
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
            $output->info('Created table openregister_deck_links');
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
