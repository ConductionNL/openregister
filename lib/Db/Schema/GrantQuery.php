<?php

/**
 * GrantQuery: one question to Schema::hasPermission().
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
 * Whether a group may perform an action, asked by an actor about an object.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class GrantQuery {

	/**
	 * The group whose permission is asked.
	 *
	 * @var string
	 */
	public readonly string $groupId;

	/**
	 * The action asked for.
	 *
	 * @var string
	 */
	public readonly string $action;

	/**
	 * Who asks.
	 *
	 * @var GrantActor
	 */
	public readonly GrantActor $actor;

	/**
	 * What is asked about.
	 *
	 * @var GrantObject
	 */
	public readonly GrantObject $object;

	/**
	 * Constructor.
	 *
	 * @param string      $groupId The group whose permission is asked.
	 * @param string      $action  The action asked for.
	 * @param GrantActor  $actor   Who asks.
	 * @param GrantObject $object  What is asked about.
	 */
	public function __construct(string $groupId, string $action, GrantActor $actor, GrantObject $object) {
		$this->groupId = $groupId;
		$this->action = $action;
		$this->actor = $actor;
		$this->object = $object;
	}//end __construct()
}//end class
