<?php

/**
 * OpenRegister FlowRunFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\FlowRun
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

namespace OCA\OpenRegister\Db\FlowRun;

/**
 * The column types of a flow run, in registration order.
 *
 * @spec exclude a flow run plumbing moved out of the entity, not a capability of its own
 */
final class FlowRunFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'flowId' => 'string',
		'flowVersion' => 'integer',
		'status' => 'string',
		'marking' => 'json',
		'placeItems' => 'json',
		'firings' => 'integer',
		'items' => 'json',
		'context' => 'json',
		'log' => 'json',
		'subjectUuid' => 'string',
		'subjects' => 'json',
		'subjectRegister' => 'string',
		'subjectSchema' => 'string',
		'triggeredBy' => 'string',
		'runAs' => 'string',
		'trigger' => 'string',
		'organisation' => 'string',
		'error' => 'string',
		'resumeAt' => 'datetime',
		'parentRunUuid' => 'string',
		'correlationKey' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
