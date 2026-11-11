<?php

/**
 * OpenRegister ExportProfileFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ExportProfile
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

namespace OCA\OpenRegister\Db\ExportProfile;

/**
 * The column types of an export profile, in registration order.
 *
 * @spec exclude export profile plumbing moved out of the entity, not a capability of its own
 */
final class ExportProfileFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'owner' => 'string',
		'name' => 'string',
		'description' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'fields' => 'string',
		'valueMode' => 'string',
		'format' => 'string',
		'filters' => 'string',
		'wholeSet' => 'boolean',
		'retentionDays' => 'integer',
		'createdAt' => 'datetime',
		'updatedAt' => 'datetime',
	];
}//end class
