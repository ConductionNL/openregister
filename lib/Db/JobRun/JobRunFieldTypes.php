<?php

/**
 * OpenRegister JobRunFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\JobRun
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

namespace OCA\OpenRegister\Db\JobRun;

/**
 * The column types of a job run, in registration order.
 *
 * @spec exclude job run plumbing moved out of the entity, not a capability of its own
 */
final class JobRunFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'jobClass' => 'string',
		'argumentDigest' => 'string',
		'started' => 'datetime',
		'ended' => 'datetime',
		'durationMs' => 'integer',
		'outcome' => 'string',
		'message' => 'string',
		'details' => 'string',
		'cause' => 'string',
		'actor' => 'string',
	];
}//end class
