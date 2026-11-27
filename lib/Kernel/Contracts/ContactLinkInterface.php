<?php
/**
 * ContactLinkInterface: what listeners read of a person's link on an object.
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
 * A link between an object and a contact (a user or a party), with its role.
 *
 * Implemented by \OCA\OpenRegister\Db\ContactLink. The person link events carry
 * this read interface instead of the Integrations entity (events name Kernel
 * types only). The column accessors are served by Nextcloud's Entity::__call,
 * so they are listed as tags here.
 *
 * @method int|null getId()
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
 */
interface ContactLinkInterface extends JsonSerializable {
}//end interface
