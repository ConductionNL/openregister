<?php

/**
 * OpenRegister ApplicationFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Application
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

namespace OCA\OpenRegister\Db\Application;

/**
 * The column types of a application, in registration order.
 *
 * @spec exclude application plumbing moved out of the entity, not a capability of its own
 */
final class ApplicationFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'name' => 'string',
		'description' => 'string',
		'version' => 'string',
		'organisation' => 'string',
		'configurations' => 'json',
		'registers' => 'json',
		'schemas' => 'json',
		'owner' => 'string',
		'active' => 'boolean',
		// Field types are keyed by PROPERTY name, not column name: Entity::setter()
		// looks up $_fieldTypes[$property], so a snake_case key silently skips the
		// cast and lets a BIGINT column come back from the driver as a string.
		'storageQuota' => 'integer',
		'bandwidthQuota' => 'integer',
		'requestQuota' => 'integer',
		'userQuota' => 'integer',
		'groupQuota' => 'integer',
		'groups' => 'json',
		'authorization' => 'json',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
