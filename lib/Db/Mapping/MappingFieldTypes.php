<?php

/**
 * OpenRegister MappingFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Mapping
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

namespace OCA\OpenRegister\Db\Mapping;

/**
 * The column types of a mapping, in registration order.
 *
 * @spec exclude mapping plumbing moved out of the entity, not a capability of its own
 */
final class MappingFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'reference' => 'string',
		'version' => 'string',
		'name' => 'string',
		'description' => 'string',
		'mapping' => 'json',
		'unset' => 'json',
		'cast' => 'json',
		'passThrough' => 'boolean',
		'configurations' => 'json',
		'slug' => 'string',
		'organisation' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
