<?php

/**
 * GrantObject: the object Schema::hasPermission() is asked about.
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
 * The object's owner, its data and its organisation (`@self.organisation`).
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class GrantObject {

	/**
	 * The object's owner.
	 *
	 * @var string|null
	 */
	public readonly ?string $owner;

	/**
	 * The object's data, which a conditional rule matches against.
	 *
	 * @var array|null
	 */
	public readonly ?array $data;

	/**
	 * The object's organisation, matched by the `_organisation` field.
	 *
	 * @var string|null
	 */
	public readonly ?string $organisation;

	/**
	 * Constructor.
	 *
	 * @param string|null $owner        The object's owner.
	 * @param array|null  $data         The object's data.
	 * @param string|null $organisation The object's organisation.
	 */
	public function __construct(?string $owner, ?array $data, ?string $organisation) {
		$this->owner = $owner;
		$this->data = $data;
		$this->organisation = $organisation;
	}//end __construct()
}//end class
