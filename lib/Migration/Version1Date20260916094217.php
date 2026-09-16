<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared master data: who else may read this register or schema.
 *
 * A gemeenschappelijke regeling is several legal entities sharing one back
 * office. Each keeps its own records, and they share the code lists, the case
 * types and the parties. The tenancy model separates them well and shared
 * nothing, so every entity kept its own copy of the same code list and one of
 * those copies was wrong within a year.
 *
 * `shared_with` is the declaration that ends that. The row's existing
 * `organisation` column is the HOLDER; this column lists the organisations that
 * may READ it. Nothing is copied: the share is a rule the query applies, which
 * is what keeps the organisation UUID the only tenant key (ADR-002).
 *
 * One nullable JSON column per table, add-only and rerun-safe. An instance that
 * declares nothing has NULL everywhere and behaves exactly as it did.
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
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `shared_with` declaration to registers and schemas.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */
class Version1Date20260916094217 extends SimpleMigrationStep {

	/**
	 * The tables that can carry a shared master data declaration.
	 *
	 * A register and a schema are the two grains the corpus asks for: a code
	 * list is usually a register, a case type and a party are usually a schema.
	 *
	 * @var array<int, string>
	 */
	private const TABLES = [
		'openregister_registers',
		'openregister_schemas',
	];

	/**
	 * The column holding the consumer organisation UUIDs.
	 */
	private const COLUMN = 'shared_with';

	/**
	 * Add the column to both tables when it is absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$added = 0;

		foreach (self::TABLES as $tableName) {
			if ($schema->hasTable($tableName) === false) {
				$output->warning('shared master data: table ' . $tableName . ' is absent, skipping');
				continue;
			}

			$table = $schema->getTable($tableName);
			if ($table->hasColumn(self::COLUMN) === true) {
				continue;
			}

			// TEXT rather than a JSON column type: the entity layer already
			// encodes and decodes every `json` field itself (Register and
			// Schema both do), and a portable TEXT keeps MariaDB, MySQL and
			// PostgreSQL on one definition.
			//
			// Nullable, and that is the backwards-compatibility guarantee: an
			// existing row declares nothing, reads as nothing, and the query
			// layer widens by nothing.
			$table->addColumn(
				self::COLUMN,
				Types::TEXT,
				[
					'notnull' => false,
					'default' => null,
					'comment' => 'JSON list of organisation UUIDs that may read this row as shared master data',
				]
			);
			$added++;
		}//end foreach

		if ($added === 0) {
			$output->info('shared master data: the shared_with column is already present, nothing to do');
			return null;
		}

		$output->info('shared master data: added shared_with to ' . $added . ' table(s)');

		return $schema;

	}//end changeSchema()
}//end class
