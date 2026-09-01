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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntry;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Shared schema-update gate + changelog recorder.
 */
class SchemaVersioningService {
	/**
	 * Constructor.
	 *
	 * @param SchemaDiffService $diffService Pure diff/classification.
	 * @param SchemaChangelogMapper $changelogMapper Changelog persistence.
	 * @param SchemaRunMapper $runMapper Run persistence (for invalid counts).
	 * @param SchemaRunEntryMapper $runEntryMapper Per-object run entries.
	 * @param IUserSession $userSession Current user resolution.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaDiffService $diffService,
		private readonly SchemaChangelogMapper $changelogMapper,
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRunEntryMapper $runEntryMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Diff the incoming definition against the stored schema.
	 *
	 * @param Schema $existing The stored schema.
	 * @param array<string, mixed> $newDefinition The incoming definition (properties/required).
	 * @param array<string, string> $renames Optional declared renames.
	 *
	 * @return SchemaChangeSet The classified change set.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function classify(Schema $existing, array $newDefinition, array $renames = []): SchemaChangeSet {
		$old = [
			'properties' => ($existing->getProperties() ?? []),
			'required' => ($existing->getRequired() ?? []),
		];

		return $this->diffService->diff($old, $newDefinition, $renames);
	}//end classify()

	/**
	 * Enforce the breaking-change acknowledgement gate.
	 *
	 * @param SchemaChangeSet $changeSet The classified change set.
	 * @param bool $acknowledged Whether acknowledgeBreaking was set.
	 * @param int $schemaId The schema id (for invalid-count lookup).
	 *
	 * @return void
	 *
	 * @throws BreakingSchemaChangeException When breaking and unacknowledged.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function enforceGate(SchemaChangeSet $changeSet, bool $acknowledged, int $schemaId): void {
		if ($changeSet->isBreaking() === false) {
			return;
		}

		if ($acknowledged === true) {
			return;
		}

		throw new BreakingSchemaChangeException(
			changes: $changeSet->getChanges(),
			invalidCount: $this->latestInvalidCount(schemaId: $schemaId)
		);

	}//end enforceGate()

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
	 * Record a changelog entry for an applied schema update.
	 *
	 * No entry is written for a metadata-only (no structural change) update.
	 *
	 * @param int $schemaId The schema id.
	 * @param string|null $version The resulting version.
	 * @param SchemaChangeSet $changeSet The classified change set.
	 * @param bool $acknowledged Whether the change was acknowledged.
	 *
	 * @return SchemaChangelog|null The recorded entry, or null for a no-op.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function recordChangelog(int $schemaId, ?string $version, SchemaChangeSet $changeSet, bool $acknowledged): ?SchemaChangelog {
		if ($changeSet->hasChanges() === false) {
			return null;
		}

		$actor = $this->currentActor();

		$data = [
			'schemaId' => $schemaId,
			'version' => $version,
			'classification' => $changeSet->getClassification(),
			'changes' => $changeSet->getChanges(),
			'actor' => $actor,
		];

		if ($changeSet->isBreaking() === true && $acknowledged === true) {
			$data['acknowledgedBy'] = $actor;
			$data['acknowledgedAt'] = new DateTime();
		}

		try {
			return $this->changelogMapper->createFromArray($data);
		} catch (\Throwable $e) {
			// Changelog persistence must never break the update itself.
			$this->logger->warning(
				'[SchemaVersioningService] Failed to record changelog',
				['schema_id' => $schemaId, 'error' => $e->getMessage()]
			);
			return null;
		}

	}//end recordChangelog()

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
			$runs = $this->runMapper->findBySchema($schemaId, 25);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ($runs as $run) {
			if ($run->getType() !== SchemaRun::TYPE_REVALIDATION) {
				continue;
			}

			if ($run->getState() !== SchemaRun::STATE_COMPLETED) {
				continue;
			}

			$report = ($run->getReport() ?? []);
			if (array_key_exists('invalid', $report) === true) {
				return (int)$report['invalid'];
			}

			// Fallback: count invalid entries in the side table.
			try {
				return count($this->runEntryMapper->findByRun($run->getId(), SchemaRunEntry::OUTCOME_INVALID));
			} catch (\Throwable $e) {
				return null;
			}
		}//end foreach

		return null;
	}//end latestInvalidCount()

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
