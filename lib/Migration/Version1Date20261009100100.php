<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drop the separate "recently opened" table.
 *
 * `openregister_object_views` held one row per (user, object) with the last
 * time that user opened it. The audit trail already records every audited
 * read with the reader and the moment, so the table was a second copy of the
 * same fact with its own throttle, cap and cleanup. "Recently opened" is now
 * read from the audit trail (`read-history-on-audit-trail`) and nothing
 * writes or reads this table any more.
 *
 * The rows are not migrated. Every one of them recorded a read the audit
 * trail also holds while audit trails were on, so the history survives the
 * drop; while audit trails are off the lens is empty by decision.
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
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops `openregister_object_views` when it exists.
 *
 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
 */
class Version1Date20261009100100 extends SimpleMigrationStep {

	/**
	 * The table this migration drops.
	 */
	private const TABLE = 'openregister_object_views';

	/**
	 * Drop the view table when it is there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/read-history-on-audit-trail/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$schema->dropTable(self::TABLE);
		$output->info('Dropped '.self::TABLE.': recently opened is read from the audit trail');

		return $schema;
	}//end changeSchema()
}//end class
