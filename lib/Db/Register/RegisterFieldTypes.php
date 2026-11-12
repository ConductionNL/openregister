<?php

/**
 * OpenRegister RegisterFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Register;

/**
 * The column types of a register, in registration order.
 *
 * @spec exclude register plumbing moved out of the entity, not a capability of its own
 */
final class RegisterFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'slug' => 'string',
		'title' => 'string',
		'version' => 'string',
		'description' => 'string',
		'schemas' => 'json',
		'source' => 'string',
		'tablePrefix' => 'string',
		'folder' => 'string',
		'updated' => 'datetime',
		'created' => 'datetime',
		'owner' => 'string',
		'application' => 'string',
		'organisation' => 'string',
		'authorization' => 'json',
		'sharedWith' => 'json',
		'groups' => 'json',
		'deleted' => 'datetime',
		'type' => 'string',
		'languages' => 'json',
		'configuration' => 'json',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
	];
}//end class
