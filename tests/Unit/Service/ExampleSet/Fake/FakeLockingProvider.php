<?php

/**
 * In-memory locks for the example-set tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Exclusive locks by path; a second acquire of a held path throws, as another process would see it.
 */
class FakeLockingProvider implements ILockingProvider {
	/** @var array<string, int> Held locks: path to type. */
	public array $held = [];

	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}

	public function acquireLock(string $path, int $type, ?string $readablePath=null): void {
		if (isset($this->held[$path]) === true) {
			throw new LockedException($path);
		}

		$this->held[$path] = $type;
	}

	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}

	public function changeLock(string $path, int $targetType): void {
		$this->held[$path] = $targetType;
	}

	public function releaseAll(): void {
		$this->held = [];
	}
}
