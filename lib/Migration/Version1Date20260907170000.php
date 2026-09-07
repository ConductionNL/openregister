<?php

/**
 * The objects a run declares it is working with.
 *
 * 🔴 THE SINGLE `subject_uuid` STAYS. It is what a trigger fired on and what
 * 28 call sites read; replacing it would be a migration of every one of them to
 * buy a name. `subjects` is an ADDITIONAL, author-facing fact: the set of
 * objects a flow says it is working with, each under a role its own author
 * chose.
 *
 * Nullable with no default, because a run that has declared nothing has
 * declared nothing — and a NULL that means "none recorded" is honest where an
 * empty object would be a claim the author never made.
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
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `subjects` to flow runs.
 */
class Version1Date20260907170000 extends SimpleMigrationStep {

	private const RUNS = 'openregister_flow_runs';

	/**
	 * Add the column.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(self::RUNS) === false) {
			return null;
		}

		$runs = $schema->getTable(self::RUNS);
		if ($runs->hasColumn('subjects') === true) {
			return null;
		}

		$runs->addColumn('subjects', Types::JSON, ['notnull' => false, 'default' => null]);
		$output->info(message: 'Added the declared-subject set to flow runs; subject_uuid is unchanged.');

		return $schema;
	}//end changeSchema()
}//end class
