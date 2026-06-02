<?php

/**
 * Tier-2 form-link migration: create `openregister_form_links` table.
 *
 * Backs the Tier-2 forms integration leaf — promotes the FormsProvider
 * from the marker-only LIKE lookup against `forms_v2_forms.title` to a
 * first-class link table so:
 *
 *   * Form-level and submission-level links can both live in one
 *     table, distinguished by `submission_id` (nullable);
 *   * `linked_by` / `linked_at` audit trail is captured outside the
 *     upstream Forms tables (which OR doesn't own);
 *   * Form metadata cached at link time (`form_hash`, `title`,
 *     `status`, `expires_at`) is preserved even if NC Forms goes
 *     away or the form is archived (graceful-degradation per AD-23).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Create the openregister_form_links table for Tier-2 forms integration.
 *
 * @package OCA\OpenRegister\Migration
 */
class Version1Date20260524130000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * Idempotent: only creates the table if absent.
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
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_form_links') === true) {
            return null;
        }

        $table = $schema->createTable(tableName: 'openregister_form_links');

        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                'autoincrement' => true,
                'notnull'       => true,
                'unsigned'      => true,
            ]
        );
        $table->addColumn(
            'object_uuid',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 36,
            ]
        );
        $table->addColumn(
            'register_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'schema_id',
            Types::BIGINT,
            [
                'notnull'  => false,
                'unsigned' => true,
                'default'  => null,
            ]
        );
        $table->addColumn(
            'form_id',
            Types::BIGINT,
            [
                'notnull'  => true,
                'unsigned' => true,
            ]
        );
        $table->addColumn(
            'form_hash',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]
        );
        // Submission id is nullable — a row with submission_id NULL is a
        // form-level link; a row with a submission_id is a per-submission
        // link. Composite unique below allows both shapes for the same
        // (object, form) pair.
        $table->addColumn(
            'submission_id',
            Types::BIGINT,
            [
                'notnull'  => false,
                'unsigned' => true,
                'default'  => null,
            ]
        );
        $table->addColumn(
            'title',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 255,
                'default' => null,
            ]
        );
        $table->addColumn(
            'status',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 32,
                'default' => null,
            ]
        );
        $table->addColumn(
            'expires_at',
            Types::DATETIME,
            [
                'notnull' => false,
                'default' => null,
            ]
        );
        $table->addColumn(
            'linked_by',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'linked_at',
            Types::DATETIME,
            [
                'notnull' => true,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['object_uuid'], 'or_form_links_object_idx');
        $table->addIndex(['form_id'], 'or_form_links_form_idx');
        $table->addIndex(['register_id'], 'or_form_links_register_idx');
        // Composite unique key: (object, form, submission). Allows one
        // form-level link (submission_id NULL) plus N per-submission
        // links for the same form attached to the same object.
        $table->addUniqueIndex(
            ['object_uuid', 'form_id', 'submission_id'],
            'or_form_links_unique_idx'
        );

        $output->info(message: 'Created openregister_form_links table');

        return $schema;

    }//end changeSchema()
}//end class
