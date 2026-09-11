<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Record when an organisation entered the retained lifecycle state.
 *
 * `retained` is the terminal state for an organisation whose access has ended
 * and whose data must still be kept, as a tenant with a legal retention duty
 * needs after its contract ends. Its retention period runs from the moment it
 * entered that state, and nothing on the row recorded that moment: `updated`
 * moves on every edit, and `deprovisionedAt` is the clock TenantPurgeJob
 * measures its deletion window from, so writing it here would put a retained
 * organisation on the deletion path.
 *
 * Nullable with NO default and no backfill. No organisation is retained before
 * this migration runs, and a guessed timestamp would read as a real one.
 *
 * @category  Migration
 * @package   OCA\OpenRegister\Migration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `retained_at` column to `openregister_organisations`.
 */
final class Version1Date20260911060000 extends SimpleMigrationStep {

	/**
	 * The table this migration widens.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_organisations';

	/**
	 * The column this migration adds.
	 *
	 * @var string
	 */
	private const COLUMN = 'retained_at';

	/**
	 * Add the column when it is absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			$output->warning(message: 'openregister_organisations is absent; skipping the retained_at column');

			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN) === true) {
			return null;
		}

		$table->addColumn(
			self::COLUMN,
			Types::DATETIME,
			[
				'notnull' => false,
				'comment' => 'When the organisation entered the retained state: access ended, data kept. '
					. 'The start of its retention period. TenantPurgeJob never reads it.',
			]
		);

		$output->info(message: 'openregister_organisations: added ' . self::COLUMN);

		return $schema;
	}//end changeSchema()

}//end class
