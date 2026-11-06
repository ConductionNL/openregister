<?php

/**
 * MagicMetadataColumns: the fixed columns of every magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The metadata columns every magic table carries.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicMetadataColumns {
	/**
	 * Column definitions of the metadata columns, keyed by column name.
	 */
	public const ALL = [
		'_id' => [
			'name' => '_id',
			'type' => 'bigint',
			'nullable' => false,
			'autoincrement' => true,
			'primary' => true,
		],
		'_uuid' => [
			'name' => '_uuid',
			'type' => 'string',
			'length' => 40,
			// ArchiMate identifiers are max 39 chars (id-{uuid-36}).
			'nullable' => false,
			'unique' => true,
			'index' => true,
		],
		'_slug' => [
			'name' => '_slug',
			'type' => 'string',
			'length' => 255,
			'nullable' => true,
			'index' => true,
		],
		'_uri' => [
			'name' => '_uri',
			'type' => 'text',
			'nullable' => true,
		],
		'_version' => [
			'name' => '_version',
			'type' => 'string',
			'length' => 50,
			'nullable' => true,
		],
		'_register' => [
			'name' => '_register',
			'type' => 'string',
			'length' => 255,
			'nullable' => false,
			'index' => true,
		],
		'_schema' => [
			'name' => '_schema',
			'type' => 'string',
			'length' => 255,
			'nullable' => false,
			'index' => true,
		],
		'_owner' => [
			'name' => '_owner',
			'type' => 'string',
			'length' => 64,
			'nullable' => true,
			'index' => true,
		],
		'_organisation' => [
			'name' => '_organisation',
			'type' => 'string',
			'length' => 36,
			'nullable' => true,
			'index' => true,
		],
		'_application' => [
			'name' => '_application',
			'type' => 'string',
			'length' => 255,
			'nullable' => true,
		],
		'_folder' => [
			'name' => '_folder',
			'type' => 'string',
			'length' => 255,
			'nullable' => true,
		],
		'_name' => [
			'name' => '_name',
			'type' => 'string',
			'length' => 255,
			'nullable' => true,
			'index' => true,
		],
		'_description' => [
			'name' => '_description',
			'type' => 'text',
			'nullable' => true,
		],
		'_summary' => [
			'name' => '_summary',
			'type' => 'text',
			// Changed from varchar(500) to text to support longer summaries.
			'nullable' => true,
		],
		'_image' => [
			'name' => '_image',
			'type' => 'text',
			'nullable' => true,
		],
		'_size' => [
			'name' => '_size',
			'type' => 'string',
			'length' => 50,
			'nullable' => true,
		],
		'_schema_version' => [
			'name' => '_schema_version',
			'type' => 'string',
			'length' => 50,
			'nullable' => true,
		],
		'_created' => [
			'name' => '_created',
			'type' => 'datetime',
			'nullable' => true,
			'index' => true,
		],
		'_updated' => [
			'name' => '_updated',
			'type' => 'datetime',
			'nullable' => true,
			'index' => true,
		],
		// When a person last wrote the row; `_updated` moves on every write.
		// Retrofitted onto existing tables by addMissingColumns(), like `_frozen`.
		'_last_user_activity' => [
			'name' => '_last_user_activity',
			'type' => 'datetime',
			'nullable' => true,
			'index' => true,
		],
		'_expires' => [
			'name' => '_expires',
			'type' => 'datetime',
			'nullable' => true,
			'index' => true,
		],
		// JSON columns for complex data.
		'_files' => [
			'name' => '_files',
			'type' => 'json',
			'nullable' => true,
		],
		'_relations' => [
			'name' => '_relations',
			'type' => 'json',
			'nullable' => true,
		],
		'_locked' => [
			'name' => '_locked',
			'type' => 'json',
			'nullable' => true,
		],
		'_authorization' => [
			'name' => '_authorization',
			'type' => 'json',
			'nullable' => true,
		],
		'_validation' => [
			'name' => '_validation',
			'type' => 'json',
			'nullable' => true,
		],
		// Platform-owned quality assessment. Lives here rather than in the
		// object body so a schema does not have to declare `qualityScore`
		// as an ordinary property just to be scored — which put a number
		// field the platform overwrites on every form the schema drives.
		'_quality' => [
			'name' => '_quality',
			'type' => 'json',
			'nullable' => true,
		],
		'_deleted' => [
			'name' => '_deleted',
			'type' => 'json',
			'nullable' => true,
		],
		// The archive marker. A column rather than a key inside `_retention`
		// because the default list query has to exclude archived rows in
		// SQL, and a JSON member cannot carry the `IS NULL` the exclusion
		// is built on. No migration accompanies it: syncTableForRegisterSchema()
		// retrofits a missing metadata column onto every existing magic
		// table through addMissingColumns(), which is how `_retention` and
		// `_tmlo` arrived.
		'_archived' => [
			'name' => '_archived',
			'type' => 'json',
			'nullable' => true,
		],
		// The freeze marker. Separate from `_archived` because the two
		// states differ in exactly the way that matters to a query: a
		// frozen object stays in the working list, an archived one leaves
		// it. Collapsing them would force every caller to pick a wrong half.
		'_frozen' => [
			'name' => '_frozen',
			'type' => 'json',
			'nullable' => true,
		],
		// The explicit lifecycle status (decision 180): `draft`, `active`, or
		// NULL for every object written before it existed, whose status is
		// deduced from its dates as before. A column, not a JSON member,
		// because the list query hides other people's drafts in SQL. Added
		// to existing tables by addMissingColumns(), like `_frozen`.
		'_status' => [
			'name' => '_status',
			'type' => 'string',
			'length' => 16,
			'nullable' => true,
			'index' => true,
		],
		'_geo' => [
			'name' => '_geo',
			'type' => 'json',
			'nullable' => true,
		],
		'_retention' => [
			'name' => '_retention',
			'type' => 'json',
			'nullable' => true,
		],
		'_groups' => [
			'name' => '_groups',
			'type' => 'json',
			'nullable' => true,
		],
		'_tmlo' => [
			'name' => '_tmlo',
			'type' => 'json',
			'nullable' => true,
		],
	];
}//end class
