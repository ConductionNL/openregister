<?php

/**
 * SaveObjectRelatedUpdateGuardInterface: the related-object update check of SaveObject.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The related-update role of SaveObjectInterface, for callers that write into another stored object.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
interface SaveObjectRelatedUpdateGuardInterface {

	/**
	 * Refuse a write to a related object the caller may not update.
	 *
	 * A save that writes into another, already stored object needs the right to
	 * update that object. Skipped only when an enclosing save runs with RBAC off.
	 *
	 * @param ObjectEntity $target The stored object about to be written.
	 *
	 * @return void
	 *
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller may not update it.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertMayUpdateRelated(ObjectEntity $target): void;
}//end interface
