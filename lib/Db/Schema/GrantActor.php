<?php

/**
 * GrantActor: who asks Schema::hasPermission().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;


/**
 * The asking user, the group the caller resolved for them, and their active organisation.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class GrantActor {

	/**
	 * The asking user's id, for the owner check.
	 *
	 * @var string|null
	 */
	public readonly ?string $userId;

	/**
	 * The asking user's group, for the admin check.
	 *
	 * @var string|null
	 */
	public readonly ?string $userGroup;

	/**
	 * The user's active organisation, the value of `$organisation` in a match.
	 *
	 * @var string|null
	 */
	public readonly ?string $activeOrganisation;

	/**
	 * Constructor.
	 *
	 * @param string|null $userId             The asking user's id.
	 * @param string|null $userGroup          The asking user's group.
	 * @param string|null $activeOrganisation The user's active organisation.
	 */
	public function __construct(?string $userId, ?string $userGroup, ?string $activeOrganisation) {
		$this->userId = $userId;
		$this->userGroup = $userGroup;
		$this->activeOrganisation = $activeOrganisation;
	}//end __construct()
}//end class
