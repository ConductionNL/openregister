<?php

/**
 * OpenRegister ProcessingLogEntryFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ProcessingLogEntry
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

namespace OCA\OpenRegister\Db\ProcessingLogEntry;

/**
 * The column types of a processing log entry, in registration order.
 *
 * @spec exclude processing log entry plumbing moved out of the entity, not a capability of its own
 */
final class ProcessingLogEntryFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'activityId' => 'string',
		'action' => 'string',
		'actor' => 'string',
		'channel' => 'string',
		'registerId' => 'string',
		'schemaId' => 'string',
		'objectUuid' => 'string',
		'subjectIdType' => 'string',
		'subjectIdValue' => 'string',
		'objectCount' => 'integer',
		'objectUuids' => 'json',
		'subjectIds' => 'json',
		'queryParams' => 'json',
		'confidential' => 'boolean',
		'organisationId' => 'string',
		'created' => 'datetime',
	];
}//end class
