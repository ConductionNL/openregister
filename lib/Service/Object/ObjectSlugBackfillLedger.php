<?php

/**
 * OpenRegister ObjectSlugBackfillLedger
 *
 * What a slug backfill run leaves behind once its slugs are written: one audit
 * entry, the overflow of that entry in the log, and an evicted cache (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\SlugBackfill;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The bookkeeping side of the slug backfill.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */
class ObjectSlugBackfillLedger {

	/**
	 * Records the audit entry of a run that changed slugs.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrail;

	/**
	 * Receives the overflow lines and the failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ObjectCacheInvalidationInterface $cacheHandler Object cache, evicted after writing.
	 * @param AuditRecording                   $audit        The audit trail and the log for overflow lines and failures.
	 */
	public function __construct(
		private readonly ObjectCacheInvalidationInterface $cacheHandler,
		AuditRecording $audit,
	) {
		$this->auditTrail = $audit->trail;
		$this->logger = $audit->logger;
	}//end __construct()

	/**
	 * Evict the schema's cached objects; a failure is logged, not thrown, so it
	 * cannot hide the run's own outcome.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return void
	 */
	private function evictCache(Register $register, Schema $schema): void {
		try {
			$this->cacheHandler->invalidateForObjectChange(
				object: null,
				operation: 'bulk_update',
				registerId: (int)$register->getId(),
				schemaId: (int)$schema->getId()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf(
					'[ObjectSlug] Could not evict the cache after the slug backfill (register %s, schema %s): %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					$e->getMessage()
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end evictCache()

	/**
	 * One audit-trail entry for the run, with the old to new list (capped, see logLargeRun()),
	 * then evict the schema's cached objects so the new slugs are served.
	 *
	 * A failure to write the entry is logged with the full list, so the change
	 * stays reconstructable, and does not undo the slugs already written.
	 *
	 * @param ObjectSlugChangeSet $applied The writes that were applied, their table and the run's counters.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function record(ObjectSlugChangeSet $applied): void {
		$register = $applied->register;
		$schema = $applied->schema;
		$this->logLargeRun(applied: $applied);
		try {
			$target = new AuditedObject(register: (int)$register->getId(), schema: (int)$schema->getId());
			$this->auditTrail->recordSlugBackfill(
				backfill: new SlugBackfill(changes: $applied->changes, summary: $applied->summary, target: $target)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf(
					'[ObjectSlug] Could not write the audit entry of the slug backfill (register %s, schema %s): %s; changes: %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					$e->getMessage(),
					$this->encodeChanges(changes: $applied->changes)
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}//end try

		$this->evictCache(register: $register, schema: $schema);
	}//end record()

	/**
	 * A change list as JSON for a log line; invalid UTF-8 in a slug is replaced, not fatal.
	 *
	 * @param array $changes The changes.
	 *
	 * @return string The JSON, or a marker when it cannot be encoded.
	 */
	private function encodeChanges(array $changes): string {
		$json = json_encode($changes, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return '[unencodable]';
		}

		return $json;
	}//end encodeChanges()

	/**
	 * Put the full change list in the log when the audit entry cannot hold it.
	 *
	 * The entry lists the first {@see AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES}
	 * changes; the rest of a large run is reconstructable from these lines.
	 *
	 * @param ObjectSlugChangeSet $applied The writes that were applied and their table.
	 *
	 * @return void
	 */
	private function logLargeRun(ObjectSlugChangeSet $applied): void {
		if (count($applied->changes) <= AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES) {
			return;
		}

		$register = $applied->register;
		$schema = $applied->schema;
		$parts = array_chunk($applied->changes, AuditTrailMapper::SLUG_BACKFILL_MAX_CHANGES);
		foreach ($parts as $index => $part) {
			// Warning, not info: Nextcloud's default loglevel (2) drops info lines,
			// and these are the only record of the changes beyond the audit entry.
			$this->logger->warning(
				message: sprintf(
					'[ObjectSlug] Slug backfill changes (register %s, schema %s, part %d of %d): %s',
					(string)$register->getId(),
					(string)$schema->getId(),
					($index + 1),
					count($parts),
					$this->encodeChanges(changes: $part)
				),
				context: ['app' => 'openregister', 'path' => 'backfill']
			);
		}
	}//end logLargeRun()
}//end class
