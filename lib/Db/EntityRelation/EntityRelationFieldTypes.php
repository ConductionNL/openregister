<?php

/**
 * OpenRegister EntityRelationFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\EntityRelation
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

namespace OCA\OpenRegister\Db\EntityRelation;

/**
 * The column types of a entity relation, in registration order.
 *
 * @spec exclude entity relation plumbing moved out of the entity, not a capability of its own
 */
final class EntityRelationFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'entityId' => 'integer',
		'chunkId' => 'integer',
		'role' => 'string',
		'fileId' => 'integer',
		'objectId' => 'integer',
		'emailId' => 'integer',
		'registerId' => 'string',
		'schemaId' => 'string',
		'objectUuid' => 'string',
		'positionStart' => 'integer',
		'positionEnd' => 'integer',
		'confidence' => 'float',
		'detectionMethod' => 'string',
		'context' => 'string',
		'anonymized' => 'boolean',
		'anonymizedValue' => 'string',
		'bases' => 'json',
		'skipAnonymization' => 'boolean',
		'decisionUuid' => 'string',
		'page' => 'integer',
		'box' => 'json',
		'createdAt' => 'datetime',
	];
}//end class
