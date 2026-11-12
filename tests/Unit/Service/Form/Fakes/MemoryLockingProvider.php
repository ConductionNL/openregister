<?php

/**
 * An in-memory locking provider, so the form stores are tested against a real lock that can be held.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form\Fakes;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive locks by path; a second acquire of a held path is a LockedException.
 */
class MemoryLockingProvider implements ILockingProvider {

	/**
	 * Paths held exclusively.
	 *
	 * @var array<string, bool>
	 */
	public array $held = [];

	/**
	 * Whether a path is locked.
	 *
	 * @param string $path The path.
	 * @param int    $type The lock type.
	 */
	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}//end isLocked()

	/**
	 * Take a lock, or fail when it is held.
	 *
	 * @param string      $path         The path.
	 * @param int         $type         The lock type.
	 * @param string|null $readablePath The readable path.
	 */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		if (isset($this->held[$path]) === true) {
			throw new LockedException($path);
		}

		$this->held[$path] = true;
	}//end acquireLock()

	/**
	 * Release a lock.
	 *
	 * @param string $path The path.
	 * @param int    $type The lock type.
	 */
	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}//end releaseLock()

	/**
	 * Change a lock's type (unused).
	 *
	 * @param string $path       The path.
	 * @param int    $targetType The type.
	 */
	public function changeLock(string $path, int $targetType): void {
	}//end changeLock()

	/**
	 * Release every lock.
	 */
	public function releaseAll(): void {
		$this->held = [];
	}//end releaseAll()
}//end class
