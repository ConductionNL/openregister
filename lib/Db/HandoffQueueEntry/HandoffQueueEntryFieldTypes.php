<?php

/**
 * OpenRegister HandoffQueueEntryFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\HandoffQueueEntry
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

namespace OCA\OpenRegister\Db\HandoffQueueEntry;

/**
 * The column types of a handoff queue entry, in registration order.
 *
 * @spec exclude handoff queue entry plumbing moved out of the entity, not a capability of its own
 */
final class HandoffQueueEntryFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'sourceObjectUuid' => 'string',
		'sourceRegister' => 'integer',
		'sourceSchema' => 'integer',
		'handoffId' => 'string',
		'targetKind' => 'string',
		'requestingUser' => 'string',
		'correlationId' => 'string',
		'mappingHash' => 'string',
		'status' => 'string',
		'attempt' => 'integer',
		'lastError' => 'string',
		'created' => 'datetime',
		'lastAttemptAt' => 'datetime',
		'executedAt' => 'datetime',
	];
}//end class
