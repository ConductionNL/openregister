<?php

/**
 * Tier-2 xWiki (external, OpenConnector-routed) integration migration.
 *
 * Ensures the `openregister_xwiki_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - page_reference (string 512, not null) — canonical xWiki page ref
 *  - space (string 255, nullable) — cached owning space
 *  - title (string 255, not null) — cached page title
 *  - url (string 512, nullable) — cached deep link to the page
 *  - cached_at (datetime, nullable) — when the cached metadata was last refreshed
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the other Tier-2 link tables; the wrapping
 * `XwikiLinkService` tracks WHICH remote xWiki pages are bound to an OR
 * object while the pages themselves stay in the remote xWiki instance,
 * reached via OpenConnector (AD-4 / ADR-019). The cached columns let the
 * sidebar tab + picker hydrate without a per-row roundtrip when the
 * upstream is slow or unconfigured.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * Tier-2 xwiki-links table — create-or-extend.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260525230000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_xwiki_links') === false) {
            $this->createXwikiLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_xwiki_links') === true
            && $this->extendXwikiLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_xwiki_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createXwikiLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_xwiki_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('page_reference', Types::STRING, ['notnull' => true, 'length' => 512]);
        $table->addColumn('space', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('cached_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'page_reference'], 'idx_xwiki_object_page');
        $table->addIndex(['object_uuid'], 'idx_xwiki_object');
        $table->addIndex(['register_id'], 'idx_xwiki_register');
        $table->addIndex(['schema_id'], 'idx_xwiki_schema');

        $output->info('Created openregister_xwiki_links table (Tier-2 schema)');
    }//end createXwikiLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing xwiki_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendXwikiLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_xwiki_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_xwiki_schema');
            $output->info('Added schema_id column to openregister_xwiki_links');
            $changed = true;
        }

        if ($table->hasColumn('space') === false) {
            $table->addColumn('space', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added space column to openregister_xwiki_links');
            $changed = true;
        }

        if ($table->hasColumn('url') === false) {
            $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
            $output->info('Added url column to openregister_xwiki_links');
            $changed = true;
        }

        if ($table->hasColumn('cached_at') === false) {
            $table->addColumn('cached_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added cached_at column to openregister_xwiki_links');
            $changed = true;
        }

        return $changed;
    }//end extendXwikiLinksTable()
}//end class
