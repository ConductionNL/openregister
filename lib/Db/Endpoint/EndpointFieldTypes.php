<?php

/**
 * OpenRegister EndpointFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Endpoint
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

namespace OCA\OpenRegister\Db\Endpoint;

/**
 * The column types of a endpoint, in registration order.
 *
 * @spec exclude endpoint plumbing moved out of the entity, not a capability of its own
 */
final class EndpointFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'name' => 'string',
		'description' => 'string',
		'reference' => 'string',
		'version' => 'string',
		'endpoint' => 'string',
		'endpointArray' => 'json',
		'endpointRegex' => 'string',
		'method' => 'string',
		'targetType' => 'string',
		'targetId' => 'string',
		'conditions' => 'json',
		'inputMapping' => 'string',
		'outputMapping' => 'string',
		'rules' => 'json',
		'configurations' => 'json',
		'slug' => 'string',
		'groups' => 'json',
		'organisation' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
