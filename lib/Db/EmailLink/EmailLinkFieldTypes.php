<?php

/**
 * OpenRegister EmailLinkFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\EmailLink
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

namespace OCA\OpenRegister\Db\EmailLink;

/**
 * The column types of a email link, in registration order.
 *
 * @spec exclude email link plumbing moved out of the entity, not a capability of its own
 */
final class EmailLinkFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'objectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'mailAccountId' => 'integer',
		'mailMessageId' => 'integer',
		'mailMessageUid' => 'string',
		'subject' => 'string',
		'sender' => 'string',
		'senderName' => 'string',
		'mailDate' => 'datetime',
		'metadata' => 'string',
		'linkedBy' => 'string',
		'linkedAt' => 'datetime',
		'rfcMessageId' => 'string',
	];
}//end class
