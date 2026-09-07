<?php

/**
 * Semantic versions on flows and their published versions.
 *
 * 🔴 THE ORDINAL IS NOT TOUCHED. `version` stays an integer, stays unique with
 * `flow_uuid`, and stays what a RUN PINS for its whole life — including runs
 * suspended for weeks on a human task. Semver is an ADDITIONAL, author-facing
 * fact. Replacing the ordinal would migrate two unique indexes, every run
 * row's pin and 28 call sites to buy a label, and would put the run pin at the
 * mercy of a derivation: a bug would then not mislabel a version, it would
 * repoint a run.
 *
 * Both columns are nullable with no default. A draft has no semantic version
 * because it has not been compared with anything yet, and a NULL that means
 * "not derived" is honest where `0.0.0` would be a claim.
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
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `semver` to flows and `semver`/`semver_source` to flow versions.
 */
class Version1Date20260907100000 extends SimpleMigrationStep {

	private const FLOWS = 'openregister_flows';

	private const VERSIONS = 'openregister_flow_versions';

	/**
	 * Add the columns.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(self::VERSIONS) === true) {
			$versions = $schema->getTable(self::VERSIONS);

			if ($versions->hasColumn('semver') === false) {
				$versions->addColumn('semver', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
				$changed = true;
			}

			// WHERE IT CAME FROM, beside the value. The back-fill cannot know
			// whether a historical publish was breaking, so it must be able to
			// say that it guessed the ordering rather than derived it.
			if ($versions->hasColumn('semver_source') === false) {
				$versions->addColumn('semver_source', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
				$changed = true;
			}
		}

		if ($schema->hasTable(self::FLOWS) === true) {
			$flows = $schema->getTable(self::FLOWS);

			if ($flows->hasColumn('semver') === false) {
				$flows->addColumn('semver', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => null]);
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		$output->info(message: 'Added semantic version columns to flows and flow versions; the ordinal is unchanged.');

		return $schema;
	}//end changeSchema()
}//end class
