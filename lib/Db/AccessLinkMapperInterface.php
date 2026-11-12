<?php

/**
 * AccessLinkMapperInterface: what callers outside the Db component use of AccessLinkMapper.
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
 * The methods of AccessLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to AccessLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface AccessLinkMapperInterface {

	/**
	 * Find a link by its random anchor.
	 *
	 * @param string $anchor The anchor from the URL.
	 *
	 * @return AccessLink|null The link row, or null when no link carries that anchor.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function findByAnchor(string $anchor): ?AccessLink;

	/**
	 * Every link one principal minted, newest first.
	 *
	 * Revoked and expired rows are included on purpose: an owner reviewing what
	 * they published needs to see what they published, not only what is still
	 * open.
	 *
	 * @param string $userId The minting principal.
	 *
	 * @return array<int, AccessLink> The link rows.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findByCreator(string $userId): array;

	/**
	 * Find a link by its row id.
	 *
	 * @param int $id The link row id.
	 *
	 * @return AccessLink|null The link row, or null when unknown.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function findById(int $id): ?AccessLink;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return AccessLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return AccessLink The updated row.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete one row.
	 *
	 * @param Entity $entity The row to delete.
	 *
	 * @return AccessLink The deleted row.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
