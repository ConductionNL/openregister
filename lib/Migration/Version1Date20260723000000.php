<?php

/**
 * Widen the register/schema slug-uniqueness key from (organisation, slug)
 * to (organisation, application, slug) so each app can own its own schema
 * (or register) with a shared, generic slug.
 *
 * The old `schemas_organisation_slug_unique` / `registers_organisation_slug_unique`
 * indexes (added Version1Date20250829120000) made a slug unique per ORGANISATION,
 * not per app. On a shared OpenRegister instance where ~20 apps live in one
 * database that turned a generic slug shipped by app B — `conversation`, `order`,
 * `task`, `notification`, `contact`, … — into a hard collision with app A's
 * schema of the same slug: the DB physically refused app B its own schema, so the
 * importer silently BOUND app B to (or OVERWROTE) app A's schema. App B's objects
 * then landed in the wrong schema/table and A's schema could be corrupted. The
 * collision was invisible in single-app tests (a clean instance has no competitor
 * for the slug) and environment-dependent (first-app-wins).
 *
 * Adding `application` to the unique key lets every app own a schema/register with
 * the same slug, while resolution is scoped by app (import) and by register
 * (runtime) in the accompanying code change so a slug still resolves
 * deterministically. The new key is STRICTLY MORE PERMISSIVE than the old one —
 * every row unique under (organisation, slug) is unique under
 * (organisation, application, slug) — so no data dedup is required and the change
 * cannot fail on existing rows. Idempotent: swaps each index only when the old one
 * is present, the new one is absent, and the `application` column exists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen slug-uniqueness to (organisation, application, slug) for registers and schemas.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class Version1Date20260723000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Registers: (organisation, slug) -> (organisation, application, slug).
        $this->widenSlugUniqueIndex(
            schema: $schema,
            output: $output,
            tableName: 'openregister_registers',
            oldIndexName: 'registers_organisation_slug_unique',
            newIndexName: 'registers_org_app_slug_unique'
        );

        // Schemas: (organisation, slug) -> (organisation, application, slug).
        $this->widenSlugUniqueIndex(
            schema: $schema,
            output: $output,
            tableName: 'openregister_schemas',
            oldIndexName: 'schemas_organisation_slug_unique',
            newIndexName: 'schemas_org_app_slug_unique'
        );

        return $schema;
    }//end changeSchema()

    /**
     * Swap a (organisation, slug) unique index for a (organisation, application, slug) one.
     *
     * Idempotent and self-guarding: does nothing unless the table and the three
     * columns exist. Drops the old index only when present, and adds the new one
     * only when absent, so re-runs and partially-applied states converge safely.
     *
     * @param ISchemaWrapper $schema       The schema wrapper.
     * @param IOutput        $output       Migration output.
     * @param string         $tableName    The table to alter.
     * @param string         $oldIndexName The (organisation, slug) index to drop.
     * @param string         $newIndexName The (organisation, application, slug) index to add.
     *
     * @return void
     */
    private function widenSlugUniqueIndex(
        ISchemaWrapper $schema,
        IOutput $output,
        string $tableName,
        string $oldIndexName,
        string $newIndexName
    ): void {
        if ($schema->hasTable($tableName) === false) {
            return;
        }

        $table = $schema->getTable($tableName);

        // Need all three columns to build the wider key.
        if ($table->hasColumn('organisation') === false
            || $table->hasColumn('application') === false
            || $table->hasColumn('slug') === false
        ) {
            $output->warning(
                "Cannot widen slug-uniqueness on {$tableName}: organisation, application or slug column missing"
            );
            return;
        }

        // Drop the narrow (organisation, slug) index if it is still present.
        if ($table->hasIndex($oldIndexName) === true) {
            $table->dropIndex($oldIndexName);
            $output->info("Dropped narrow unique index {$oldIndexName} on {$tableName}");
        }

        // Add the wider (organisation, application, slug) index if not already there.
        if ($table->hasIndex($newIndexName) === false) {
            $table->addUniqueIndex(['organisation', 'application', 'slug'], $newIndexName);
            $output->info("Added unique index {$newIndexName} on (organisation, application, slug) for {$tableName}");
        }
    }//end widenSlugUniqueIndex()
}//end class
