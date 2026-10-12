<?php

/**
 * Removes drafts that outlived their schema's draft lifetime (decision 216).
 *
 * A schema may set `draftLifetimeDays` in its configuration. A draft of that
 * schema that nobody changed for that many days is removed. A schema without
 * the setting keeps its drafts, as before. One sweep serves every schema of
 * every app, so no app carries a cleanup of its own.
 *
 * Removal goes through ObjectService::deleteObject(), the delete every other
 * caller uses, so its guards and its file cleanup apply. That path removes a
 * draft for good, with its files, and lets a draft through on a schema with
 * an archival block: a draft was never received, so it is not yet a record.
 * A delete the path still refuses (an append-only schema, a referenced
 * object) keeps the draft and is counted as refused.
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
	 * Remove every draft whose last change is older than its schema's lifetime.
	 *
	 * @param DateTimeImmutable $now The moment the lifetimes are measured from.
	 *
	 * @return array{schemas: int, expired: int, removed: int, refused: int} Schemas with a lifetime,
	 *                                                                       drafts past it, removed, and not removed.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
	 */
	public function sweep(DateTimeImmutable $now): array {
		$counts = ['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0];
		$policy = new DraftStatusPolicy();
		$lifetimes = [];

		foreach ($this->registers->findAll(_rbac: false, _multitenancy: false) as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$schema = $this->schemaOf(schemaId: $schemaId);
				if ($schema === null) {
					continue;
				}

				$days = $policy->draftLifetimeDays(schema: $schema);
				if ($days === null) {
					continue;
				}

				$lifetimes[(string)$schema->getId()] = true;
				$cutoff = $now->modify('-' . $days . ' days');
				foreach ($this->expiredIn(register: $register, schema: $schema, cutoff: $cutoff) as $uuid) {
					$counts['expired']++;
					if ($this->remove(register: $register, schema: $schema, uuid: $uuid) === true) {
						$counts['removed']++;
						continue;
					}

					$counts['refused']++;
				}
			}//end foreach
		}//end foreach

		$counts['schemas'] = count($lifetimes);

		return $counts;
	}//end sweep()

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
	 * The uuids of the drafts in one register and schema that nobody changed since the cutoff.
	 *
	 * Read in full before anything is deleted, so a delete never shifts the
	 * page the next read asks for.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $cutoff   Drafts last changed before this moment are expired.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function expiredIn(Register $register, Schema $schema, DateTimeImmutable $cutoff): array {
		$expired = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$found = $this->objects->searchObjects(
					query: [
						'@self' => ['register' => (string)$register->getId(), 'schema' => (string)$schema->getId()],
						'_limit' => self::PAGE_SIZE,
						'_offset' => ($page * self::PAGE_SIZE),
						// Drafts alone, whoever owns them (decision 201's lens).
						DraftListLens::PARAMETER => 'true',
					],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					message: '[DraftExpiry] The drafts of a schema could not be listed',
					context: ['register' => $register->getSlug(), 'schema' => $schema->getSlug(), 'exception' => $exception->getMessage()]
				);
				break;
			}

			if (is_array($found) === false) {
				break;
			}

			foreach ($found as $candidate) {
				if ($this->isExpired(candidate: $candidate, cutoff: $cutoff) === true) {
					$expired[] = (string)$candidate->getUuid();
				}
			}

			if (count($found) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $expired;
	}//end expiredIn()

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
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 * @param string   $uuid     The draft.
	 *
	 * @return bool True when it was removed.
	 */
	private function remove(Register $register, Schema $schema, string $uuid): bool {
		try {
			// RBAC off: a background job has no session, and the lifetime the
			// schema declares is the authorisation. Everything else the delete
			// path guards (append-only, referential integrity, files) stays
			// on; the path itself makes a draft's delete permanent.
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
