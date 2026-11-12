<?php

/**
 * OpenRegister TaskFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Task
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

namespace OCA\OpenRegister\Db\Task;

/**
 * The column types of a task, in registration order.
 *
 * @spec exclude a task plumbing moved out of the entity, not a capability of its own
 */
final class TaskFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'taskKey' => 'string',
		'title' => 'string',
		'description' => 'string',
		'metadata' => 'json',
		'kind' => 'string',
		'runUuid' => 'string',
		'nodeId' => 'string',
		'definitionVersion' => 'integer',
		'appId' => 'string',
		'workflowStepId' => 'string',
		'organisation' => 'string',
		'state' => 'string',
		'isTerminal' => 'boolean',
		'lastAction' => 'string',
		'outcome' => 'string',
		'blockedReason' => 'string',
		'blockedBy' => 'string',
		'performerType' => 'string',
		'assignee' => 'string',
		'candidateUsers' => 'json',
		'candidateGroups' => 'json',
		'candidateRole' => 'string',
		'routingStrategy' => 'string',
		'routingFallback' => 'string',
		'onBehalfOf' => 'string',
		'mandate' => 'string',
		'requester' => 'string',
		'watchers' => 'json',
		'startAt' => 'datetime',
		'dueAt' => 'datetime',
		'expiresAt' => 'datetime',
		'onTimeout' => 'string',
		'onReject' => 'string',
		'slaValue' => 'integer',
		'slaUnit' => 'string',
		'compliancePeriodDays' => 'integer',
		'suspendedUntil' => 'datetime',
		'recurrence' => 'string',
		'priority' => 'string',
		'objectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'templateId' => 'string',
		'templateVersion' => 'integer',
		'templateSnapshot' => 'json',
		'sequenceUuid' => 'string',
		'sequencePosition' => 'integer',
		'legacyStepId' => 'integer',
		'checklist' => 'json',
		'responses' => 'json',
		'percentComplete' => 'integer',
		'completedAt' => 'datetime',
		'completedBy' => 'string',
		'resultText' => 'string',
		'comment' => 'string',
		'evidence' => 'json',
		'overrideReason' => 'string',
		'parentTaskId' => 'integer',
		'epicTaskId' => 'integer',
		'created' => 'datetime',
		'updated' => 'datetime',
		'createdBy' => 'string',
	];
}//end class
