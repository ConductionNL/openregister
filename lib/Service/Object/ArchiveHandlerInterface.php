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
	 * Archive the object a request names.
	 *
	 * @param ObjectStateRequest $request The object (id, uuid, slug or uri), the register and schema
	 *                                    the url names (so an object in another one is not reachable
	 *                                    from this address) and why it is being archived.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archiveWith(ObjectStateRequest $request): array;

	/**
	 * Take the object a request names out of the archive.
	 *
	 * @param ObjectStateRequest $request The object (id, uuid, slug or uri), the register and schema
	 *                                    the url names (so an object in another one is not reachable
	 *                                    from this address) and why it is being restored.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchiveWith(ObjectStateRequest $request): array;

	/**
	 * Freeze the object a request names.
	 *
	 * @param ObjectStateRequest $request The object (id, uuid, slug or uri), the register and schema
	 *                                    the url names (so an object in another one is not reachable
	 *                                    from this address) and why it is being frozen.
	 * @param string|null        $state   The lifecycle state declaring the freeze, when one does.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(ObjectStateRequest $request, ?string $state = null): array;

	/**
	 * Unfreeze the object a request names.
	 *
	 * @param ObjectStateRequest $request The object (id, uuid, slug or uri), the register and schema
	 *                                    the url names (so an object in another one is not reachable
	 *                                    from this address) and why it is being unfrozen.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreezeWith(ObjectStateRequest $request): array;
}//end interface
