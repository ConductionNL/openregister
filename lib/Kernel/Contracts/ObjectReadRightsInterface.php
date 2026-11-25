<?php
/**
 * ObjectReadRightsInterface: whether a given user may read one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The object-level `read` check for a named user, for an area that tells people about objects.
 *
 * Core asks its schema-level permission check for `read`, as that user, with
 * the object's owner and RBAC on. Notifications asks it per recipient so a
 * notification only reaches people who may read the object.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
 */
interface ObjectReadRightsInterface {

	/**
	 * Whether the user may read the object.
	 *
	 * @param string                $userId The user the check is for.
	 * @param ObjectEntityInterface $object The object.
	 * @param SchemaInterface       $schema The object's schema.
	 *
	 * @return bool True when the user holds `read` on the object.
	 *
	 * @throws \Throwable When the check cannot be made.
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
	 */
	public function mayRead(string $userId, ObjectEntityInterface $object, SchemaInterface $schema): bool;
}//end interface
