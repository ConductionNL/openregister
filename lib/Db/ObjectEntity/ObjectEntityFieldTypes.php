<?php

/**
 * OpenRegister ObjectEntityFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

/**
 * The column types of an object, in registration order.
 *
 * @spec exclude an object plumbing moved out of the entity, not a capability of its own
 */
final class ObjectEntityFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'slug' => 'string',
		'uri' => 'string',
		'version' => 'string',
		'register' => 'string',
		'schema' => 'string',
		'object' => 'json',
		'files' => 'json',
		'relations' => 'json',
		'locked' => 'json',
		'owner' => 'string',
		'authorization' => 'json',
		'folder' => 'string',
		'application' => 'string',
		'organisation' => 'string',
		'validation' => 'json',
		'quality' => 'json',
		'deleted' => 'json',
		'archived' => 'json',
		'frozen' => 'json',
		'status' => 'string',
		'geo' => 'json',
		'retention' => 'json',
		'tmlo' => 'json',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
		'size' => 'string',
		'schemaVersion' => 'string',
		'name' => 'string',
		'description' => 'string',
		'summary' => 'string',
		'image' => 'string',
		'updated' => 'datetime',
		'lastUserActivity' => 'datetime',
		'created' => 'datetime',
		'groups' => 'json',
		'expires' => 'datetime',
	];
}//end class
