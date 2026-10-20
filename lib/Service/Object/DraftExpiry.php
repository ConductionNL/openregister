<?php

/**
 * Removes drafts that outlived their schema's draft lifetime (decisions 216 and 228).
 *
 * A draft that nobody changed for its schema's lifetime is removed. The
 * lifetime is 30 days unless the schema's configuration says otherwise:
 * `draftLifetimeDays` holds another whole number of days, or `false` to keep
 * the schema's drafts. One sweep serves every schema of every app, so no app
 * carries a cleanup of its own.
 *
 * Removal goes through ObjectService::deleteObject(), the delete every other
 * caller uses, so its guards and its file cleanup apply. That path removes a
 * draft for good, with its files, lets a draft through on a schema with an
 * archival block, and leaves one audit-trail entry that names this job
 * (decision 226). A delete the path still refuses (an append-only schema, a
 * referenced object) keeps the draft and is counted as refused.
 *
 * The work of one run is bounded: at most MAX_REMOVALS drafts are removed,
 * and at most MAX_PAGES x PAGE_SIZE expired drafts are read per register and
 * schema pair. The list asks the store for the expired drafts alone, so the
 * rows read are expired rows, and what is left after the cap (removals and
 * reads alike) is counted as deferred and waits for the next run.
 *
 * Nothing here names an app or a procedure (decision 182).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One sweep over every schema that sets a draft lifetime.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */
class DraftExpiry {

	/**
	 * How many drafts one read fetches.
	 *
	 * @var integer
	 */
	public const PAGE_SIZE = 100;

	/**
	 * How many reads one register and schema pair gets per sweep; the rest waits for the next sweep.
	 *
	 * @var integer
	 */
	public const MAX_PAGES = 50;

	/**
	 * How many drafts one run removes at most, over all schemas; the rest waits for the next run.
	 *
	 * @var integer
	 */
	public const MAX_REMOVALS = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objects   Lists and deletes objects.
	 * @param RegisterMapper  $registers Lists the registers.
	 * @param SchemaMapper    $schemas   Loads each register's schemas.
	 * @param LoggerInterface $logger    Records what a sweep could not do.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Remove every draft whose last change is older than its schema's lifetime, up to the run's limit.
	 *
	 * @param DateTimeImmutable $now The moment the lifetimes are measured from.
	 *
	 * @return array{
	 *     schemas: int, expired: int, removed: int, refused: int, deferred: int,
	 *     perSchema: array<int, array{
	 *         register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int
	 *     }>
	 * }
	 *         Register and schema pairs with a lifetime, drafts past it, removed, not removed, left for the
	 *         next run, and the same per pair that holds an expired draft.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
	 */
	public function sweep(DateTimeImmutable $now): array {
		return $this->walk(now: $now, budget: self::MAX_REMOVALS);
	}//end sweep()

	/**
	 * Count what a sweep at this moment would remove, per schema, and remove nothing.
	 *
	 * For an administrator who wants to look before the job runs, the first
	 * run after an upgrade above all: the default lifetime of 30 days applies
	 * to drafts that were kept until now.
	 *
	 * @param DateTimeImmutable $now The moment the lifetimes are measured from.
	 *
	 * @return array{
	 *     schemas: int, expired: int, removed: int, refused: int, deferred: int,
	 *     perSchema: array<int, array{
	 *         register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int
	 *     }>
	 * }
	 *         The same shape as sweep(), with nothing removed: every expired draft is counted as deferred.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
	 */
	public function preview(DateTimeImmutable $now): array {
		return $this->walk(now: $now, budget: 0);
	}//end preview()

	/**
	 * Walk every register and schema pair whose drafts expire.
	 *
	 * @param DateTimeImmutable $now    The moment the lifetimes are measured from.
	 * @param int               $budget How many drafts may be removed; zero removes nothing.
	 *
	 * @return array{
	 *     schemas: int, expired: int, removed: int, refused: int, deferred: int,
	 *     perSchema: array<int, array{
	 *         register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int
	 *     }>
	 * }
	 *         The counts.
	 */
	private function walk(DateTimeImmutable $now, int $budget): array {
		$counts = ['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => []];
		$policy = new DraftStatusPolicy();

		foreach ($this->registers->findAll(_rbac: false, _multitenancy: false) as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$schema = $this->schemaOf(schemaId: $schemaId);
				$days = $this->lifetimeOf(schema: $schema, policy: $policy);
				if ($days === null) {
					continue;
				}

				$counts['schemas']++;
				$cutoff = $now->modify('-' . $days . ' days');
				[$expired, $unread] = $this->expiredIn(register: $register, schema: $schema, cutoff: $cutoff);
				if ($expired === [] && $unread === 0) {
					continue;
				}

				$row = $this->removeWithin(
					register: $register,
					schema: $schema,
					expired: $expired,
					budget: ($budget - $counts['removed'] - $counts['refused']),
					cutoff: $cutoff,
					unread: $unread
				);
				$row['lifetimeDays'] = $days;
				$counts['perSchema'][] = $row;
				foreach (['expired', 'removed', 'refused', 'deferred'] as $key) {
					$counts[$key] += $row[$key];
				}
			}//end foreach
		}//end foreach

		return $counts;
	}//end walk()

	/**
	 * How many days the drafts of a schema may go unchanged, or null when the sweep leaves the schema alone.
	 *
	 * @param Schema|null       $schema The schema, null when it could not be loaded.
	 * @param DraftStatusPolicy $policy The lifetime rules.
	 *
	 * @return int|null The days.
	 */
	private function lifetimeOf(?Schema $schema, DraftStatusPolicy $policy): ?int {
		if ($schema === null || $this->holdsDrafts(schema: $schema) === false) {
			return null;
		}

		return $policy->draftLifetimeDays(schema: $schema);
	}//end lifetimeOf()

	/**
	 * Whether a schema stores objects of its own, so it can hold a draft.
	 *
	 * A schema served from an object source (a view, an external table) has
	 * no stored status and nothing here may ask that source for drafts.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when its objects live in OpenRegister.
	 */
	private function holdsDrafts(Schema $schema): bool {
		return $schema->getObjectSource() === null;
	}//end holdsDrafts()

	/**
	 * Remove the expired drafts of one pair while the run's budget lasts.
	 *
	 * @param Register           $register The register.
	 * @param Schema             $schema   The schema.
	 * @param array<int, string> $expired  The uuids of its expired drafts.
	 * @param int                $budget   How many delete attempts are left in this run.
	 * @param DateTimeImmutable  $cutoff   The cutoff the list was made with, asked again just before each delete.
	 * @param int                $unread   How many expired drafts lie beyond what one run reads; they wait for the next run.
	 *
	 * @return array{register: string, schema: string, lifetimeDays: int, expired: int, removed: int, refused: int, deferred: int}
	 *         The counts of this pair; the caller fills in the lifetime.
	 */
	private function removeWithin(Register $register, Schema $schema, array $expired, int $budget, DateTimeImmutable $cutoff, int $unread=0): array {
		$row = [
			'register' => (string)($register->getSlug() ?? $register->getId()),
			'schema' => (string)($schema->getSlug() ?? $schema->getId()),
			'lifetimeDays' => 0,
			'expired' => (count($expired) + $unread),
			'removed' => 0,
			'refused' => 0,
			'deferred' => $unread,
		];

		foreach ($expired as $uuid) {
			if (($row['removed'] + $row['refused']) >= $budget) {
				$row['deferred']++;
				continue;
			}

			if ($this->remove(register: $register, schema: $schema, uuid: $uuid, cutoff: $cutoff) === true) {
				$row['removed']++;
				continue;
			}

			$row['refused']++;
		}

		return $row;
	}//end removeWithin()

	/**
	 * A register's schema, or null when it cannot be loaded.
	 *
	 * @param mixed $schemaId The id the register lists.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(mixed $schemaId): ?Schema {
		if (is_int($schemaId) === false && is_string($schemaId) === false) {
			return null;
		}

		try {
			return $this->schemas->find($schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[DraftExpiry] A schema could not be loaded, its drafts are left alone',
				context: ['schema' => (string)$schemaId, 'exception' => $exception->getMessage()]
			);

			return null;
		}
	}//end schemaOf()

	/**
	 * The uuids of the drafts in one register and schema that nobody changed since the cutoff, and how many more lie beyond the read cap.
	 *
	 * The store is asked for the expired drafts alone (last changed before the
	 * cutoff, or never changed and created before it), so the rows one run
	 * reads are expired rows and a schema full of young drafts cannot hide an
	 * old one behind them. Read in full before anything is deleted, so a
	 * delete never shifts the page the next read asks for. When the cap of
	 * MAX_PAGES full pages is reached, the rest is counted, not read.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $cutoff   Drafts last changed before this moment are expired.
	 *
	 * @return array{0: array<int, string>, 1: int} The uuids, and the number of expired drafts left unread.
	 */
	private function expiredIn(Register $register, Schema $schema, DateTimeImmutable $cutoff): array {
		$moment = gmdate('Y-m-d H:i:s', $cutoff->getTimestamp());
		$filters = [
			// Changed at some point, and not since the cutoff.
			['updated' => ['lt' => $moment]],
			// Never changed: the created moment counts.
			['updated' => 'IS NULL', 'created' => ['lt' => $moment]],
		];

		$expired = [];
		$unread = 0;
		$pages = self::MAX_PAGES;
		foreach ($filters as $filter) {
			$query = [
				'@self' => (['register' => (string)$register->getId(), 'schema' => (string)$schema->getId()] + $filter),
				// Drafts alone, whoever owns them (decision 201's lens).
				DraftListLens::PARAMETER => 'true',
			];
			if ($pages <= 0) {
				$unread += $this->countOf(register: $register, schema: $schema, query: $query);
				continue;
			}

			$scan = $this->scan(register: $register, schema: $schema, query: $query, cutoff: $cutoff, pages: $pages);
			array_push($expired, ...$scan['uuids']);
			if ($scan['failed'] === true) {
				// A list that cannot be read is logged once; the schema's drafts wait for the next run.
				break;
			}

			$pages -= $scan['pages'];
			if ($scan['full'] === true) {
				$unread += max(1, ($this->countOf(register: $register, schema: $schema, query: $query) - $scan['rows']));
			}
		}

		return [array_values(array_unique($expired)), $unread];
	}//end expiredIn()

	/**
	 * Read the pages of one list, up to a page budget.
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The schema.
	 * @param array<string, mixed> $query    The list query, without paging.
	 * @param DateTimeImmutable    $cutoff   Drafts last changed before this moment are expired.
	 * @param int                  $pages    How many pages may be read.
	 *
	 * @return array{uuids: array<int, string>, pages: int, rows: int, full: bool, failed: bool}
	 *         The expired uuids, the pages and rows read, whether the last page was full when the pages ran out,
	 *         and whether a read failed.
	 */
	private function scan(Register $register, Schema $schema, array $query, DateTimeImmutable $cutoff, int $pages): array {
		$scan = ['uuids' => [], 'pages' => 0, 'rows' => 0, 'full' => false, 'failed' => false];
		for ($page = 0; $page < $pages; $page++) {
			try {
				$found = $this->objects->searchObjects(
					query: ($query + ['_limit' => self::PAGE_SIZE, '_offset' => ($page * self::PAGE_SIZE)]),
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					message: '[DraftExpiry] The drafts of a schema could not be listed',
					context: ['register' => $register->getSlug(), 'schema' => $schema->getSlug(), 'exception' => $exception->getMessage()]
				);
				$scan['failed'] = true;
				break;
			}

			if (is_array($found) === false) {
				$scan['failed'] = true;
				break;
			}

			$scan['pages']++;
			$scan['rows'] += count($found);
			foreach ($found as $candidate) {
				// The store was asked for expired drafts; the object is asked once more.
				if ($this->isExpired(candidate: $candidate, cutoff: $cutoff) === true) {
					$scan['uuids'][] = (string)$candidate->getUuid();
				}
			}

			if (count($found) < self::PAGE_SIZE) {
				return $scan;
			}

			$scan['full'] = (($page + 1) === $pages);
		}//end for

		return $scan;
	}//end scan()

	/**
	 * How many drafts one list matches, for what lies beyond the read cap.
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The schema.
	 * @param array<string, mixed> $query    The list query, without paging.
	 *
	 * @return int The count; zero when it cannot be asked.
	 */
	private function countOf(Register $register, Schema $schema, array $query): int {
		try {
			return (int)$this->objects->countSearchObjects(query: $query, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[DraftExpiry] The expired drafts beyond the read cap could not be counted',
				context: ['register' => $register->getSlug(), 'schema' => $schema->getSlug(), 'exception' => $exception->getMessage()]
			);

			return 0;
		}
	}//end countOf()

	/**
	 * Whether a search hit is a draft whose last change is before the cutoff.
	 *
	 * A hit that is not a draft is never expired, whatever the search
	 * answered: the status is asked of the object itself, so a search that
	 * ignores the lens cannot make this sweep delete a sent object. A draft
	 * without any date is left alone too.
	 *
	 * @param mixed             $candidate The search hit.
	 * @param DateTimeImmutable $cutoff    The cutoff.
	 *
	 * @return bool True when it is an expired draft.
	 *
	 * @psalm-assert-if-true ObjectEntity $candidate
	 */
	private function isExpired(mixed $candidate, DateTimeImmutable $cutoff): bool {
		if (($candidate instanceof ObjectEntity) === false || $candidate->isDraft() === false) {
			return false;
		}

		$changed = ($candidate->getUpdated() ?? $candidate->getCreated());
		if ($changed === null || (string)$candidate->getUuid() === '') {
			return false;
		}

		return $changed->getTimestamp() < $cutoff->getTimestamp();
	}//end isExpired()

	/**
	 * Delete one expired draft through the ordinary delete path.
	 *
	 * The list was read before the first delete, and a sweep can take a
	 * while: the owner may have sent the draft since. The object is read
	 * again here and deleted only while it is still a draft last changed
	 * before the cutoff, so a submission that just got its receipt is never
	 * removed. An object that cannot be read again is left alone.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param string            $uuid     The draft.
	 * @param DateTimeImmutable $cutoff   Drafts last changed before this moment are expired.
	 *
	 * @return bool True when it was removed.
	 */
	private function remove(Register $register, Schema $schema, string $uuid, DateTimeImmutable $cutoff): bool {
		try {
			$current = $this->objects->find(
				id: $uuid,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
			if ($this->isExpired(candidate: $current, cutoff: $cutoff) === false) {
				$this->logger->info(
					message: '[DraftExpiry] A draft was not removed: it is no longer an expired draft',
					context: ['register' => $register->getSlug(), 'schema' => $schema->getSlug(), 'uuid' => $uuid]
				);

				return false;
			}

			// RBAC off: a background job has no session, and the lifetime the
			// schema declares is the authorisation. Everything else the delete
			// path guards (append-only, referential integrity, files) stays
			// on; the path itself makes a draft's delete permanent and writes
			// its audit-trail entry. DraftExpiryJob runs the sweep under its
			// own name, so that entry says which job removed the draft.
			return $this->objects->deleteObject(uuid: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[DraftExpiry] An expired draft was not removed',
				context: ['register' => $register->getSlug(), 'schema' => $schema->getSlug(), 'uuid' => $uuid, 'exception' => $exception->getMessage()]
			);

			return false;
		}
	}//end remove()
}//end class
