<?php

/**
 * OpenRegister Schema appendOnly Column Migration
 *
 * Adds the `append_only` boolean column to the `openregister_schemas` table.
 * When set to true, objects of this schema permit INSERT operations but reject
 * UPDATE and DELETE with HTTP 405 + error code SCHEMA_APPEND_ONLY.
 *
 * Used by Scholiq for XapiStatement (cmi5+xAPI LRS audit) and Attestation
 * (compliance evidence) schemas.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
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
 * Migration: add `append_only` column to schemas table.
 *
 * - append_only = false (default) → backward-compatible; no behaviour change
 * - append_only = true → INSERT only; UPDATE/DELETE rejected with HTTP 405
 */
class Version1Date20260511100000 extends SimpleMigrationStep
{
    /**
     * Add append_only column to openregister_schemas table.
     *
     * @param IOutput                 $output        Migration output interface
     * @param Closure                 $schemaClosure Closure returning the DB schema wrapper
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema, or null when no change was needed
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schemas') === false) {
            $output->info('openregister_schemas table not found — skipping appendOnly migration');
            return null;
        }

        $table = $schema->getTable('openregister_schemas');

        if ($table->hasColumn('append_only') === true) {
            $output->info('append_only column already exists — skipping');
            return null;
        }

        $table->addColumn(
            'append_only',
            Types::BOOLEAN,
            [
                'notnull' => true,
                'default' => false,
                'comment' => 'When true, objects of this schema are INSERT-only; UPDATE and DELETE are rejected with HTTP 405',
            ]
        );

        $output->info('Added append_only column to openregister_schemas (default false)');

        return $schema;
    }//end changeSchema()
}//end class
