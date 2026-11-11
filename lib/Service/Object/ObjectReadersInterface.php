<?php

/**
 * ObjectReadersInterface: Lists the users authorised to read an object.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The reader fan-out of PermissionHandler.
 *
 * Callers that need only this role depend on it, not on the whole handler.
 */
interface ObjectReadersInterface {

	/**
	 * Return the list of user IDs authorised to read a given object.
	 *
	 * This is used by NotifyPushListener to fan out per-user push events without
	 * iterating every Nextcloud user with a per-user permission check. The approach
	 * is query-based:
	 *
	 * 1. Resolve the effective authorization for the object's schema.
	 * 2. Extract the groups that have `read` permission.
	 * 3. Fetch member user IDs for each group via IGroupManager (one DB call per group).
	 * 4. Return the deduplicated union.
	 *
	 * Special cases:
	 *   - No authorization configured → empty array (caller should treat as "all users"
	 *     and broadcast or skip push, depending on policy).
	 *   - Authorization exists but object owner is known → always include the owner.
	 *   - `public` group in the read list → empty array (caller should treat as broadcast).
	 *   - `admin` group in the read list → empty array (caller should treat as broadcast
	 *     or resolve admin members separately).
	 *
	 * @param ObjectEntity $object The object whose authorised readers are requested.
	 *
	 * @return array<string> Deduplicated list of user IDs, or empty array when the
	 *                       object is publicly readable (no restriction) or the schema
	 *                       cannot be resolved.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-3
	 */
	public function getReadableByUsers(ObjectEntity $object): array;
}//end interface
