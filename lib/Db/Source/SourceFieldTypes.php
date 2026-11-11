<?php

/**
 * OpenRegister SourceFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Source
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

namespace OCA\OpenRegister\Db\Source;

/**
 * The column types of a source, in registration order.
 *
 * @spec exclude source plumbing moved out of the entity, not a capability of its own
 */
final class SourceFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'title' => 'string',
		'version' => 'string',
		'description' => 'string',
		'databaseUrl' => 'string',
		'type' => 'string',
		'organisation' => 'string',
		'syncEnabled' => 'boolean',
		'syncSchedule' => 'string',
		'syncInterval' => 'integer',
		'lastSyncDate' => 'datetime',
		'lastSyncStatus' => 'string',
		'lastSyncToken' => 'string',
		'authType' => 'string',
		'authConfig' => 'json',
		'mappingId' => 'integer',
		'targetRegister' => 'string',
		'targetSchema' => 'string',
		'conflictStrategy' => 'string',
		'deleteStrategy' => 'string',
		'batchSize' => 'integer',
		'updated' => 'datetime',
		'created' => 'datetime',
	];
}//end class
