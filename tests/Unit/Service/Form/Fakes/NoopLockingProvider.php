<?php

/**
 * A locking provider that reserves nothing, like the one an instance without a locking cache runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form\Fakes;

use OCP\Lock\ILockingProvider;

/**
 * Every call succeeds and holds nothing.
 */
class NoopLockingProvider implements ILockingProvider {

	/**
	 * Never locked.
	 *
	 * @param string $path The path.
	 * @param int    $type The lock type.
	 */
	public function isLocked(string $path, int $type): bool {
		return false;
	}//end isLocked()

	/**
	 * Takes nothing.
	 *
	 * @param string      $path         The path.
	 * @param int         $type         The lock type.
	 * @param string|null $readablePath The readable path.
	 */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
	}//end acquireLock()

	/**
	 * Releases nothing.
	 *
	 * @param string $path The path.
	 * @param int    $type The lock type.
	 */
	public function releaseLock(string $path, int $type): void {
	}//end releaseLock()

	/**
	 * Changes nothing.
	 *
	 * @param string $path       The path.
	 * @param int    $targetType The type.
	 */
	public function changeLock(string $path, int $targetType): void {
	}//end changeLock()

	/**
	 * Releases nothing.
	 */
	public function releaseAll(): void {
	}//end releaseAll()
}//end class
