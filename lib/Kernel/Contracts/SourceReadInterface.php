<?php

/**
 * SourceReadInterface: what callers outside lib/Db read of a Source row.
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
 * A source: an external system OpenRegister reads objects from or syncs with.
 *
 * Implemented by \OCA\OpenRegister\Db\Source. The column getters are served by
 * Nextcloud's Entity::__call, so they are listed as tags here. Read only: the
 * setters stay on the entity.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getTitle()
 * @method string|null getVersion()
 * @method string|null getDescription()
 * @method string|null getDatabaseUrl()
 * @method string|null getType()
 * @method string|null getOrganisation()
 * @method DateTime|null getUpdated()
 * @method DateTime|null getCreated()
 * @method bool|null getSyncEnabled()
 * @method string|null getSyncSchedule()
 * @method int|null getSyncInterval()
 * @method DateTime|null getLastSyncDate()
 * @method string|null getLastSyncStatus()
 * @method string|null getLastSyncToken()
 * @method string|null getAuthType()
 * @method array|null getAuthConfig()
 * @method int|null getMappingId()
 * @method string|null getTargetRegister()
 * @method string|null getTargetSchema()
 * @method string|null getConflictStrategy()
 * @method string|null getDeleteStrategy()
 * @method int|null getBatchSize()
 */
interface SourceReadInterface extends JsonSerializable {
}//end interface
