<?php

/**
 * OpenRegister TaskSequenceFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\TaskSequence
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

namespace OCA\OpenRegister\Db\TaskSequence;

/**
 * The column types of a task sequence, in registration order.
 *
 * @spec exclude task sequence plumbing moved out of the entity, not a capability of its own
 */
final class TaskSequenceFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'templateId' => 'string',
		'templateVersion' => 'integer',
		'templateSnapshot' => 'json',
		'anchorObjectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'chainKey' => 'string',
		'requesterId' => 'string',
		'resolvedTier' => 'json',
		'positionCursor' => 'integer',
		'status' => 'string',
		'outcome' => 'string',
		'runUuid' => 'string',
		'nodeId' => 'string',
		'openedAt' => 'datetime',
		'closedAt' => 'datetime',
	];
}//end class
