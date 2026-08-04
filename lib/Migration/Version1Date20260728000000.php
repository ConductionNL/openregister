<?php

/**
 * Add `user_quota` and `group_quota` columns to `openregister_applications`.
 *
 * The applications API has always exposed quota as one nested object with five
 * allocations (`storage`, `bandwidth`, `requests`, `users`, `groups`), but only
 * the first three had columns — `Application::getQuotaData()` hardcoded the
 * other two to null with a "to be set via admin configuration" note. A client
 * that POSTed a complete quota object therefore got `users`/`groups` back as
 * null with no error, indistinguishable from "unlimited". These two columns
 * make the persisted set match the published shape.
 *
 * Both are nullable with no default, so NULL keeps its established meaning
 * across every allocation: unlimited. Strictly additive — adding nullable
 * columns cannot fail on existing rows, needs no backfill, and leaves every
 * existing application at "unlimited users, unlimited groups", which is what
 * the API already reported for them. Idempotent: adds each column only when
 * it is still absent.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `user_quota` / `group_quota` allocations to applications.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec exclude Additive columns backing two quota allocations the applications
 *     API already published as null. No behavioural contract of its own —
 *     enforcement belongs to the tenant-quotas capability.
 */
class Version1Date20260728000000 extends SimpleMigrationStep
{
    /**
     * Table receiving the two new allocations.
     *
     * @var string
     */
    private const APPLICATIONS_TABLE = 'openregister_applications';

    /**
     * New columns, keyed by column name, valued by their comment.
     *
     * @var array<string, string>
     */
    private const QUOTA_COLUMNS = [
        'user_quota'  => 'Maximum number of users (NULL = unlimited)',
        'group_quota' => 'Maximum number of groups (NULL = unlimited)',
    ];

    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec exclude See class-level note — additive nullable quota columns only.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(self::APPLICATIONS_TABLE) === false) {
            return $schema;
        }

        $table = $schema->getTable(self::APPLICATIONS_TABLE);

        foreach (self::QUOTA_COLUMNS as $column => $comment) {
            if ($table->hasColumn($column) === true) {
                $output->info($column.' already present on '.self::APPLICATIONS_TABLE.'; nothing to do.');
                continue;
            }

            $table->addColumn(
                $column,
                Types::INTEGER,
                [
                    'notnull' => false,
                    'comment' => $comment,
                ]
            );

            $output->info('Added '.$column.' to '.self::APPLICATIONS_TABLE.' (NULL = unlimited).');
        }

        return $schema;
    }//end changeSchema()
}//end class
