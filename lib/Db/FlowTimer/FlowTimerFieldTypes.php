<?php

/**
 * OpenRegister FlowTimerFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\FlowTimer
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

namespace OCA\OpenRegister\Db\FlowTimer;

/**
 * The column types of a flow timer, in registration order.
 *
 * @spec exclude flow timer plumbing moved out of the entity, not a capability of its own
 */
final class FlowTimerFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'title' => 'string',
		'metadata' => 'json',
		'subjectType' => 'string',
		'subjectUuid' => 'string',
		'organisation' => 'string',
		'runUuid' => 'string',
		'nodeId' => 'string',
		'appId' => 'string',
		'purpose' => 'string',
		'legalEffect' => 'string',
		'onExpiry' => 'string',
		'anchorEvent' => 'string',
		'anchorOffset' => 'integer',
		'anchorOffsetUnit' => 'string',
		'anchorAt' => 'datetime',
		'budgetValue' => 'float',
		'budgetUnit' => 'string',
		'rollToWorkingDay' => 'string',
		'unrolledAt' => 'datetime',
		'rolledBy' => 'string',
		'consumedValue' => 'float',
		'runningSince' => 'datetime',
		'fireAt' => 'datetime',
		'nextRungAt' => 'datetime',
		'calendarSlug' => 'string',
		'ladderSlug' => 'string',
		'escalationRules' => 'json',
		'suspendedSince' => 'datetime',
		'suspendReason' => 'string',
		'suspendedTotalSeconds' => 'integer',
		'extensionCount' => 'integer',
		'extensionMax' => 'integer',
		'state' => 'string',
		'supersedesUuid' => 'string',
		'firedAt' => 'datetime',
		'breached' => 'boolean',
		'cancelledAt' => 'datetime',
		'cancelReason' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
		'createdBy' => 'string',
	];
}//end class
