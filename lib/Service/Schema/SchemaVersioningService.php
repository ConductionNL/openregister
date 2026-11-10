<?php

/**
 * SchemaVersioningService — the shared gate + changelog for schema updates.
 *
 * Wraps the pure {@see SchemaDiffService} with the persistence and gating
 * that every schema-update entry point (controller update, uploadUpdate,
 * the runtime schema API, configuration import) funnels through:
 *
 *  - diff the incoming definition against the stored schema and classify it;
 *  - enforce the breaking-change acknowledgement gate (throws
 *    {@see BreakingSchemaChangeException} on an unacknowledged breaking
 *    change, surfacing the invalid-object count from the latest revalidation
 *    run when one exists);
 *  - compute the next semantic version;
 *  - record the classified changelog entry (with acknowledging actor when
 *    breaking) once the update has been applied.
 *
 * The configuration-import carve-out lets an app implicitly acknowledge
 * breaking changes to schemas it owns (its own register) so app upgrades
 * stay unblocked, while foreign edits remain gated.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use DateTime;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Shared schema-update gate + changelog recorder.
 */
class SchemaVersioningService {
	/**
	 * Pure diff/classification: constructor-less, so it is built here, not injected.
	 *
	 * @var SchemaDiffService
	 */
	private readonly SchemaDiffService $diffService;

	/**
	 * Constructor.
	 *
	 * @param SchemaChangelogMapperInterface $changelogMapper Changelog persistence.
	 * @param SchemaPopulationRuns           $runs            The run ledger (for invalid counts).
	 * @param IUserSession                   $userSession     Current user resolution.
	 * @param LoggerInterface                $logger          Logger.
	 */
	public function __construct(
		private readonly SchemaChangelogMapperInterface $changelogMapper,
		private readonly SchemaPopulationRuns $runs,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		$this->diffService = new SchemaDiffService();
	}//end __construct()

	/**
	 * Diff the incoming definition against the stored schema.
	 *
	 * @param Schema             $existing The stored schema.
	 * @param IncomingDefinition $incoming The incoming definition (properties/required) and its declared renames.
	 *
	 * @return SchemaChangeSet The classified change set.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function classify(Schema $existing, IncomingDefinition $incoming): SchemaChangeSet {
		$old = [
			'properties' => ($existing->getProperties() ?? []),
			'required' => ($existing->getRequired() ?? []),
		];

		return $this->diffService->diff($old, $incoming->definition, $incoming->renames);
	}//end classify()

	/**
	 * Enforce the breaking-change acknowledgement gate on a change record.
	 *
	 * @param int                $schemaId The schema id (for invalid-count lookup).
	 * @param SchemaChangeRecord $record   The change set and whether it was acknowledged.
	 *
	 * @return void
	 *
	 * @throws BreakingSchemaChangeException When breaking and unacknowledged.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function gateChange(int $schemaId, SchemaChangeRecord $record): void {
		if ($record->changeSet->isBreaking() === false) {
			return;
		}

		if ($record->acknowledged === true) {
			return;
		}

		throw new BreakingSchemaChangeException(
			changes: $record->changeSet->getChanges(),
			invalidCount: $this->latestInvalidCount(schemaId: $schemaId)
		);

	}//end gateChange()

	/**
	 * Compute the next version for a schema given a change set.
	 *
	 * @param Schema $existing The stored schema.
	 * @param SchemaChangeSet $changeSet The classified change set.
	 *
	 * @return string The next version.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function nextVersion(Schema $existing, SchemaChangeSet $changeSet): string {
		return $this->diffService->nextVersion($existing->getVersion(), $changeSet);
	}//end nextVersion()

	/**
	 * Record a changelog entry for an applied schema change.
	 *
	 * No entry is written for a metadata-only (no structural change) update.
	 *
	 * @param int                $schemaId The schema id.
	 * @param SchemaChangeRecord $record   The change set, acknowledgement, resulting version and origin.
	 *
	 * @return SchemaChangelog|null The recorded entry, or null for a no-op.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function recordChange(int $schemaId, SchemaChangeRecord $record): ?SchemaChangelog {
		if ($record->changeSet->hasChanges() === false) {
			return null;
		}

		$data = $this->changelogData(schemaId: $schemaId, record: $record);

		return $this->persistChangelog(data: $this->withAcknowledgement(data: $data, record: $record));
	}//end recordChange()

	/**
	 * A breaking change's changelog fields with who acknowledged it, or warned about when nobody did.
	 *
	 * @param array<string, mixed> $data   The changelog fields.
	 * @param SchemaChangeRecord   $record The change set, acknowledgement and origin.
	 *
	 * @return array<string, mixed> The fields to record.
	 */
	private function withAcknowledgement(array $data, SchemaChangeRecord $record): array {
		if ($record->changeSet->isBreaking() === false) {
			return $data;
		}

		if ($record->acknowledged === false) {
			$this->warnUnacknowledged(data: $data, origin: $record->origin);
			return $data;
		}

		$data['acknowledgedBy'] = $data['actor'];
		$data['acknowledgedAt'] = new DateTime();

		return $data;
	}//end withAcknowledgement()

	/**
	 * The changelog fields every recorded change carries.
	 *
	 * @param int                $schemaId The schema id.
	 * @param SchemaChangeRecord $record   The change set and resulting version.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function changelogData(int $schemaId, SchemaChangeRecord $record): array {
		return [
			'schemaId' => $schemaId,
			'version' => $record->version,
			'classification' => $record->changeSet->getClassification(),
			'changes' => $record->changeSet->getChanges(),
			'actor' => $this->currentActor(),
		];
	}//end changelogData()

	/**
	 * Log an unacknowledged breaking change.
	 *
	 * Paths without a person to ask are recorded and logged, never refused (decided 29 Sep 2026).
	 *
	 * @param array<string, mixed> $data   The changelog fields.
	 * @param string|null          $origin Where the change came from.
	 *
	 * @return void
	 */
	private function warnUnacknowledged(array $data, ?string $origin): void {
		$this->logger->warning(
			'[SchemaVersioningService] An unacknowledged breaking schema change was recorded, not refused.',
			[
				'schema_id' => $data['schemaId'],
				'version' => $data['version'],
				'origin' => $origin,
				'actor' => $data['actor'],
				'changes' => $data['changes'],
			]
		);

	}//end warnUnacknowledged()

	/**
	 * Write a changelog entry; a failed write never breaks the update itself.
	 *
	 * @param array<string, mixed> $data The changelog fields.
	 *
	 * @return SchemaChangelog|null The entry, or null when the write failed.
	 */
	private function persistChangelog(array $data): ?SchemaChangelog {
		try {
			return $this->changelogMapper->createFromArray($data);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[SchemaVersioningService] Failed to record changelog',
				['schema_id' => $data['schemaId'], 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end persistChangelog()

	/**
	 * The invalid-object count from the most recent completed revalidation,
	 * or null when none exists.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int|null The invalid count, or null.
	 */
	private function latestInvalidCount(int $schemaId): ?int {
		try {
			$runs = $this->runs->runMapper->findBySchema($schemaId, 25);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($runs as $run) {
			if ($this->isCompletedRevalidation(run: $run) === true) {
				return $this->invalidCountOf(run: $run);
			}
		}

		return null;
	}//end latestInvalidCount()

	/**
	 * Whether a run is a revalidation that completed.
	 *
	 * @param SchemaRun $run The run.
	 *
	 * @return bool True for a completed revalidation.
	 */
	private function isCompletedRevalidation(SchemaRun $run): bool {
		return $run->getType() === SchemaRun::TYPE_REVALIDATION && $run->getState() === SchemaRun::STATE_COMPLETED;
	}//end isCompletedRevalidation()

	/**
	 * The invalid count of one completed revalidation: its report, else its invalid entries.
	 *
	 * @param SchemaRun $run The run.
	 *
	 * @return int|null The count, or null when the entries cannot be read.
	 */
	private function invalidCountOf(SchemaRun $run): ?int {
		$report = ($run->getReport() ?? []);
		if (array_key_exists('invalid', $report) === true) {
			return (int)$report['invalid'];
		}

		try {
			return count($this->runs->runEntryMapper->findByRun(runId: $run->getId(), filter: new OutcomeFilter(outcome: SchemaRunEntry::OUTCOME_INVALID)));
		} catch (\Throwable $e) {
			return null;
		}

	}//end invalidCountOf()

	/**
	 * The current acting user id, or 'system'.
	 *
	 * @return string The actor id.
	 */
	private function currentActor(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return 'system';
	}//end currentActor()
}//end class
