<?php

/**
 * Storage for parallel streams: place claims, stream rows, per-place items, a
 * durable firing count, and branch identity on step rows.
 *
 * All additions are additive and the old code reads none of them: a null
 * `stream_id` is the single implicit stream and `sequence` keeps its old
 * meaning for every back-filled row, so reverting the app code restores the
 * previous behaviour with the new columns inert. Dropping them is a separate,
 * optional migration and MUST NOT be part of the rollback path.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use DateTime;
use Doctrine\DBAL\Types\Types;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flow_claims`, `openregister_flow_streams`, the run and
 * step columns, and back-fills streams for in-flight runs.
 */
class Version1Date20260901120000 extends SimpleMigrationStep {

	private const CLAIMS = 'openregister_flow_claims';

	private const STREAMS = 'openregister_flow_streams';

	private const RUNS = 'openregister_flow_runs';

	private const STEPS = 'openregister_flow_steps';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection, for the back-fill.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {

	}//end __construct()

	/**
	 * Create the two tables and add the four columns.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One existence guard per table, column and index added.
	 * @SuppressWarnings(PHPMD.NPathComplexity) The same guards, multiplied; each is required for idempotency.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(self::CLAIMS) === false) {
			$table = $schema->createTable(self::CLAIMS);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('run_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('place', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('stream_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('transition', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('claimed_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			// The unique index IS the lock.
			$table->addUniqueIndex(['run_uuid', 'place'], 'or_flowclaim_place_uq');
			// The reaper's read.
			$table->addIndex(['claimed_at'], 'or_flowclaim_at_idx');
			// The per-pass ceiling's read.
			$table->addIndex(['owner'], 'or_flowclaim_owner_idx');
			$output->info(message: 'Created ' . self::CLAIMS);
			$changed = true;
		}

		if ($schema->hasTable(self::STREAMS) === false) {
			$table = $schema->createTable(self::STREAMS);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('run_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('stream_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('ordinal_path', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('parent_stream_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			// The place currently holding this stream's token: "on what" a
			// branch waits, and how a resumed run knows which token is whose.
			$table->addColumn('place', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('resume_at', Types::DATETIME_MUTABLE, ['notnull' => false, 'default' => null]);
			$table->addColumn('next_sequence', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => false, 'default' => null]);
			$table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['run_uuid', 'stream_id'], 'or_flowstream_id_uq');
			$table->addIndex(['run_uuid', 'status'], 'or_flowstream_status_idx');
			$output->info(message: 'Created ' . self::STREAMS);
			$changed = true;
		}

		if ($schema->hasTable(self::RUNS) === true) {
			$runs = $schema->getTable(self::RUNS);
			if ($runs->hasColumn('place_items') === false) {
				$runs->addColumn('place_items', Types::JSON, ['notnull' => false, 'default' => null]);
				$changed = true;
			}

			if ($runs->hasColumn('firings') === false) {
				$runs->addColumn('firings', Types::INTEGER, ['notnull' => true, 'default' => 0]);
				$changed = true;
			}
		}

		if ($schema->hasTable(self::STEPS) === true) {
			$steps = $schema->getTable(self::STEPS);
			if ($steps->hasColumn('stream_id') === false) {
				$steps->addColumn('stream_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
				$changed = true;
			}

			if ($steps->hasColumn('ordinal_path') === false) {
				$steps->addColumn('ordinal_path', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
				$changed = true;
			}

			if ($steps->hasIndex('or_flowstep_ordinal_idx') === false) {
				$steps->addIndex(['run_uuid', 'ordinal_path', 'sequence'], 'or_flowstep_ordinal_idx');
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Back-fill streams for in-flight runs and stamp historical step rows.
	 *
	 * One stream per MARKED place on every non-terminal run, ordinals in
	 * sorted place-name order, `next_sequence` from the run's highest step
	 * sequence + 1 so the resumed history continues. Existing step rows are
	 * stamped with the root path and the root stream id. `place_items` is
	 * left null so it seeds from the flat `items` on first read. Guarded on
	 * existence throughout, so a second run creates no duplicate stream and
	 * re-stamps no step.
	 *
	 * Honest caveat, stated here rather than left for someone to discover: a
	 * pre-upgrade run's ordinals are place-name order, not the author's
	 * declaration order, because declaration order was never recorded for a
	 * run already in flight. Such a run is not ordinal-comparable with one
	 * started after the upgrade.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure.
	 * @param array $options Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The step signature is Nextcloud's.
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowStream::childPath is a pure path helper on a value object.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$now = new DateTime();
		$runsSeeded = 0;
		$stepsStamped = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->select('uuid', 'status', 'marking', 'resume_at')
			->from(self::RUNS)
			->where($qb->expr()->in('status', $qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$runs = $result->fetchAll();
		$result->closeCursor();

		foreach ($runs as $row) {
			$uuid = (string)($row['uuid'] ?? '');
			if ($uuid === '' || $this->hasStreams(runUuid: $uuid) === true) {
				continue;
			}

			$places = $this->markedPlaces(marking: ($row['marking'] ?? null));
			if ($places === []) {
				// Queued, never started: its first firing mints the root.
				continue;
			}

			sort($places, SORT_STRING);
			$nextSequence = ($this->highestSequence(runUuid: $uuid) + 1);
			$ordinal = 1;
			$rootId = null;
			foreach ($places as $place) {
				$streamId = self::streamIdFor(runUuid: $uuid, ordinal: $ordinal);
				$rootId ??= $streamId;
				$path = FlowStream::ROOT_PATH;
				if ($ordinal > 1) {
					$path = FlowStream::childPath(parentPath: FlowStream::ROOT_PATH, index: $ordinal);
				}

				$insert = $this->db->getQueryBuilder();
				$insert->insert(self::STREAMS)
					->values(
						[
							'run_uuid' => $insert->createNamedParameter($uuid),
							'stream_id' => $insert->createNamedParameter($streamId),
							'ordinal_path' => $insert->createNamedParameter($path),
							'parent_stream_id' => $insert->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
							'place' => $insert->createNamedParameter($place),
							'status' => $insert->createNamedParameter((string)($row['status'] ?? FlowRun::STATUS_QUEUED)),
							'resume_at' => $insert->createNamedParameter($this->dateOrNull(value: ($row['resume_at'] ?? null)), IQueryBuilder::PARAM_DATE),
							'next_sequence' => $insert->createNamedParameter($nextSequence, IQueryBuilder::PARAM_INT),
							'created' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
							'updated' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
						]
					);
				$insert->executeStatement();
				$ordinal++;
			}//end foreach

			$runsSeeded++;
			$stepsStamped += $this->stampSteps(runUuid: $uuid, rootStreamId: (string)$rootId);
		}//end foreach

		// Historical (terminal) runs' steps are stamped too, so canonical
		// ordering reproduces today's order for every run exactly.
		$stepsStamped += $this->stampUnstampedSteps();

		$output->info(
			message: sprintf(
				'flow-parallel-streams back-fill: %d in-flight run(s) given streams, %d step row(s) stamped with the root path. '
				. 'Pre-upgrade runs carry place-name ordinals, not declaration ordinals, and are not ordinal-comparable with runs started after this upgrade.',
				$runsSeeded,
				$stepsStamped
			)
		);
	}//end postSchemaChange()

	/**
	 * A deterministic stream id for a back-filled stream.
	 *
	 * @param string $runUuid The run.
	 * @param int $ordinal The 1-based ordinal.
	 *
	 * @return string The stream id.
	 */
	public static function streamIdFor(string $runUuid, int $ordinal): string {
		return substr(sha1($runUuid . ':' . $ordinal), 0, 32);
	}//end streamIdFor()

	/**
	 * Whether a run already has stream rows.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return bool True when streams exist.
	 */
	private function hasStreams(string $runUuid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from(self::STREAMS)
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}//end hasStreams()

	/**
	 * The marked place names of a stored marking.
	 *
	 * @param mixed $marking The raw column value.
	 *
	 * @return array<int, string> The places.
	 */
	private function markedPlaces(mixed $marking): array {
		if (is_string($marking) === true) {
			$marking = json_decode($marking, true);
		}

		if (is_array($marking) === false) {
			return [];
		}

		$places = [];
		foreach ($marking as $key => $value) {
			if (is_int($key) === true) {
				$places[] = (string)$value;
				continue;
			}

			if ((int)$value > 0) {
				$places[] = (string)$key;
			}
		}

		return array_values(array_unique($places));
	}//end markedPlaces()

	/**
	 * The highest step sequence of a run, 0 when it has none.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int The highest sequence.
	 */
	private function highestSequence(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sequence'))
			->from(self::STEPS)
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return (int)($max ?? 0);
	}//end highestSequence()

	/**
	 * Stamp a run's unstamped step rows with the root path and root stream.
	 *
	 * @param string $runUuid The run.
	 * @param string $rootStreamId The root stream id.
	 *
	 * @return int Rows stamped.
	 */
	private function stampSteps(string $runUuid, string $rootStreamId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::STEPS)
			->set('ordinal_path', $qb->createNamedParameter(FlowStream::ROOT_PATH))
			->set('stream_id', $qb->createNamedParameter($rootStreamId))
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->isNull('ordinal_path'));

		return $qb->executeStatement();
	}//end stampSteps()

	/**
	 * Stamp every remaining unstamped step row with the root path.
	 *
	 * @return int Rows stamped.
	 */
	private function stampUnstampedSteps(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::STEPS)
			->set('ordinal_path', $qb->createNamedParameter(FlowStream::ROOT_PATH))
			->where($qb->expr()->isNull('ordinal_path'));

		return $qb->executeStatement();
	}//end stampUnstampedSteps()

	/**
	 * A stored datetime string as a DateTime, or null.
	 *
	 * @param mixed $value The raw column value.
	 *
	 * @return DateTime|null The parsed value.
	 */
	private function dateOrNull(mixed $value): ?DateTime {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (\Throwable) {
			return null;
		}
	}//end dateOrNull()
}//end class
