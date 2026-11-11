<?php

/**
 * OpenRegister ProcessingPurposeFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ProcessingPurpose
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

namespace OCA\OpenRegister\Db\ProcessingPurpose;

/**
 * The column types of a processing purpose, in registration order.
 *
 * @spec exclude processing purpose plumbing moved out of the entity, not a capability of its own
 */
final class ProcessingPurposeFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'code' => 'string',
		'name' => 'string',
		'description' => 'string',
		'activity' => 'string',
		'activityUuid' => 'string',
		'organisationId' => 'string',
		'status' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
