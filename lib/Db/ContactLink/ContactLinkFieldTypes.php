<?php

/**
 * OpenRegister ContactLinkFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ContactLink
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

namespace OCA\OpenRegister\Db\ContactLink;

/**
 * The column types of a contact link, in registration order.
 *
 * @spec exclude contact link plumbing moved out of the entity, not a capability of its own
 */
final class ContactLinkFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'objectUuid' => 'string',
		'registerId' => 'integer',
		'schemaId' => 'integer',
		'contactUid' => 'string',
		'addressbookId' => 'integer',
		'contactUri' => 'string',
		'displayName' => 'string',
		'email' => 'string',
		'phone' => 'string',
		'org' => 'string',
		'avatarUrl' => 'string',
		'role' => 'string',
		'metadata' => 'string',
		'linkedBy' => 'string',
		'linkedAt' => 'datetime',
		'userId' => 'string',
		'validFrom' => 'datetime',
		'validUntil' => 'datetime',
		'note' => 'string',
		'partyUuid' => 'string',
		'partyKind' => 'string',
		'primaryParty' => 'boolean',
	];
}//end class
