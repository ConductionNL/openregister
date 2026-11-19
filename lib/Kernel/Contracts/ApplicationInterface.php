<?php
/**
 * ApplicationInterface: what listeners read of an application row.
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
 * An application: a named bundle of configurations, registers and schemas with its quota.
 *
 * Implemented by \OCA\OpenRegister\Db\Application. The application events carry this read
 * interface instead of the Configuration entity (events name Kernel types only).
 * The column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int|null getId()
 * @method string|null getUuid()
 * @method string|null getName()
 * @method string|null getDescription()
 * @method string|null getVersion()
 * @method string|null getOrganisation()
 * @method string|null getOwner()
 * @method bool|null getActive()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 */
interface ApplicationInterface extends JsonSerializable {
}//end interface
