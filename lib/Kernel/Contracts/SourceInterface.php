<?php
/**
 * SourceInterface: what listeners read of a source row.
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
 * A source: an external system objects are read from or synchronised with.
 *
 * Implemented by \OCA\OpenRegister\Db\Source. The source events carry this read
 * interface instead of the Integrations entity (events name Kernel types only).
 * The column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int|null getId()
 * @method string|null getUuid()
 * @method string|null getTitle()
 * @method string|null getDescription()
 * @method string|null getVersion()
 * @method string|null getType()
 * @method string|null getOrganisation()
 * @method array|null getAuthConfig()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 */
interface SourceInterface extends JsonSerializable {
}//end interface
