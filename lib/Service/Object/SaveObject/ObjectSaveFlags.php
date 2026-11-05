<?php

/**
 * OpenRegister ObjectSaveFlags
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\IUser;

/**
 * How one SaveObject::saveObject() call runs: rights, tenancy, persistence and ownership.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectSaveFlags {

	/**
	 * Whether RBAC checks apply.
	 *
	 * @var boolean
	 */
	public bool $rbac = true;

	/**
	 * Whether multitenancy filtering applies.
	 *
	 * @var boolean
	 */
	public bool $multitenancy = true;

	/**
	 * Whether the object is persisted.
	 *
	 * @var boolean
	 */
	public bool $persist = true;

	/**
	 * Whether audit trail and events are skipped.
	 *
	 * @var boolean
	 */
	public bool $silent = false;

	/**
	 * The explicit acting user, or null for the session user.
	 *
	 * @var IUser|null
	 */
	public ?IUser $currentUser = null;

	/**
	 * Whether an existing identifier refuses the write.
	 *
	 * @var boolean
	 */
	public bool $failIfExists = false;

	/**
	 * Whether the system identity is stamped even when a session exists.
	 *
	 * @var boolean
	 */
	public bool $unowned = false;

	/**
	 * Whether the request behind this write tried to name its own audit cause.
	 *
	 * @var boolean
	 */
	public bool $clientCauseAttempt = false;

	/**
	 * Whether the caller asks to save through a blocking duplicate match.
	 *
	 * @var boolean
	 */
	public bool $dedupOverride = false;

	/**
	 * An explicit owner for a new object.
	 *
	 * @var string|null
	 */
	public ?string $owner = null;
}//end class
