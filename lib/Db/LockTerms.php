<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * What a caller asks for when it takes an object lock: the process, how long, and the holding run.
 *
 * A run uuid that is blank after trimming takes the user lock, exactly as a missing one does.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
 */
final class LockTerms {

	/**
	 * Constructor.
	 *
	 * @param string|null $process  Optional process identifier.
	 * @param int|null    $duration Lock duration in seconds; null holds until released.
	 * @param string|null $runUuid  Flow run taking the lock, for a run-scoped lock.
	 */
	public function __construct(
		public readonly ?string $process=null,
		public readonly ?int $duration=3600,
		public readonly ?string $runUuid=null,
	) {
	}//end __construct()

	/**
	 * The lock payload for a holder, before any expiration, in stored key order.
	 *
	 * @param string   $userId The holder's user id.
	 * @param DateTime $now    The moment the lock is taken.
	 *
	 * @return array{kind: string, runUuid: ?string, user: string, process: ?string, created: string, duration: ?int}
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function payloadFor(string $userId, DateTime $now): array {
		[$kind, $runUuid] = $this->holder();

		return [
			'kind' => $kind,
			'runUuid' => $runUuid,
			'user' => $userId,
			'process' => $this->process,
			'created' => $now->format('c'),
			'duration' => $this->duration,
		];
	}//end payloadFor()

	/**
	 * Who holds the lock: a run, by its trimmed uuid, when one is given; otherwise the user.
	 *
	 * A blank run uuid leaves a user lock and is carried as given.
	 *
	 * @return array{0: string, 1: string|null} The lock kind and the run uuid.
	 */
	private function holder(): array {
		$runUuid = $this->runUuid;
		if ($runUuid !== null && trim($runUuid) !== '') {
			return [ObjectEntity::LOCK_KIND_RUN, trim($runUuid)];
		}

		return [ObjectEntity::LOCK_KIND_USER, $runUuid];
	}//end holder()
}//end class
