<?php

/**
 * OpenRegister SearchTrailFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\SearchTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\SearchTrail;

/**
 * The column types of a search trail, in registration order.
 *
 * @spec exclude a search trail plumbing moved out of the entity, not a capability of its own
 */
final class SearchTrailFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'searchTerm' => 'string',
		'queryParameters' => 'json',
		'resultCount' => 'integer',
		'totalResults' => 'integer',
		'register' => 'integer',
		'schema' => 'integer',
		'registerUuid' => 'string',
		'schemaUuid' => 'string',
		'user' => 'string',
		'userName' => 'string',
		'registerName' => 'string',
		'schemaName' => 'string',
		'session' => 'string',
		'ipAddress' => 'string',
		'userAgent' => 'string',
		'requestUri' => 'string',
		'httpMethod' => 'string',
		'responseTime' => 'integer',
		'page' => 'integer',
		'limit' => 'integer',
		'offset' => 'integer',
		'facetsRequested' => 'boolean',
		'facetableRequested' => 'boolean',
		'filters' => 'json',
		'sortParameters' => 'json',
		'publishedOnly' => 'boolean',
		'executionType' => 'string',
		'created' => 'datetime',
		'organisationId' => 'string',
		'organisationIdType' => 'string',
		'expires' => 'datetime',
		'size' => 'integer',
	];
}//end class
