<?php

/**
 * A home on the row for the one piece of a flow that explains it.
 *
 * A flow authored as a definition file carries its rationale in a top-level
 * `$comment` — on hydra's lock reaper, 90 lines recording four defects and the
 * reasoning that prevents each recurring. `openregister_flows` had no column
 * for it, so importing such a file and regenerating it FROM the database
 * returned a flow without that text, and nothing reported the loss.
 *
 * The workaround was to regenerate by MERGING file and database rather than
 * exporting, which left the file as the only home for the rationale — meaning
 * a flow edited through the UI could not carry one at all, and two authors
 * editing the same flow by different routes silently disagreed about why it
 * was shaped that way.
 *
 * TEXT, not STRING: the existing bodies already exceed 6,000 characters, and a
 * length-capped column would truncate on write rather than refuse it.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flows.comment`.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class Version1Date20260812100000 extends SimpleMigrationStep {
	/**
	 * Add the comment column.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when unchanged.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_flows') === false) {
			return null;
		}

		$table = $schema->getTable('openregister_flows');

		if ($table->hasColumn('comment') === true) {
			return null;
		}

		$table->addColumn('comment', Types::TEXT, ['notnull' => false, 'default' => null]);

		return $schema;
	}//end changeSchema()
}//end class
