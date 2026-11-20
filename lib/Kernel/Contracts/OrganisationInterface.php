<?php
/**
 * OrganisationInterface: what listeners read of an organisation row.
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
 * An organisation: the tenant objects, registers and users belong to.
 *
 * Implemented by \OCA\OpenRegister\Db\Organisation. The organisation events carry
 * this read interface instead of the Core entity (events name Kernel types only).
 * The column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int|null getId()
 * @method string|null getUuid()
 * @method string|null getSlug()
 * @method string|null getName()
 * @method string|null getDescription()
 * @method string|null getOwner()
 * @method string|null getStatus()
 * @method string|null getType()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 */
interface OrganisationInterface extends JsonSerializable {
}//end interface
