<?php

/**
 * OpenRegister CaseItemFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\CaseItem
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

namespace OCA\OpenRegister\Db\CaseItem;

/**
 * The column types of a case item, in registration order.
 *
 * @spec exclude case item plumbing moved out of the entity, not a capability of its own
 */
final class CaseItemFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'itemKey' => 'string',
		'name' => 'string',
		'description' => 'string',
		'objectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'flowUuid' => 'string',
		'flowVersion' => 'integer',
		'definitionItemKey' => 'string',
		'origin' => 'string',
		'parentItemId' => 'integer',
		'planItemType' => 'string',
		'position' => 'integer',
		'state' => 'string',
		'isTerminal' => 'boolean',
		'enteredAt' => 'datetime',
		'terminatedReason' => 'string',
		'entryCriteria' => 'json',
		'exitCriteria' => 'json',
		'required' => 'boolean',
		'discretionary' => 'boolean',
		'repetition' => 'json',
		'realisationKind' => 'string',
		'realisationUuid' => 'string',
		'realisationCount' => 'integer',
		'authorizationRules' => 'json',
		'candidateUsers' => 'json',
		'candidateGroups' => 'json',
		'candidateRole' => 'string',
		'dueAt' => 'datetime',
		'expiresAt' => 'datetime',
		'doorlooptijd' => 'string',
		'servicenorm' => 'string',
		'planSettings' => 'json',
		'created' => 'datetime',
		'updated' => 'datetime',
		'createdBy' => 'string',
	];
}//end class
