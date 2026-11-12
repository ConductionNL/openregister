<?php

/**
 * RbacPermissionCheck
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * One permission decision of {@see MagicRbacHandler::hasPermission()}: the
 * caller, the action and the object in play, and the rules resolved for them.
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class RbacPermissionCheck {

	/**
	 * The caller's user ID, null for an anonymous caller.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The caller's group IDs.
	 *
	 * @var string[]
	 */
	public array $userGroups = [];

	/**
	 * The object's data, null for a schema-level check.
	 *
	 * @var array|null
	 */
	public ?array $objectData = null;

	/**
	 * The object's own `_authorization` block.
	 *
	 * @var array|null
	 */
	public ?array $objectAuthorization = null;

	/**
	 * The object's UUID, for per-object grants.
	 *
	 * @var string|null
	 */
	public ?string $objectUuid = null;

	/**
	 * The cascaded schema/register authorization block.
	 *
	 * @var array|null
	 */
	public ?array $cascaded = null;

	/**
	 * Whether authenticated callers inherit the `public` rights.
	 *
	 * @var boolean
	 */
	public bool $inheritFromPublic = false;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema The schema checked.
	 * @param string $action The CRUD action checked.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Set the caller.
	 *
	 * @param string|null $userId     The caller's user ID.
	 * @param string[]    $userGroups The caller's group IDs.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function forCaller(?string $userId, array $userGroups): self {
		$this->userId     = $userId;
		$this->userGroups = $userGroups;
		return $this;
	}//end forCaller()

	/**
	 * Set the object in play.
	 *
	 * @param array|null $objectData          The object's data.
	 * @param array|null $objectAuthorization The object's own `_authorization` block.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function onObject(?array $objectData, ?array $objectAuthorization): self {
		$this->objectData          = $objectData;
		$this->objectAuthorization = $objectAuthorization;
		return $this;
	}//end onObject()

	/**
	 * Set the object's UUID.
	 *
	 * @param string|null $objectUuid The object's UUID.
	 *
	 * @return self This check.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function identifiedBy(?string $objectUuid): self {
		$this->objectUuid = $objectUuid;
		return $this;
	}//end identifiedBy()

	/**
	 * Whether the caller is an administrator.
	 *
	 * @return bool True when the caller is in the `admin` group.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function callerIsAdmin(): bool {
		return in_array('admin', $this->userGroups, true);
	}//end callerIsAdmin()

	/**
	 * Whether the caller owns the object.
	 *
	 * @param string|null $objectOwner The object's owner.
	 *
	 * @return bool True when a logged-in caller is the owner.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function callerOwns(?string $objectOwner): bool {
		return $this->userId !== null && $objectOwner !== null && $objectOwner === $this->userId;
	}//end callerOwns()
}//end class
