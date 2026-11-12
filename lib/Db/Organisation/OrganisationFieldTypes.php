<?php

/**
 * OpenRegister OrganisationFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Organisation;

/**
 * The column types of an organisation, in registration order.
 *
 * @spec exclude an organisation plumbing moved out of the entity, not a capability of its own
 */
final class OrganisationFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'slug' => 'string',
		'name' => 'string',
		'description' => 'string',
		'users' => 'json',
		'groups' => 'json',
		'owner' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
		'active' => 'boolean',
		'storageQuota' => 'integer',
		'bandwidthQuota' => 'integer',
		'requestQuota' => 'integer',
		'userQuota' => 'integer',
		'objectQuota' => 'integer',
		'objectQuotaSchema' => 'string',
		'authorization' => 'json',
		'parent' => 'string',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
		'status' => 'string',
		'environment' => 'string',
		'provisionedAt' => 'datetime',
		'suspendedAt' => 'datetime',
		'deprovisionedAt' => 'datetime',
		'retainedAt' => 'datetime',
		// Identity facet (ADR-022 §3): the statutory identifiers a leaf app
		// used to keep in its own publisher/vendor record.
		'type' => 'string',
		'isLocalTenant' => 'boolean',
		'remoteInstanceUrl' => 'string',
		'contactEmail' => 'string',
		'defaultPermissionLevel' => 'string',
		'qualityScore' => 'integer',
		'qualityStatus' => 'string',
		'summary' => 'string',
		'oin' => 'string',
		'tooi' => 'string',
		'rsin' => 'string',
		'kvk' => 'string',
		'legalName' => 'string',
		'pki' => 'string',
		'image' => 'string',
		// Relationship facet.
		'registrationStatus' => 'string',
		'mergedInto' => 'string',
		'mergedAt' => 'datetime',
	];
}//end class
