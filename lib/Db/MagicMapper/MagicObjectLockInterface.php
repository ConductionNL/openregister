<?php

/**
 * MagicObjectLockInterface: Locks and unlocks objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Locks and unlocks objects.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicObjectLockInterface {
	/**
	 * Lock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to lock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $lockDuration Lock duration in seconds (null for default).
	 * @param string|null $process What the lock was taken for.
	 * @param string|null $runUuid Flow run taking the lock, for a run-scoped lock.
	 *
	 * @throws Exception If locking fails.
	 *
	 * @return ObjectEntity The locked object entity.
	 */
	public function lockObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, ?int $lockDuration = null, ?string $process = null,
		?string $runUuid = null
	): ObjectEntity;

	/**
	 * Unlock object in register+schema table.
	 *
	 * @param ObjectEntity $entity The object entity to unlock.
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param string|null $runUuid Flow run releasing the lock, for a run-scoped lock.
	 * @param bool $break Release regardless of holder (administrator break-lock).
	 *
	 * @throws Exception If unlocking fails.
	 *
	 * @return ObjectEntity The unlocked object entity.
	 */
	public function unlockObjectEntity(
		ObjectEntity $entity, Register $register, Schema $schema, ?string $runUuid = null, bool $break = false
	): ObjectEntity;

	/**
	 * Lock an object.
	 *
	 * @param string $uuid The object UUID
	 * @param int|null $lockDuration Lock duration in seconds
	 *
	 * @return array Lock result.
	 *
	 * @psalm-return array{locked: mixed, uuid: string}
	 */
	public function lockObject(string $uuid, ?int $lockDuration = null): array;

	/**
	 * Unlock an object.
	 *
	 * @param string $uuid The object UUID
	 *
	 * @return bool True on success
	 */
	public function unlockObject(string $uuid): bool;
}//end interface
