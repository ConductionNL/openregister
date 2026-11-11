<?php

/**
 * OpenRegister EndpointLogFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\EndpointLog
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\EndpointLog;

/**
 * The column types of an endpoint log, in registration order.
 *
 * Without strict_types, like the entity it belongs to.
 *
 * @spec exclude endpoint log plumbing moved out of the entity, not a capability of its own
 */
final class EndpointLogFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'statusCode' => 'integer',
		'statusMessage' => 'string',
		'request' => 'json',
		'response' => 'json',
		'endpointId' => 'integer',
		'userId' => 'string',
		'sessionId' => 'string',
		'expires' => 'datetime',
		'created' => 'datetime',
		'size' => 'integer',
	];
}//end class
