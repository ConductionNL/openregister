<?php

/**
 * OpenRegister FlowFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Flow
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

namespace OCA\OpenRegister\Db\Flow;

/**
 * The column types of a flow, in registration order.
 *
 * @spec exclude flow plumbing moved out of the entity, not a capability of its own
 */
final class FlowFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'name' => 'string',
		'description' => 'string',
		'app' => 'string',
		'applicationSlug' => 'string',
		'enabled' => 'boolean',
		'version' => 'integer',
		'semver' => 'string',
		'semverSource' => 'string',
		'lifecycleStatus' => 'string',
		'trigger' => 'string',
		'triggerRegister' => 'string',
		'triggerSchema' => 'string',
		'cron' => 'string',
		'executionMode' => 'string',
		'nodes' => 'json',
		'edges' => 'json',
		'annotations' => 'json',
		'limits' => 'json',
		'retentionDays' => 'integer',
		'auditEnabled' => 'boolean',
		'owner' => 'string',
		'organisation' => 'string',
		'notes' => 'string',
		'comment' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
		'status' => 'string',
		'statusMessage' => 'string',
		'lastRunUuid' => 'string',
		'lastRunStatus' => 'string',
		'lastRunMessage' => 'string',
		'lastRunAt' => 'datetime',
	];
}//end class
