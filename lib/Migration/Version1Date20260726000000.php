<?php

/**
 * Drop the (organisation, application, slug) unique index on
 * `openregister_schemas`, replacing it with no DB-level index at all.
 *
 * PER-REGISTER SLUG-UNIQUENESS (openspec/changes/per-register-schema-slug-uniqueness).
 * `schemas_org_app_slug_unique` (added by Version1Date20260723000000) made a
 * schema slug unique per (organisation, application) — strictly better than the
 * (organisation, slug) key it replaced, but still too coarse: schemas are
 * MANY-TO-MANY with registers (769 schema rows are referenced by more than one
 * register today, some by up to 6), and a single `application` column cannot
 * express "unique within one register's schema set" for an app that owns
 * multiple registers, nor for import paths where the application id is absent
 * at resolution time. That gap is exactly how an incident occurred: an
 * importing register's schema resolved to a same-slug schema owned by an
 * entirely different app/register instead of getting its own row.
 *
 * The invariant this migration removes at the DB layer is enforced instead at
 * the SERVICE layer (`ImportHandler::importSchema()` /
 * `computeRegisterScopedSchemaIds()`), because "unique within a register's
 * set" cannot be expressed as a single-table unique index — register
 * membership is a separate, many-to-many relationship (a `Register`'s
 * `schemas` id list), not a column on `openregister_schemas`. No replacement
 * index is added.
 *
 * Strictly permissive: dropping a unique index can never fail on existing
 * data (it only removes a constraint) — no backfill, no data migration, no
 * risk to the 769 shared-schema rows or any other existing content.
 * Idempotent: drops the index only when it is still present.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/per-register-schema-slug-uniqueness/specs/data-import-export/spec.md#requirement-no-database-level-uniqueness-constraint-scopes-schema-slugs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops `schemas_org_app_slug_unique` on `openregister_schemas`; adds no replacement index.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/per-register-schema-slug-uniqueness/specs/data-import-export/spec.md#requirement-no-database-level-uniqueness-constraint-scopes-schema-slugs
 */
class Version1Date20260726000000 extends SimpleMigrationStep
{
    /**
     * The (organisation, application, slug) unique index being retired.
     *
     * @var string
     */
    private const SCHEMAS_SLUG_UNIQUE_INDEX = 'schemas_org_app_slug_unique';

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/changes/per-register-schema-slug-uniqueness/specs/data-import-export/spec.md#requirement-no-database-level-uniqueness-constraint-scopes-schema-slugs
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schemas') === false) {
            return $schema;
        }

        $table = $schema->getTable('openregister_schemas');

        if ($table->hasIndex(self::SCHEMAS_SLUG_UNIQUE_INDEX) === true) {
            $table->dropIndex(self::SCHEMAS_SLUG_UNIQUE_INDEX);
            $output->info(
                'Dropped '.self::SCHEMAS_SLUG_UNIQUE_INDEX.' on openregister_schemas: '.
                'schema slug uniqueness is now enforced per-register at the service layer '.
                '(openspec/changes/per-register-schema-slug-uniqueness), not by a DB index.'
            );
        } else {
            $output->info(self::SCHEMAS_SLUG_UNIQUE_INDEX.' already absent on openregister_schemas; nothing to do.');
        }

        return $schema;
    }//end changeSchema()
}//end class
