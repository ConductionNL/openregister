<?php

/**
 * OpenRegister DeferredListenerContext
 *
 * Serializable acting-context snapshot for actor-forwarded listener jobs.
 * Captures WHO triggered a deferred side effect (session user id and active
 * organisation uuid at dispatch time) together with the per-object entries
 * the background job must process. Round-trips loss-free through the
 * JSON-serialized `oc_jobs.argument` column via toJobArguments() /
 * fromJobArguments().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
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

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Value object carrying the captured acting context of a deferred listener.
 *
 * Entries are small associative arrays holding object identifiers (uuid,
 * register, schema, version) plus the minimal per-entry payload a listener
 * semantically requires and cannot re-fetch later (e.g. the pre-update data
 * snapshot for `updated` notification conditions). Full objects are never
 * serialized — jobs re-fetch current state by identifier.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class DeferredListenerContext {
	/**
	 * Wrap a captured acting context.
	 *
	 * @param string|null $userId Acting user id at dispatch time (null = session-less origin).
	 * @param string|null $orgUuid Active organisation uuid at dispatch time (drift detection only).
	 * @param array<int, array<string, mixed>> $entries Per-object entries for the job to process.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $userId,
		private readonly ?string $orgUuid,
		private readonly array $entries,
	) {
	}//end __construct()

	/**
	 * The acting user id captured at dispatch time.
	 *
	 * @return string|null User id, or null when the write had no session user.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * The active organisation uuid captured at dispatch time.
	 *
	 * @return string|null Organisation uuid, or null when none was resolvable.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getOrganisationUuid(): ?string {
		return $this->orgUuid;
	}//end getOrganisationUuid()

	/**
	 * The per-object entries the job must process.
	 *
	 * @return array<int, array<string, mixed>> Entry arrays (uuid/register/schema/version + payload).
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function getEntries(): array {
		return $this->entries;
	}//end getEntries()

	/**
	 * Serialize to the array shape stored in `oc_jobs.argument`.
	 *
	 * @return array<string, mixed> JSON-serializable job arguments.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function toJobArguments(): array {
		return [
			'userId' => $this->userId,
			'organisationUuid' => $this->orgUuid,
			'entries' => array_values($this->entries),
		];
	}//end toJobArguments()

	/**
	 * Rebuild a context from a job's argument payload.
	 *
	 * Tolerant of malformed input (non-array argument, missing keys,
	 * non-array entries): invalid pieces are dropped rather than thrown on,
	 * so a poisoned job row degrades to a logged no-op instead of a crash
	 * loop in cron.
	 *
	 * @param mixed $argument Raw job argument as delivered by the job list.
	 *
	 * @return self Reconstructed context (possibly with zero entries).
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public static function fromJobArguments(mixed $argument): self {
		if (is_array($argument) === false) {
			return new self(userId: null, orgUuid: null, entries: []);
		}

		return new self(
			userId: self::nonEmptyString(value: ($argument['userId'] ?? null)),
			orgUuid: self::nonEmptyString(value: ($argument['organisationUuid'] ?? null)),
			entries: self::arrayEntries(list: ($argument['entries'] ?? []))
		);
	}//end fromJobArguments()

	/**
	 * A job-argument value as a non-empty string, or null.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string|null The string, or null when it is not one or is empty.
	 */
	private static function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()

	/**
	 * The entries of a job-argument list that are arrays, in order; none when it is not a list.
	 *
	 * @param mixed $list The decoded entry list.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private static function arrayEntries(mixed $list): array {
		if (is_array($list) === false) {
			return [];
		}

		$entries = [];
		foreach ($list as $rawEntry) {
			if (is_array($rawEntry) === true) {
				$entries[] = $rawEntry;
			}
		}

		return $entries;
	}//end arrayEntries()
}//end class
