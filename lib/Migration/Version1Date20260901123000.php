<?php

/**
 * Index `openregister_flow_runs` on (organisation, subject_uuid, status, id)
 * for the two subject-scoped run reads.
 *
 * FLOW-RUNS-SUBJECT-SCOPE (openspec/changes/flow-runs-subject-scope). A case
 * detail page asks two questions of the run table: "what is running on THIS
 * object" (`organisation = ? AND subject_uuid = ? AND status IN (active set)`)
 * and "what already ran on it" (same, over the terminal set), both newest
 * first and bounded. The existing `or_flowrun_org_status_idx` leads with
 * `(organisation, status, id)`, which serves the org-wide widget but makes a
 * subject read scan every live or every finished run of the tenant and filter
 * on `subject_uuid` afterwards. On a busy tenant the finished set is the whole
 * history, so that is a walk, not a lookup.
 *
 * `organisation` leads because it is the equality that eliminates the most
 * rows and is the predicate that is never optional, `subject_uuid` follows as
 * the second equality (one case out of thousands), `status` third for the IN
 * over the status set, and `id` last so the newest-first ordering and the
 * LIMIT come off the index rather than a sort.
 *
 * Strictly additive: an index add cannot fail on existing data and changes no
 * rows. Idempotent: added only when absent.
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
 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `or_flowrun_org_subject_idx` on `openregister_flow_runs`.
 *
 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
 */
class Version1Date20260901123000 extends SimpleMigrationStep {
	/**
	 * The index that serves the subject-scoped live and completed reads.
	 *
	 * @var string
	 */
	private const SUBJECT_RUNS_INDEX = 'or_flowrun_org_subject_idx';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_flow_runs') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_flow_runs');

		if ($table->hasIndex(self::SUBJECT_RUNS_INDEX) === true) {
			$output->info(self::SUBJECT_RUNS_INDEX . ' already present on openregister_flow_runs; nothing to do.');
			return $schema;
		}

		$table->addIndex(['organisation', 'subject_uuid', 'status', 'id'], self::SUBJECT_RUNS_INDEX);
		$output->info(
			'Added ' . self::SUBJECT_RUNS_INDEX . ' on openregister_flow_runs(organisation, subject_uuid, status, id): '
			. 'the subject-scoped live and completed reads are a range scan on one case '
			. 'rather than a walk over every run of the tenant.'
		);

		return $schema;
	}//end changeSchema()
}//end class
