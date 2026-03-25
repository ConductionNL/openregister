<?php

/**
 * Database migration for email links table.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the openregister_email_links table.
 *
 * @psalm-suppress UnusedClass
 */
class Version1Date20260325120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput        $output The migration output.
     * @param Closure        $schemaClosure Schema closure.
     * @param array<string, mixed> $options Migration options.
     *
     * @return ISchemaWrapper|null The modified schema or null.
     */
    public function changeSchema(
        IOutput $output,
        Closure $schemaClosure,
        array $options
    ): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_email_links') === true) {
            return null;
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

        return $schema;
    }//end changeSchema()
}//end class
