<?php

/**
 * Approval-chains declarative wiring migration.
 *
 * Adds a single additive, nullable `requester_id` column to the already-shipped
 * `openregister_approval_steps` table (see `Version1Date20260325000003`). The
 * declarative gate (`x-openregister-approval-chains`) stamps this column with the
 * uid of the user whose attempted transition provisioned the step, so
 * `ApprovalService` can enforce approver-≠-requester separation of duties.
 * Existing steps created through the pure `POST /api/approval-chains` CRUD flow
 * keep `requester_id = NULL` — separation-of-duties enforcement only engages when
 * a schema declares it (see `ApprovalService::resolveSeparationOfDuties()`), so a
 * null value is always safe.
 *
 * Deliberately a separate migration file, not an edit to the already-shipped
 * `Version1Date20260325000003` — a shipped migration is never edited after merge.
 * Idempotent: adds the column only when absent, no-ops entirely if the base table
 * doesn't exist yet.
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
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `requester_id` to `openregister_approval_steps`.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class Version1Date20260714010000 extends SimpleMigrationStep
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
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_approval_steps') === false) {
            return null;
        }

        $table = $schema->getTable(tableName: 'openregister_approval_steps');

        if ($table->hasColumn(name: 'requester_id') === true) {
            return null;
        }

        $table->addColumn(
            name: 'requester_id',
            typeName: Types::STRING,
            options: [
                'notnull' => false,
                'length'  => 255,
            ]
        );

        $output->info('Added requester_id to openregister_approval_steps');

        return $schema;
    }//end changeSchema()
}//end class
