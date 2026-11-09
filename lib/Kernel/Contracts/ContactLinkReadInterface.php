<?php

/**
 * ContactLinkReadInterface: what callers outside lib/Db read of a ContactLink row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A contact link: a user, contact or party holding a role on an object for a period.
 *
 * Implemented by \OCA\OpenRegister\Db\ContactLink. The column getters are served by
 * Nextcloud's Entity::__call, so they are listed as tags here. Read only: the
 * setters stay on the entity.
 *
 * @method int getId()
 * @method string getObjectUuid()
 * @method int getRegisterId()
 * @method int|null getSchemaId()
 * @method string getContactUid()
 * @method int getAddressbookId()
 * @method string getContactUri()
 * @method string|null getDisplayName()
 * @method string|null getEmail()
 * @method string|null getPhone()
 * @method string|null getOrg()
 * @method string|null getAvatarUrl()
 * @method string|null getRole()
 * @method string|null getMetadata()
 * @method string getLinkedBy()
 * @method DateTime getLinkedAt()
 * @method string|null getUserId()
 * @method DateTime|null getValidFrom()
 * @method DateTime|null getValidUntil()
 * @method string|null getNote()
 * @method string|null getPartyUuid()
 * @method string|null getPartyKind()
 * @method bool|null getPrimaryParty()
 */
interface ContactLinkReadInterface extends JsonSerializable {

	/**
	 * Whether the link names a Nextcloud user rather than a contact.
	 *
	 * @return bool True for a user link.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function isUserLink(): bool;

	/**
	 * Whether the link names a party record rather than an account or a vCard.
	 *
	 * @return bool True for a party link.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function isPartyLink(): bool;

	/**
	 * Whether today lies inside the validity window; an unset bound is open.
	 *
	 * @param DateTime|null $today The day to test, today when null.
	 *
	 * @return bool True when the person holds the role on that day.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function isActiveOn(?DateTime $today = null): bool;
}//end interface
