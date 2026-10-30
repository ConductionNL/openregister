<?php

/**
 * OpenRegister Example Set Lock
 *
 * One install or removal of a set at a time. A run reads the record once and
 * writes it whole after every step, so two runs at once each write their own
 * copy and the later writer drops what the other recorded: its objects stay
 * on the instance, unrecorded, and the next run collides with them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * An exclusive lock per set, held for the length of an install or a removal.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
 */
class ExampleSetLock {
	/**
	 * Constructor.
	 *
	 * @param ILockingProvider $locks Nextcloud's locking provider, shared by every process of the instance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ILockingProvider $locks,
	) {
	}//end __construct()

	/**
	 * The lock path of a set.
	 *
	 * @param string $set The set id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
	 */
	public static function path(string $set): string {
		return 'openregister/example-set/'.$set;
	}//end path()

	/**
	 * What a run says when another run holds the set.
	 *
	 * @param string $set The set id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
	 */
	public function busy(string $set): string {
		return 'another install or removal of set "'.$set.'" is running, so this one wrote nothing.'
			.' Run it again when that one has ended; a run that was killed holds the set until its lock expires.';
	}//end busy()

	/**
	 * Take the set for this run.
	 *
	 * @param string $set The set id.
	 *
	 * @return bool True when this run holds the set; false when another run does.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
	 */
	public function hold(string $set): bool {
		try {
			$this->locks->acquireLock(self::path(set: $set), ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return false;
		}

		return true;
	}//end hold()

	/**
	 * Let go of the set.
	 *
	 * @param string $set The set id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
	 */
	public function release(string $set): void {
		$this->locks->releaseLock(self::path(set: $set), ILockingProvider::LOCK_EXCLUSIVE);
	}//end release()
}//end class
