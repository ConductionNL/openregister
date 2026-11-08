<?php

/**
 * OpenRegister AuditRetentionResolver
 *
 * Derives the lifetime of an audit-trail row from the retention of the OBJECT
 * the row describes, replacing the unconditional `+30 days` that used to be
 * stamped on every row (or#2265).
 *
 * The old behaviour destroyed the evidence of a retention decision long before
 * the decision itself expired: on the procest case register, 311 of 330
 * soft-deleted cases had no surviving delete audit row, and every one of the
 * 258,240 rows carrying an expiry carried exactly a 30-day one. An audit trail
 * for a record kept 20 years under the Archiefwet cannot itself live 30 days.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the `expires` instant for an audit-trail row from the retention
 * policy of the object it describes.
 *
 * ## Policy
 *
 * The resolution order is most-specific-first. Every branch also records a
 * short machine-readable *source* token, which the caller stamps into the
 * row's `retention_period` column so that any future purge can be explained
 * from the row itself rather than from the code that happened to be deployed
 * on the day it was written.
 *
 * 1. `retention.legalHold.active === true` — INDEFINITE. A legal hold exists
 *    precisely to stop destruction; destroying the audit trail underneath one
 *    is the same defect one level down.
 * 2. `retention.archiefnominatie === 'bewaren'` — INDEFINITE. `bewaren` means
 *    permanent preservation with eventual transfer to an e-depot; the
 *    `archiefactiedatum` on such an object is the TRANSFER date, not a
 *    destruction date, so it must not be read as an expiry.
 * 3. `retention.archiefnominatie === 'nog_niet_bepaald'` — INDEFINITE. A
 *    record whose retention has not been determined cannot be destroyed, and
 *    neither can its audit trail. "Undetermined" must fail towards keeping.
 * 4. `retention.archiefactiedatum` — the object's own destruction date. Used
 *    verbatim (subject to the floor below).
 * 5. `retention.bewaartermijn` — an ISO-8601 duration on the object, added to
 *    the audit row's creation instant.
 * 6. The schema's `archive` block — `bewaartermijnOverride`, else
 *    `defaultBewaartermijn`, added to the row's creation instant.
 * 7. The schema's `x-openregister-archival` annotation — `retention.default`,
 *    added to the row's creation instant. This is the same annotation
 *    {@see \OCA\OpenRegister\Service\Archival\RetentionEvaluator} uses for
 *    row-level archival, so the audit row inherits the same horizon as the
 *    data it describes.
 * 8. Nothing configured — INDEFINITE.
 *
 * ## The default for objects with no retention policy
 *
 * Branch 8 returns `null`, and `null` in the `expires` column already means
 * "never purge": {@see \OCA\OpenRegister\Db\AuditTrailMapper::clearLogs()}
 * has always filtered on `expires IS NOT NULL`. That is a deliberate choice
 * and the reason it is safe: for a system holding Dutch government retention
 * data, silently discarding evidence is a worse failure than storing too much,
 * and any finite default re-creates or#2265 at a different horizon. The cost is
 * that the table now grows until a retention policy is configured — see the
 * storage note on the pull request. It is a bounded, visible, fixable cost;
 * destroyed evidence is none of those things.
 *
 * ## The floor
 *
 * {@see self::MINIMUM_LIFETIME} (30 days) is applied as a FLOOR, never a
 * ceiling. Without it an object already past its `archiefactiedatum` would
 * have the audit row describing the action just taken purged by the next
 * hourly cron run — the row would be born expired. The old constant survives
 * here with its sign reversed: it used to be the longest an audit row could
 * live, and is now the shortest.
 */
class AuditRetentionResolver implements AuditRetentionResolverInterface {

	/**
	 * Shortest lifetime any audit row may be given.
	 *
	 * Applied as a floor to every finite outcome. See the class docblock:
	 * an object already past its destruction date would otherwise produce
	 * audit rows that are expired the moment they are written.
	 *
	 * @var string
	 */
	public const MINIMUM_LIFETIME = 'P30D';

	/**
	 * Source token stamped on rows that are retained indefinitely because the
	 * object carries no retention policy at all.
	 *
	 * @var string
	 */
	public const SOURCE_NO_POLICY = 'no-retention-policy:indefinite';

	/**
	 * Container used to lazily resolve the schema mapper.
	 *
	 * Lazy on purpose: this resolver runs inside the audit write path, which
	 * is itself reached from mappers. Constructor-injecting SchemaMapper here
	 * risks a resolution cycle, and a schema that cannot be loaded must
	 * degrade to "no policy" rather than break the write.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Logger for unparseable durations and unloadable schemas.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Service container for lazy schema lookups.
	 * @param LoggerInterface $logger Logger for malformed retention configuration.
	 */
	public function __construct(ContainerInterface $container, LoggerInterface $logger) {
		$this->container = $container;
		$this->logger = $logger;

	}//end __construct()

	/**
	 * Resolve the audit-row expiry for an object.
	 *
	 * @param ObjectEntity $object The object the audit row describes.
	 * @param DateTimeInterface $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable|null, source: string} The
	 *                                                                 resolved expiry (null meaning "retain indefinitely") and the
	 *                                                                 machine-readable token explaining which branch produced it.
	 */
	public function resolve(ObjectEntity $object, DateTimeInterface $createdAt): array {
		// Defensive: the column is JSON, so a malformed row can yield a
		// non-array. Absent or unreadable retention must mean "no policy"
		// (indefinite), never a short default.
		$retention = $object->getRetention();
		if (is_array($retention) === false) {
			$retention = [];
		}

		// 1, 2 + 3. A legal hold, or a nomination that forbids destruction.
		$indefinite = $this->indefiniteSource(retention: $retention);
		if ($indefinite !== null) {
			return [
				'expires' => null,
				'source' => $indefinite,
			];
		}

		// 4. The object's own destruction date.
		$resolved = $this->fromActiedatum(actiedatum: ($retention['archiefactiedatum'] ?? null), createdAt: $createdAt);
		if ($resolved !== null) {
			return $resolved;
		}

		// 5. An ISO-8601 duration on the object.
		$resolved = $this->fromDuration(
			candidate: ['duration' => ($retention['bewaartermijn'] ?? null), 'source' => 'object.bewaartermijn'],
			createdAt: $createdAt
		);
		if ($resolved !== null) {
			return $resolved;
		}

		// 6 + 7. Schema-level configuration.
		return $this->fromSchema(object: $object, createdAt: $createdAt);
	}//end resolve()

	/**
	 * The source token of a retention that keeps the row indefinitely, or null.
	 *
	 * A legal hold beats every other consideration; `bewaren` and
	 * `nog_niet_bepaald` both forbid destruction.
	 *
	 * @param array<string, mixed> $retention The object's retention metadata.
	 *
	 * @return string|null The source token, or null when the retention may end.
	 */
	private function indefiniteSource(array $retention): ?string {
		$legalHold = ($retention['legalHold'] ?? null);
		if (is_array($legalHold) === true && ($legalHold['active'] ?? false) === true) {
			return 'legal-hold:indefinite';
		}

		return match ($retention['archiefnominatie'] ?? null) {
			'bewaren' => 'archiefnominatie=bewaren:indefinite',
			'nog_niet_bepaald' => 'archiefnominatie=nog_niet_bepaald:indefinite',
			default => null,
		};
	}//end indefiniteSource()

	/**
	 * A floored expiry from the object's own destruction date, or null when absent or unparseable.
	 *
	 * @param mixed             $actiedatum The candidate `archiefactiedatum`.
	 * @param DateTimeInterface $createdAt  The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable, source: string}|null
	 */
	private function fromActiedatum(mixed $actiedatum, DateTimeInterface $createdAt): ?array {
		if (is_string($actiedatum) === false || $actiedatum === '') {
			return null;
		}

		$parsed = $this->parseDate(value: $actiedatum);
		if ($parsed === null) {
			return null;
		}

		return $this->withFloor(
			resolved: ['expires' => $parsed, 'source' => 'object.archiefactiedatum'],
			createdAt: $createdAt
		);
	}//end fromActiedatum()

	/**
	 * Resolve the expiry from the schema's archive block or archival annotation.
	 *
	 * @param ObjectEntity $object The object whose schema is consulted.
	 * @param DateTimeInterface $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable|null, source: string}
	 */
	private function fromSchema(ObjectEntity $object, DateTimeInterface $createdAt): array {
		$schema = $this->loadSchema(schemaId: $object->getSchema());
		if ($schema === null) {
			return [
				'expires' => null,
				'source' => self::SOURCE_NO_POLICY,
			];
		}

		// 6 + 7, in order: the first parseable duration wins.
		foreach ($this->schemaCandidates(schema: $schema) as $candidate) {
			$resolved = $this->fromDuration(candidate: $candidate, createdAt: $createdAt);
			if ($resolved !== null) {
				return $resolved;
			}
		}

		// 8. No policy anywhere: retain indefinitely. See the class docblock.
		return [
			'expires' => null,
			'source' => self::SOURCE_NO_POLICY,
		];

	}//end fromSchema()

	/**
	 * The schema's retention durations, most specific first, each with its source token.
	 *
	 * 6. The `archive` block written by RetentionService::applyArchivalMetadata()
	 * (`bewaartermijnOverride`, then `defaultBewaartermijn`); 7. the
	 * `x-openregister-archival` annotation used by RetentionEvaluator.
	 *
	 * @param \OCA\OpenRegister\Db\Schema $schema The object's schema.
	 *
	 * @return array<int, array{duration: mixed, source: string}>
	 */
	private function schemaCandidates($schema): array {
		$candidates = [];
		$archive = $schema->getArchive();
		if (is_array($archive) === true) {
			$candidates[] = ['duration' => ($archive['bewaartermijnOverride'] ?? null), 'source' => 'schema.archive.bewaartermijnOverride'];
			$candidates[] = ['duration' => ($archive['defaultBewaartermijn'] ?? null), 'source' => 'schema.archive.defaultBewaartermijn'];
		}

		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === true) {
			$candidates[] = [
				'duration' => ($configuration['x-openregister-archival']['retention']['default'] ?? null),
				'source' => 'schema.x-openregister-archival.retention.default',
			];
		}

		return $candidates;
	}//end schemaCandidates()

	/**
	 * Build a floored expiry from an ISO-8601 duration, or null when the value
	 * is absent or unparseable.
	 *
	 * @param array{duration: mixed, source: string} $candidate The candidate duration and the token naming where it came from.
	 * @param DateTimeInterface                      $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable|null, source: string}|null
	 */
	private function fromDuration(array $candidate, DateTimeInterface $createdAt): ?array {
		$duration = $candidate['duration'];
		if (is_string($duration) === false || $duration === '') {
			return null;
		}

		try {
			$interval = new DateInterval($duration);
		} catch (Throwable $error) {
			$this->logger->warning(
				'[AuditRetentionResolver] Unparseable retention duration "' . $duration . '" from ' . $candidate['source']
				. '; falling through to the next source.',
				['exception' => $error]
			);
			return null;
		}

		$expires = DateTimeImmutable::createFromInterface($createdAt)->add($interval);

		return $this->withFloor(resolved: ['expires' => $expires, 'source' => $candidate['source']], createdAt: $createdAt);
	}//end fromDuration()

	/**
	 * Apply {@see self::MINIMUM_LIFETIME} as a floor to a resolved expiry.
	 *
	 * @param array{expires: DateTimeImmutable, source: string} $resolved  The candidate expiry and its source token.
	 * @param DateTimeInterface                                 $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTimeImmutable, source: string} The
	 *                                                            floored expiry; the source token gains a `+floor` suffix when the floor
	 *                                                            actually bound, so the row records that it outlived its object.
	 */
	private function withFloor(array $resolved, DateTimeInterface $createdAt): array {
		$floor = DateTimeImmutable::createFromInterface($createdAt)->add(new DateInterval(self::MINIMUM_LIFETIME));

		if ($resolved['expires'] < $floor) {
			return [
				'expires' => $floor,
				'source' => $resolved['source'] . '+floor',
			];
		}

		return [
			'expires' => $resolved['expires'],
			'source' => $resolved['source'],
		];

	}//end withFloor()

	/**
	 * Parse a date string from the retention metadata.
	 *
	 * @param string $value The candidate date string (ISO-8601 date or datetime).
	 *
	 * @return DateTimeImmutable|null The parsed instant, or null when unparseable.
	 */
	private function parseDate(string $value): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $error) {
			$this->logger->warning(
				'[AuditRetentionResolver] Unparseable archiefactiedatum "' . $value . '".',
				['exception' => $error]
			);
			return null;
		}

	}//end parseDate()

	/**
	 * Load a schema by id, returning null when it cannot be resolved.
	 *
	 * RBAC and multitenancy are bypassed for the same reason the sibling
	 * `resolveProcessingActivityId()` bypasses them: this runs inside the
	 * audit writer, which is not a request-scoped read of the schema and must
	 * not depend on the acting user being able to see it.
	 *
	 * @param string|int|null $schemaId Schema identifier (numeric id or uuid).
	 *
	 * @return \OCA\OpenRegister\Db\Schema|null The schema, or null.
	 */
	private function loadSchema($schemaId) {
		if ($schemaId === null || $schemaId === '' || $schemaId === 0) {
			return null;
		}

		try {
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapperInterface::class);

			return $schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $error) {
			return null;
		}

	}//end loadSchema()
}//end class
