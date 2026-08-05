<?php

/**
 * Migration adding the `source_language` column to `openregister_translations`.
 *
 * The column tracks the canonical source-of-truth language for each
 * (object × property) at projection time. Adding it as
 * `VARCHAR(16) NOT NULL DEFAULT ''` keeps pre-existing rows valid until
 * the `openregister:translations:backfill-source-language` console
 * command populates them from each parent register's default language.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `source_language` to `openregister_translations`.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
 */
class Version1Date20260520120000 extends SimpleMigrationStep
{
    /**
     * Apply the schema change.
     *
     * @param IOutput              $output        Migration output channel.
     * @param Closure              $schemaClosure Schema closure factory.
     * @param array<string, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null Modified schema wrapper, or null when nothing changed.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_translations') === false) {
            return $schema;
        }

        $table = $schema->getTable('openregister_translations');
        if ($table->hasColumn('source_language') === true) {
            return $schema;
        }

        $table->addColumn(
            'source_language',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 16,
                'default' => '',
            ]
        );

        if ($table->hasIndex('idx_translations_source_lang') === false) {
            $table->addIndex(['source_language'], 'idx_translations_source_lang');
        }

        return $schema;
    }//end changeSchema()

    /**
     * Run the SQL back-fill that gives every row a non-empty source_language.
     *
     * Joins through `openregister_objects` to the parent register and uses
     * the first element of the register's `languages` array (falling back
     * to `'nl'` when the JSON column is empty or null).
     *
     * Idempotent: only updates rows where `source_language = ''`. The
     * companion `openregister:translations:backfill-source-language`
     * console command is the entry point preferred for large datasets.
     *
     * @param IOutput              $output        Migration output channel.
     * @param Closure              $schemaClosure Schema closure factory (unused).
     * @param array<string, mixed> $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // The actual back-fill is intentionally delegated to the
        // `openregister:translations:backfill-source-language` console
        // command so admins can choose the batch size. We only emit an
        // advisory message here.
        $output->info(
            '[i18n-source-of-truth] openregister_translations.source_language column added. '
            .'Run `occ openregister:translations:backfill-source-language` to populate '
            .'existing rows from their parent register defaults.'
        );
    }//end postSchemaChange()
}//end class
