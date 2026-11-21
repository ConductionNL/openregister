<?php

/**
 * SchemaFieldTypes: the column type of every Schema field.
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

use OCP\DB\Types;

/**
 * The field => type map Schema registers with addType(), in registration order.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class SchemaFieldTypes {

	/**
	 * Each persisted field and its column type, in the order the entity registers them.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'uri' => 'string',
		'slug' => 'string',
		'title' => 'string',
		'description' => 'string',
		'version' => 'string',
		'summary' => 'string',
		'icon' => 'string',
		'required' => 'json',
		'properties' => 'json',
		'archive' => 'json',
		'facets' => 'json',
		'allOf' => 'json',
		'oneOf' => 'json',
		'anyOf' => 'json',
		'source' => 'string',
		'hardValidation' => Types::BOOLEAN,
		'immutable' => Types::BOOLEAN,
		'appendOnly' => Types::BOOLEAN,
		'searchable' => Types::BOOLEAN,
		'smartPickerEnabled' => Types::BOOLEAN,
		'updated' => 'datetime',
		'created' => 'datetime',
		'maxDepth' => Types::INTEGER,
		'owner' => 'string',
		'application' => 'string',
		'organisation' => 'string',
		'authorization' => 'json',
		'sharedWith' => 'json',
		'deleted' => 'datetime',
		'configuration' => 'json',
		'groups' => 'json',
		'hooks' => 'json',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
		'draft' => 'json',
		'formerSlugs' => 'json',
	];
}//end class
