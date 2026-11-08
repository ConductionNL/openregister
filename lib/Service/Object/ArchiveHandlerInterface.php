<?php

/**
 * The archive and freeze transitions the object-state endpoints run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The archive and freeze transitions the object-state endpoints run.
 *
 * Bound to ArchiveHandler in ContractBindings. Every method is described on ArchiveHandler.
 */
interface ArchiveHandlerInterface {

	/**
	 * Archive an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being archived.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archive(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array;

	/**
	 * Restore an object from the archive.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being restored.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchive(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array;

	/**
	 * Freeze an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being frozen.
	 * @param string|null $state The lifecycle state declaring the freeze, when one does.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(
		string $identifier,
		?string $reason = null,
		?string $state = null,
		?string $register = null,
		?string $schema = null,
	): array;

	/**
	 * Unfreeze an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being unfrozen.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreeze(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array;
}//end interface
