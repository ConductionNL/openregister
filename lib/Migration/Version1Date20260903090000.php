<?php

/**
 * Adds the `_quality` metadata column to every per-schema object table.
 *
 * A per-object quality score is an ASSESSMENT of the object's data, not a
 * fact about the thing the object describes. Until now the scorer had no
 * place to put it other than the object body, which forced every schema
 * wanting a score to declare `qualityScore` and `qualityStatus` as ordinary
 * properties. Three things follow from that, and all three are wrong:
 *
 * - The properties appear on every form the schema drives. A case handler
 *   filing a case was shown a "Quality score" number field to fill in, for a
 *   value the platform overwrites on save.
 * - Removing the declaration silently DELETES the values, because the store
 *   strips what the schema does not declare. So the mistake could not be
 *   undone without data loss.
 * - Two schemas scoring the same way had to agree on property names by
 *   convention, with nothing to enforce it.
 *
 * `_quality` is the same kind of column as `_validation` and `_retention`:
 * platform-owned metadata, surfaced in the `@self` envelope, never part of
 * the object's own data.
 *
 * This step is additive and idempotent. It adds the column where it is
 * missing and leaves it alone where it is present, so re-running it is a
 * no-op. It does NOT remove any `qualityScore` property or its stored value:
 * a schema that declares one keeps it, and keeps being written, until its
 * owning app drops the declaration deliberately.
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
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gives every per-schema object table a `_quality` metadata column.
 */
class Version1Date20260903090000 extends SimpleMigrationStep {

	/**
	 * The metadata column this step adds.
	 *
	 * @var string
	 */
	private const QUALITY_COLUMN = '_quality';

	/**
	 * A column that every per-schema object table carries.
	 *
	 * Used to tell a per-schema object table from any other table that
	 * happens to share the prefix. `_uuid` is written by every object write
	 * path, so a table without it is not one this step should touch.
	 *
	 * @var string
	 */
	private const SIGNATURE_COLUMN = '_uuid';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Reads the configured database table prefix.
	 */
	public function __construct(private readonly IConfig $config) {

	}//end __construct()

	/**
	 * Add `_quality` to every per-schema object table that lacks it.
	 *
	 * @param IOutput  $output        Migration output.
	 * @param Closure  $schemaClosure Returns the live ISchemaWrapper.
	 * @param array    $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
		$changed = 0;

		foreach ($schema->getTables() as $table) {
			if (str_starts_with($table->getName(), $prefix.'openregister_table_') === false) {
				continue;
			}

			// A table sharing the prefix but missing the signature column is
			// not an object table. Adding a column to it would be a guess.
			if ($table->hasColumn(self::SIGNATURE_COLUMN) === false) {
				continue;
			}

			if ($table->hasColumn(self::QUALITY_COLUMN) === true) {
				continue;
			}

			$table->addColumn(self::QUALITY_COLUMN, Types::JSON, ['notnull' => false]);
			$changed++;
		}//end foreach

		if ($changed === 0) {
			$output->info('quality metadata: every per-schema object table already has _quality, nothing to do');
			return null;
		}

		$output->info(sprintf('quality metadata: added _quality to %d per-schema object table(s)', $changed));

		return $schema;

	}//end changeSchema()

}//end class
