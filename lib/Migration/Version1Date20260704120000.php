<?php

/**
 * Database migration to add the missing `owner` column to the conversations table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The `Conversation` entity (lib/Db/Conversation.php) declares an `$owner`
 * property with `getOwner()`/`setOwner()`, and `ChatService` sets it when it
 * creates a conversation, but no migration ever added the column to the
 * `openregister_conversations` table. As a result every agent run that creates
 * a conversation fails with:
 *   SQLSTATE[42703]: Undefined column: 7 ERROR: column "owner" of relation
 *   "oc_openregister_conversations" does not exist
 * This migration reconciles the table with the entity.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Adds the missing nullable `owner` column to the conversations table.
 *
 * The migration is idempotent: the column is only added when absent, so
 * re-running it on an already-migrated database (or one where the column was
 * added manually) is a no-op.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260704120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema, or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_conversations') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_conversations');

        if ($table->hasColumn('owner') === true) {
            return null;
        }

        // Nullable string matching Conversation::$owner (?string). A Nextcloud
        // user id is at most 64 characters.
        $table->addColumn(
            'owner',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]
        );
        $output->info('   ✓ Added owner column to openregister_conversations table');

        return $schema;
    }//end changeSchema()
}//end class
