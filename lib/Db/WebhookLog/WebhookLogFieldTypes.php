<?php

/**
 * OpenRegister WebhookLogFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\WebhookLog
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

namespace OCA\OpenRegister\Db\WebhookLog;

/**
 * The column types of a webhook log, in registration order.
 *
 * @spec exclude webhook log plumbing moved out of the entity, not a capability of its own
 */
final class WebhookLogFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'webhook' => 'integer',
		'eventClass' => 'string',
		'payload' => 'string',
		'url' => 'string',
		'method' => 'string',
		'success' => 'boolean',
		'statusCode' => 'integer',
		'requestBody' => 'string',
		'responseBody' => 'string',
		'errorMessage' => 'string',
		'attempt' => 'integer',
		'nextRetryAt' => 'datetime',
		'created' => 'datetime',
	];
}//end class
