<?php

/**
 * ExportProfileMapperInterface: what callers outside the Db component use of ExportProfileMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of ExportProfileMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to ExportProfileMapper. Guarded by F05InterfaceBindingsTest.
 */
interface ExportProfileMapperInterface {

	/**
	 * One profile by id.
	 *
	 * @param int $id The profile id.
	 *
	 * @return ExportProfile The profile.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one row matches.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function find(int $id): ExportProfile;

	/**
	 * The profiles one user owns.
	 *
	 * @param string $owner The owner's user id.
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function findByOwner(string $owner): array;

	/**
	 * Every profile.
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function findAll(): array;

	/**
	 * Insert one profile.
	 *
	 * @param Entity $entity The profile.
	 *
	 * @return ExportProfile The inserted profile.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one profile.
	 *
	 * @param Entity $entity The profile.
	 *
	 * @return ExportProfile The updated profile.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete one profile.
	 *
	 * @param Entity $entity The profile.
	 *
	 * @return ExportProfile The deleted profile.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function delete(Entity $entity): Entity;
}//end interface
