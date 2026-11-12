<?php

/**
 * OpenRegister AccessLinkFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AccessLink
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

namespace OCA\OpenRegister\Db\AccessLink;

/**
 * The column types of a access link, in registration order.
 *
 * @spec exclude access link plumbing moved out of the entity, not a capability of its own
 */
final class AccessLinkFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'anchor' => 'string',
		'subjectType' => 'string',
		'subjectId' => 'string',
		'capabilities' => 'string',
		'label' => 'string',
		'passwordHash' => 'string',
		'createdBy' => 'string',
		'created' => 'datetime',
		'expiresAt' => 'datetime',
		'revokedAt' => 'datetime',
		'disabled' => 'boolean',
		'lastUsedAt' => 'datetime',
		'useCount' => 'integer',
	];
}//end class
