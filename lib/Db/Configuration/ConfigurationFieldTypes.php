<?php

/**
 * OpenRegister ConfigurationFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Configuration
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

namespace OCA\OpenRegister\Db\Configuration;

/**
 * The column types of a configuration, in registration order.
 *
 * @spec exclude a configuration plumbing moved out of the entity, not a capability of its own
 */
final class ConfigurationFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'title' => 'string',
		'description' => 'string',
		'type' => 'string',
		'app' => 'string',
		'version' => 'string',
		'sourceType' => 'string',
		'sourceUrl' => 'string',
		'localVersion' => 'string',
		'remoteVersion' => 'string',
		'lastChecked' => 'datetime',
		'autoUpdate' => 'boolean',
		'notificationGroups' => 'json',
		'githubRepo' => 'string',
		'githubBranch' => 'string',
		'githubPath' => 'string',
		'isLocal' => 'boolean',
		'syncEnabled' => 'boolean',
		'syncInterval' => 'integer',
		'lastSyncDate' => 'datetime',
		'syncStatus' => 'string',
		'openregister' => 'string',
		'registers' => 'json',
		'schemas' => 'json',
		'objects' => 'json',
		'views' => 'json',
		'agents' => 'json',
		'sources' => 'json',
		'mappings' => 'json',
		'applications' => 'json',
		'organisation' => 'string',
		'owner' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
