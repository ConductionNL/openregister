<?php

/**
 * OpenRegister FederatedShareFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\FederatedShare
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

namespace OCA\OpenRegister\Db\FederatedShare;

/**
 * The column types of a federated share, in registration order.
 *
 * @spec exclude a federated share plumbing moved out of the entity, not a capability of its own
 */
final class FederatedShareFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'direction' => 'string',
		'remoteInstanceUrl' => 'string',
		'remoteProviderId' => 'string',
		'shareToken' => 'string',
		'scope' => 'string',
		'register' => 'string',
		'schema' => 'string',
		'objectUri' => 'string',
		'queryFilter' => 'json',
		'permissions' => 'string',
		'organisation' => 'string',
		'sharedWith' => 'string',
		'status' => 'string',
		'owner' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
	];
}//end class
