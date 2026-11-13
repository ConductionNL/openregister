<?php

/**
 * OpenRegister WebhookFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Webhook
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

namespace OCA\OpenRegister\Db\Webhook;

/**
 * The column types of a webhook, in registration order.
 *
 * @spec exclude webhook plumbing moved out of the entity, not a capability of its own
 */
final class WebhookFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'name' => 'string',
		'url' => 'string',
		'method' => 'string',
		'events' => 'string',
		'headers' => 'string',
		'secret' => 'string',
		'enabled' => 'boolean',
		'organisation' => 'string',
		'owner' => 'string',
		'filters' => 'string',
		'retryPolicy' => 'string',
		'maxRetries' => 'integer',
		'timeout' => 'integer',
		'lastTriggeredAt' => 'datetime',
		'lastSuccessAt' => 'datetime',
		'lastFailureAt' => 'datetime',
		'totalDeliveries' => 'integer',
		'successfulDeliveries' => 'integer',
		'failedDeliveries' => 'integer',
		'created' => 'datetime',
		'updated' => 'datetime',
		'configuration' => 'string',
		'mapping' => 'integer',
	];
}//end class
