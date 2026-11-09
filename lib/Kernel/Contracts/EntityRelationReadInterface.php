<?php

/**
 * EntityRelationReadInterface: what callers outside lib/Db read of a EntityRelation row.
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
 * An entity relation: a detected entity linked to a chunk, with its anonymisation decision.
 *
 * Implemented by \OCA\OpenRegister\Db\EntityRelation. The column getters are served by
 * Nextcloud's Entity::__call, so they are listed as tags here. Read only: the
 * setters stay on the entity.
 *
 * @method int getId()
 * @method int getEntityId()
 * @method int getChunkId()
 * @method string|null getRole()
 * @method int|null getFileId()
 * @method int|null getObjectId()
 * @method int|null getEmailId()
 * @method string|null getRegisterId()
 * @method string|null getSchemaId()
 * @method string|null getObjectUuid()
 * @method int getPositionStart()
 * @method int getPositionEnd()
 * @method float getConfidence()
 * @method string getDetectionMethod()
 * @method string|null getContext()
 * @method bool getAnonymized()
 * @method string|null getAnonymizedValue()
 * @method array|null getBases()
 * @method bool getSkipAnonymization()
 * @method string|null getDecisionUuid()
 * @method DateTime getCreatedAt()
 */
interface EntityRelationReadInterface extends JsonSerializable {
}//end interface
