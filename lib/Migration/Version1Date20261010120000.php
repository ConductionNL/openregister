<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Adds `imported` to `openregister_case_item_audit`: true on history brought
 * over from another engine by `CasePlanEnsurer`, whose `created` is the moment
 * it originally happened. Additive; existing rows read as not imported, which
 * is true.
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
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the imported flag to the plan-item audit.
 *
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
class Version1Date20261010120000 extends SimpleMigrationStep {

	/**
	 * The audit table.
	 */
	public const AUDIT_TABLE = 'openregister_case_item_audit';

	/**
	 * Add `imported` when the table is there and lacks it.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::AUDIT_TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::AUDIT_TABLE);
		if ($table->hasColumn('imported') === true) {
			return $schema;
		}

		// Nullable on purpose: Nextcloud refuses NOT NULL booleans on Oracle.
		$table->addColumn('imported', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

		return $schema;

	}//end changeSchema()
}//end class
