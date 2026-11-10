<?php
/**
 * ConversationInterface: what callers outside Integrations read of a Conversation row.
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
 * A chat conversation between a user and an agent.
 *
 * Implemented by \OCA\OpenRegister\Mcp\Db\Conversation. Events and listeners
 * name this read interface instead of the Integrations entity (ADR-012 Rule 2).
 * The column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getTitle()
 * @method string|null getUserId()
 * @method string|null getOwner()
 * @method string|null getOrganisation()
 * @method int|null getAgentId()
 * @method array|null getMetadata()
 * @method DateTime|null getDeletedAt()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 */
interface ConversationInterface extends JsonSerializable {
}//end interface
