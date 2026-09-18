<?php

/**
 * The consumer whose token made a write, on the audit trail.
 *
 * Adds `consumer` to openregister_audit_trails as the INDEXED projection of
 * the registered consumer behind the calling token. The sealed copy, with the
 * token reference and its owner beside it, lives in `result_summary`, which is
 * inside the canonical JSON; this column is deliberately outside it, because
 * any key added to AuditTrail::jsonSerialize() changes the canonical form of
 * every row ever written and invalidates the whole chain (ADR-003 Rule 4).
 *
 * The index is what makes "everything this koppeling wrote last month" a
 * lookup rather than a scan of the largest table this app has.
 *
 * Idempotent: the column and the index are created only when absent.
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
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the audit trail's consumer column.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class Version1Date20260916225200 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_audit_trails') === false) {
			// Hand the schema back, never null: a null return drops the shared
			// snapshot and makes the next migration re-introspect the whole
			// database. This branch predates the guard that says so.
			return $schema;
		}

		$audit = $schema->getTable('openregister_audit_trails');

		if ($audit->hasColumn('consumer') === false) {
			$audit->addColumn('consumer', Types::STRING, ['notnull' => false, 'length' => 255]);
		}

		if ($audit->hasIndex('or_audit_consumer_idx') === false) {
			$audit->addIndex(['consumer'], 'or_audit_consumer_idx');
		}

		return $schema;
	}//end changeSchema()
}//end class
