<?php
/**
 * FederatedShareInterface: what the share checks read of a federated share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use DateTime;
use JsonSerializable;

/**
 * A grant of local data to an organisation on another instance, as the access checks read it.
 *
 * The share checks (who may grant it, what it reaches, whether it still lives)
 * are Core's work; the share itself is stored and served by federation
 * (Integrations). Implemented by \OCA\OpenRegister\Db\FederatedShare. The
 * column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int|null getId()
 * @method string|null getDirection()
 * @method string|null getScope()
 * @method string|null getRegister()
 * @method string|null getSchema()
 * @method string|null getObjectUri()
 * @method string|null getPermissions()
 * @method string|null getOwner()
 * @method string|null getStatus()
 * @method DateTime|null getExpiresAt()
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
interface FederatedShareInterface extends JsonSerializable {
}//end interface
