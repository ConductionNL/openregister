<?php
/**
 * EntityRelationInterface: what listeners read of an entity relation row.
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
 * An entity relation: one detected entity (a person, an address ...) found in a
 * file, an object or an e-mail, with the decision recorded about it.
 *
 * Implemented by \OCA\OpenRegister\Db\EntityRelation. The relation decision event
 * carries this read interface instead of the Core entity (events name Kernel types
 * only). The column accessors are served by Nextcloud's Entity::__call, so they
 * are listed as tags here.
 *
 * @method int|null getId()
 * @method int getEntityId()
 * @method string|null getRole()
 * @method int|null getFileId()
 * @method int|null getObjectId()
 * @method int|null getEmailId()
 * @method string|null getObjectUuid()
 * @method array|null getBases()
 * @method bool getSkipAnonymization()
 * @method string|null getDecisionUuid()
 * @method DateTime getCreatedAt()
 */
interface EntityRelationInterface extends JsonSerializable {
}//end interface
