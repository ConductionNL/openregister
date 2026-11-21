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

use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\WriteCause;

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
	 * @param CauseFrame|null $cause The write cause at deferral time, when it was not a person acting directly
	 *                              (runs-recorded-and-causes-named REQ-RCN-002); null keeps the job a person's.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $userId,
		private readonly ?string $orgUuid,
		private readonly array $entries,
		private readonly ?CauseFrame $cause = null,
	) {
	}//end __construct()

	/**
	 * The write cause captured at deferral time, or null for a person acting directly.
	 *
	 * @return CauseFrame|null The cause and its run.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
	 */
	public function getCause(): ?CauseFrame {
		return $this->cause;
	}//end getCause()

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
		$arguments = [
			'userId' => $this->userId,
			'organisationUuid' => $this->orgUuid,
			'entries' => array_values($this->entries),
		];

		// Only a cause other than a person's is written, so a job argument for
		// a person's write is byte-for-byte what it was before causes travelled.
		if ($this->cause !== null) {
			$arguments['cause'] = $this->cause->cause;
			$arguments['causeRun'] = $this->cause->run;
		}

		return $arguments;
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
			entries: self::arrayEntries(list: ($argument['entries'] ?? [])),
			cause: self::causeOf(argument: $argument)
		);
	}//end fromJobArguments()

	/**
	 * The cause a job argument carries, or null when it carries none or a person's.
	 *
	 * The word is normalised against the closed vocabulary: an unknown word
	 * reads as a person's, never as a cause somebody could filter away.
	 *
	 * @param array<string, mixed> $argument The decoded job argument.
	 *
	 * @return CauseFrame|null The cause and its run.
	 */
	private static function causeOf(array $argument): ?CauseFrame {
		$word = self::nonEmptyString(value: ($argument['cause'] ?? null));
		if ($word === null) {
			return null;
		}

		$cause = WriteCause::normalise(cause: $word);
		if ($cause === WriteCause::PERSON) {
			return null;
		}

		return new CauseFrame(cause: $cause, run: self::nonEmptyString(value: ($argument['causeRun'] ?? null)));
	}//end causeOf()

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
