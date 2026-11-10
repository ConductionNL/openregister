<?php

/**
 * OpenRegister ObjectRelationFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectRelation
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

namespace OCA\OpenRegister\Db\ObjectRelation;

/**
 * The column types of a object relation, in registration order.
 *
 * @spec exclude object relation plumbing moved out of the entity, not a capability of its own
 */
final class ObjectRelationFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'sourceUuid' => 'string',
		'sourceRegister' => 'integer',
		'sourceSchema' => 'integer',
		'targetUuid' => 'string',
		'targetRegister' => 'integer',
		'targetSchema' => 'integer',
		'targetUrl' => 'string',
		'targetTitle' => 'string',
		'kind' => 'string',
		'relationType' => 'string',
		'label' => 'string',
		'inverseLabel' => 'string',
		'symmetric' => 'boolean',
		'origin' => 'string',
		'sourceEntry' => 'string',
		'inherited' => 'json',
		'anchor' => 'string',
		'createdBy' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
